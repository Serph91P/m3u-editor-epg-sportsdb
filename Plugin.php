<?php

namespace AppLocalPlugins\EpgSportsdb;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class Plugin implements EpgProcessorPluginInterface, HookablePluginInterface
{
    /**
     * Map of country name → list of TheSportsDB league API names to query.
     * Used on the free tier via eventsday.php?l=LEAGUE to get country-specific events.
     * League names match the API parameter format (underscores, as seen in thesportsdb.com URLs).
     *
     * @var array<string, list<string>>
     */
    private const array LEAGUES_BY_COUNTRY = [
        // Europe — Football
        'Germany' => [
            'German_Bundesliga',
            'German_2_Bundesliga',
            'German_DFB_Pokal',
            'German_Bundesliga_Handball',
            'German_DEL',
            'German_Basketball_Bundesliga',
        ],
        'Austria' => [
            'Austrian_Football_Bundesliga',
        ],
        'Switzerland' => [
            'Swiss_Super_League',
        ],
        'Scotland' => [
            'Scottish_Premiership',
            'Scottish_FA_Cup',
        ],
        'England' => [
            'English_Premier_League',
            'English_League_Championship',
            'English_FA_Cup',
            'English_League_Cup',
        ],
        'Spain' => [
            'Spanish_La_Liga',
            'Spanish_Segunda_Division',
            'Spanish_Copa_del_Rey',
        ],
        'Italy' => [
            'Serie_A',
            'Serie_B',
            'Coppa_Italia',
        ],
        'France' => [
            'French_Ligue_1',
            'French_Ligue_2',
            'French_Coupe_de_France',
        ],
        'Netherlands' => [
            'Dutch_Eredivisie',
        ],
        'Portugal' => [
            'Portuguese_Primeira_Liga',
        ],
        'Belgium' => [
            'Belgian_Pro_League',
        ],
        'Turkey' => [
            'Turkish_Super_Lig',
        ],
        'Russia' => [
            'Russian_Premier_League',
        ],
        'Greece' => [
            'Greek_Super_League',
        ],
        // Europe — International
        'International' => [
            'UEFA_Champions_League',
            'UEFA_Europa_League',
            'UEFA_Europa_Conference_League',
            'FIFA_World_Cup',
            'UEFA_Euro',
        ],
        // North America
        'USA' => [
            'NBA',
            'NHL',
            'NFL',
            'MLB',
            'Major_League_Soccer',
        ],
        // South America
        'Brazil' => [
            'Brazilian_Serie_A',
            'Brazilian_Serie_B',
        ],
        'Argentina' => [
            'Argentine_Primera_Division',
        ],
        'Mexico' => [
            'Mexican_Liga_MX',
        ],
        // Asia / Pacific
        'Japan' => [
            'Japanese_J_League',
        ],
        'South Korea' => [
            'Korean_K_League_1',
        ],
        'Australia' => [
            'Australian_A-League',
            'Australian_NBL',
            'Australian_NRL',
            'Australian_AFL',
        ],
    ];

    /**
     * Sport types to query on the free tier via eventsday.php?s=SPORT.
     * Each yields up to 5 events per day. Covers major sports seen in German TV EPGs.
     */
    private const array SPORT_TYPES = [
        'Soccer',
        'Motorsport',
        'Ice Hockey',
        'Basketball',
        'Tennis',
        'Golf',
        'Fighting',
        'American Football',
        'Baseball',
        'Rugby',
        'Cycling',
        'Handball',
    ];

    /**
     * Delay between API requests in microseconds to respect rate limits.
     * Free tier: 30 req/min → one request every 2 seconds.
     */
    private const int REQUEST_DELAY_US = 2_100_000;

    /**
     * Handle manual actions triggered from the plugin UI.
     */
    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'enrich_epg' => $this->enrichEpg($payload, $context),
            'health_check' => $this->healthCheck($context),
            'clear_state' => $this->clearEnrichmentState($context),
            default => PluginActionResult::failure("Unsupported action [{$action}]."),
        };
    }

    /**
     * Handle hooks dispatched by the host application.
     */
    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'epg.cache.generated') {
            return PluginActionResult::success("Hook [{$hook}] ignored - not relevant.");
        }

        $autoRun = $context->settings['auto_run_on_cache'] ?? true;
        if (! $autoRun) {
            return PluginActionResult::success('Auto-run disabled - skipping.');
        }

        $epgId = $payload['epg_id'] ?? null;
        $userId = $payload['user_id'] ?? null;
        $playlistIds = $payload['playlist_ids'] ?? [];

        if (! $epgId || ! $userId) {
            return PluginActionResult::failure('Missing epg_id or user_id in hook payload.');
        }

        $allowedPlaylistIds = $context->settings['auto_run_playlists'] ?? [];
        if (! empty($allowedPlaylistIds)) {
            $playlistIds = array_values(array_intersect($playlistIds, $allowedPlaylistIds));
            if (empty($playlistIds)) {
                return PluginActionResult::success('No matching playlists for auto-run - skipping.');
            }
        }

        $context->heartbeat("EPG cache generated (ID: {$epgId}). Running SportsDB enrichment.");

        return $this->doEnrich($epgId, $playlistIds, $context);
    }

    /**
     * Manual enrich action from UI.
     */
    private function enrichEpg(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        $playlistId = $payload['playlist_id'] ?? null;

        if (! $playlistId) {
            return PluginActionResult::failure('Playlist is required.');
        }

        $playlist = Playlist::find($playlistId);
        if (! $playlist) {
            return PluginActionResult::failure("Playlist [{$playlistId}] not found.");
        }

        $epgIds = Channel::query()
            ->where('playlist_id', $playlist->id)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->whereHas('epgChannel')
            ->join('epg_channels', 'channels.epg_channel_id', '=', 'epg_channels.id')
            ->distinct()
            ->pluck('epg_channels.epg_id')
            ->all();

        if (empty($epgIds)) {
            return PluginActionResult::success("No active channels with EPG mappings in '{$playlist->name}' - nothing to enrich.");
        }

        $playlistIds = [$playlist->id];
        $totalChannels = $this->countTargetChannels($epgIds, $playlistIds);
        $context->heartbeat("Starting SportsDB enrichment for playlist '{$playlist->name}' ({$totalChannels} active channels).");

        $combinedStats = [];

        foreach ($epgIds as $epgId) {
            $result = $this->doEnrich($epgId, $playlistIds, $context);

            if (! $result->success) {
                return $result;
            }

            if (empty($result->data)) {
                return $result;
            }

            foreach ($result->data as $key => $value) {
                if (is_int($value)) {
                    $combinedStats[$key] = ($combinedStats[$key] ?? 0) + $value;
                } else {
                    $combinedStats[$key] = $value;
                }
            }
        }

        $summary = "SportsDB enrichment complete for playlist '{$playlist->name}': "
            .($combinedStats['programmes_updated'] ?? 0).'/'
            .($combinedStats['programmes_processed'] ?? 0).' sport programmes updated '
            ."across {$totalChannels} active channels.";

        return PluginActionResult::success($summary, $combinedStats);
    }

    /**
     * Core enrichment: iterate JSONL files, match Sports programmes to SportsDB events.
     *
     * @param  array<int>  $playlistIds
     */
    private function doEnrich(int $epgId, array $playlistIds, PluginExecutionContext $context): PluginActionResult
    {
        $epg = Epg::find($epgId);
        if (! $epg) {
            return PluginActionResult::failure("EPG [{$epgId}] not found.");
        }

        $cacheService = app(EpgCacheService::class);
        if (! $cacheService->isCacheValid($epg)) {
            return PluginActionResult::failure("EPG cache for '{$epg->name}' is not valid. Sync the EPG first.");
        }

        $targetChannelIds = $this->resolveTargetChannelIds($epgId, $playlistIds);
        if (empty($targetChannelIds)) {
            return PluginActionResult::success('No playlist channels are mapped to this EPG - nothing to enrich.');
        }

        $settings = $context->settings;
        $overwrite = $settings['overwrite_existing'] ?? false;
        $enrichPosters = $settings['enrich_posters'] ?? true;
        $enrichDescriptions = $settings['enrich_descriptions'] ?? true;
        $apiKey = $settings['sportsdb_api_key'] ?? '';
        $country = $settings['sportsdb_country'] ?? 'Germany';

        $metadata = $this->readMetadata($epg);
        if (! $metadata) {
            return PluginActionResult::failure('Could not read EPG cache metadata.');
        }

        $minDate = $metadata['programme_date_range']['min_date'] ?? null;
        $maxDate = $metadata['programme_date_range']['max_date'] ?? null;
        if (! $minDate || ! $maxDate) {
            return PluginActionResult::failure('EPG cache has no programme date range.');
        }

        // Load enrichment state
        $enrichmentState = $this->loadEnrichmentState();
        $stateKey = "epg_{$epgId}";
        $settingsHash = $this->computeSettingsHash($settings);
        $channelsHash = $this->computeChannelsHash($targetChannelIds);

        $epgState = $enrichmentState[$stateKey] ?? [];
        $storedSettingsHash = $epgState['settings_hash'] ?? null;
        $storedChannelsHash = $epgState['channels_hash'] ?? null;
        $fileStates = $epgState['files'] ?? [];

        if ($storedSettingsHash !== null && $storedSettingsHash !== $settingsHash) {
            $context->heartbeat('Settings changed - re-processing all files.');
            $fileStates = [];
        } elseif ($storedChannelsHash !== null && $storedChannelsHash !== $channelsHash) {
            $context->heartbeat('Channel mappings changed - re-processing all files.');
            $fileStates = [];
        }

        $cacheDir = "epg-cache/{$epg->uuid}/v1";
        $currentDate = Carbon::parse($minDate);
        $endDate = Carbon::parse($maxDate);
        $totalDays = $currentDate->diffInDays($endDate) + 1;
        $dayIndex = 0;

        $stats = [
            'programmes_processed' => 0,
            'programmes_updated' => 0,
            'programmes_skipped' => 0,
            'posters_added' => 0,
            'descriptions_added' => 0,
            'days_processed' => 0,
            'days_skipped' => 0,
            'channels_targeted' => count($targetChannelIds),
            'api_requests' => 0,
            'events_fetched' => 0,
        ];

        // Pre-fetch all SportsDB events for the full date range
        $context->info('Fetching SportsDB events for date range...');
        $context->heartbeat('Fetching SportsDB events for date range...');
        $allEvents = $this->fetchEventsForDateRange(
            $currentDate->copy(),
            $endDate->copy(),
            $apiKey,
            $country,
            $stats,
            $context,
        );

        // For the free tier, also fetch league events over an extended lookback window.
        // This allows matching EPG replay broadcasts against recent league matches
        // (e.g. last weekend's Bundesliga game rebroadcast on Monday/Tuesday).
        $leagueEventPool = [];
        if ($apiKey === '') {
            // Look back 7 days before the EPG start to cover last weekend's matches
            // that are rebroadcast during the current EPG window.
            // Total window = 7 + EPG_days (e.g. 7 + 4 = 11 days, not 18).
            $lookbackDays = 7;
            $context->heartbeat("Pre-fetching league events ({$lookbackDays}-day lookback for replay matching)...");
            $leagueEventPool = $this->fetchLeagueEventPool(
                $currentDate->copy(),
                $endDate->copy(),
                $settings,
                $lookbackDays,
                $stats,
                $context,
            );
        }

        $newFileStates = [];

        while ($currentDate->lte($endDate)) {
            $dayIndex++;
            $dateStr = $currentDate->format('Y-m-d');
            $jsonlFile = "{$cacheDir}/programmes-{$dateStr}.jsonl";
            $fileName = "programmes-{$dateStr}.jsonl";

            if ($context->cancellationRequested()) {
                $enrichmentState[$stateKey] = [
                    'settings_hash' => $settingsHash,
                    'channels_hash' => $channelsHash,
                    'files' => array_merge($fileStates, $newFileStates),
                ];
                $this->saveEnrichmentState($enrichmentState);

                return PluginActionResult::cancelled('SportsDB enrichment cancelled.', $stats);
            }

            if (! Storage::disk('local')->exists($jsonlFile)) {
                $stats['days_processed']++;
                $currentDate->addDay();

                continue;
            }

            $fullPath = Storage::disk('local')->path($jsonlFile);
            $currentHash = md5_file($fullPath);
            $storedSourceHash = $fileStates[$fileName]['source_hash'] ?? null;
            $storedEnrichedHash = $fileStates[$fileName]['enriched_hash'] ?? null;

            if ($storedSourceHash !== null && ($currentHash === $storedSourceHash || $currentHash === $storedEnrichedHash)) {
                $context->info("Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - unchanged");
                $context->heartbeat(
                    "Skipping {$dateStr} ({$dayIndex}/{$totalDays}) - unchanged",
                    progress: (int) (($dayIndex / $totalDays) * 100)
                );
                $newFileStates[$fileName] = $fileStates[$fileName];
                $stats['days_skipped']++;
                $stats['days_processed']++;
                $currentDate->addDay();

                continue;
            }

            // Merge day-specific sport-type events with the pre-fetched league pool.
            // Day events may be empty on non-match days (e.g. international breaks);
            // the league pool covers the past 14 days so replay content can still be matched.
            $dayEvents = $allEvents[$dateStr] ?? [];
            $mergedEvents = $this->mergeEventArrays($dayEvents, $leagueEventPool);

            $mergedEventsCount = count($mergedEvents);
            $context->info("Processing {$dateStr} ({$dayIndex}/{$totalDays}) - {$mergedEventsCount} events available (pool)");
            $context->heartbeat(
                "Processing {$dateStr} ({$dayIndex}/{$totalDays})...",
                progress: (int) (($dayIndex / $totalDays) * 100)
            );

            $result = $this->processDateFile(
                $jsonlFile,
                $targetChannelIds,
                $mergedEvents,
                $overwrite,
                $enrichPosters,
                $enrichDescriptions,
                $context,
                $settings,
            );

            $stats['programmes_processed'] += $result['processed'];
            $stats['programmes_updated'] += $result['updated'];
            $stats['programmes_skipped'] += $result['skipped'];
            $stats['posters_added'] += $result['posters'];
            $stats['descriptions_added'] += $result['descriptions'];

            $enrichedHash = $result['modified']
                ? md5_file(Storage::disk('local')->path($jsonlFile))
                : $currentHash;

            $newFileStates[$fileName] = [
                'source_hash' => $currentHash,
                'enriched_hash' => $enrichedHash,
                'enriched_at' => now()->toIso8601String(),
                'programmes_updated' => $result['updated'],
            ];

            $stats['days_processed']++;
            $currentDate->addDay();
        }

        $enrichmentState[$stateKey] = [
            'settings_hash' => $settingsHash,
            'channels_hash' => $channelsHash,
            'files' => $newFileStates,
        ];
        $this->saveEnrichmentState($enrichmentState);

        $skippedInfo = $stats['days_skipped'] > 0
            ? " ({$stats['days_skipped']} day(s) skipped - unchanged)"
            : '';

        $summary = "SportsDB enrichment complete for '{$epg->name}': "
            ."{$stats['programmes_updated']}/{$stats['programmes_processed']} sport programmes updated "
            ."across {$stats['channels_targeted']} channels, {$stats['days_processed']} day(s){$skippedInfo}. "
            ."({$stats['events_fetched']} events fetched via {$stats['api_requests']} API requests)";

        return PluginActionResult::success($summary, $stats);
    }

    // ── API fetching ────────────────────────────────────────────────

    /**
     * Fetch events for the full date range.
     *
     * Free tier: queries eventsday.php per sport type per day (5 results each).
     * Premium: queries eventstv.php per day (up to 500 results with channel info).
     *
     * @return array<string, list<array>> Events grouped by date (Y-m-d)
     */
    private function fetchEventsForDateRange(
        Carbon $startDate,
        Carbon $endDate,
        string $apiKey,
        string $country,
        array &$stats,
        PluginExecutionContext $context,
    ): array {
        $key = $apiKey !== '' ? $apiKey : '123';
        $isPremium = $apiKey !== '';
        $allEvents = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            $allEvents[$dateStr] = [];

            if ($context->cancellationRequested()) {
                break;
            }

            if ($isPremium) {
                $events = $this->fetchPremiumTvEvents($key, $dateStr, $country);
                $stats['api_requests']++;
                $stats['events_fetched'] += count($events);
                $allEvents[$dateStr] = $events;

                $context->info("Fetched {$dateStr}: ".count($events).' TV events (premium)');
                $context->heartbeat("Fetched {$dateStr}: ".count($events).' TV events (premium)');
            } else {
                foreach (self::SPORT_TYPES as $sport) {
                    if ($context->cancellationRequested()) {
                        break;
                    }

                    $events = $this->fetchFreeDayEvents($key, $dateStr, $sport);
                    $stats['api_requests']++;
                    $stats['events_fetched'] += count($events);

                    // Merge events, deduplicate by event ID
                    foreach ($events as $event) {
                        $eventId = $event['idEvent'] ?? null;
                        if ($eventId !== null) {
                            $allEvents[$dateStr][$eventId] = $event;
                        } else {
                            $allEvents[$dateStr][] = $event;
                        }
                    }

                    // Rate limit: 30 req/min → ~2s between requests
                    usleep(self::REQUEST_DELAY_US);
                }

                // League events are fetched separately via fetchLeagueEventPool() with
                // a 14-day lookback window to handle replay content. Do not query per-day
                // here to avoid double-counting and unnecessary extra API requests.
                $allEvents[$dateStr] = array_values($allEvents[$dateStr]);
                $eventCount = count($allEvents[$dateStr]);
                $context->info("Fetched {$dateStr}: {$eventCount} sport-type events across ".count(self::SPORT_TYPES).' sport types');
                $context->heartbeat("Fetched {$dateStr}: {$eventCount} sport-type events");
            }

            $currentDate->addDay();
        }

        return $allEvents;
    }

    /**
     * Fetch events for a single day + sport type via eventsday.php (free tier).
     *
     * @return list<array>
     */
    private function fetchFreeDayEvents(string $apiKey, string $date, string $sport): array
    {
        $sportParam = urlencode(str_replace(' ', '_', $sport));
        $url = "https://www.thesportsdb.com/api/v1/json/{$apiKey}/eventsday.php?d={$date}&s={$sportParam}";

        return $this->httpGetJson($url, 'events') ?? [];
    }

    /**
     * Fetch events for a single day + specific league via eventsday.php (free tier).
     * Returns up to 5 events for that league, ideal for country-specific leagues.
     *
     * @return list<array>
     */
    private function fetchFreeDayLeagueEvents(string $apiKey, string $date, string $league): array
    {
        $leagueParam = urlencode(str_replace(' ', '_', $league));
        $url = "https://www.thesportsdb.com/api/v1/json/{$apiKey}/eventsday.php?d={$date}&l={$leagueParam}";

        return $this->httpGetJson($url, 'events') ?? [];
    }

    /**
     * Fetch league events over an extended date window (lookback + EPG range).
     *
     * Returns a flat, deduplicated pool of events. Used for matching EPG replay
     * broadcasts against recent matches that were played before the broadcast date.
     *
     * @param  int  $lookbackDays  How many days before $startDate to include
     * @return list<array>
     */
    private function fetchLeagueEventPool(
        Carbon $startDate,
        Carbon $endDate,
        array $settings,
        int $lookbackDays,
        array &$stats,
        PluginExecutionContext $context,
    ): array {
        $leagueNames = $this->resolveLeagueNames($settings);
        if (empty($leagueNames)) {
            return [];
        }

        $apiKey = $settings['sportsdb_api_key'] ?? '';
        $key = $apiKey !== '' ? $apiKey : '123';

        $pool = [];
        $fetchStart = $startDate->copy()->subDays($lookbackDays);
        $current = $fetchStart->copy();
        $leagueCount = count($leagueNames);
        $totalDays = (int) $fetchStart->diffInDays($endDate) + 1;

        $context->info("Pre-fetching {$leagueCount} league(s) over {$totalDays} days (lookback {$lookbackDays}d)...");

        while ($current->lte($endDate)) {
            $dateStr = $current->format('Y-m-d');

            foreach ($leagueNames as $league) {
                if ($context->cancellationRequested()) {
                    break 2;
                }

                $events = $this->fetchFreeDayLeagueEvents($key, $dateStr, $league);
                $stats['api_requests']++;
                $stats['events_fetched'] += count($events);

                foreach ($events as $event) {
                    $id = $event['idEvent'] ?? null;
                    if ($id !== null) {
                        $pool[$id] = $event;
                    } else {
                        $pool[] = $event;
                    }
                }

                usleep(self::REQUEST_DELAY_US);
            }

            $current->addDay();
        }

        $pool = array_values($pool);
        $context->info('League event pool ready: '.count($pool).' unique events.');

        return $pool;
    }

    /**
     * Merge two event arrays, deduplicating by event ID.
     * Day events take priority (keyed first) so live events are not overwritten.
     *
     * @return list<array>
     */
    private function mergeEventArrays(array $dayEvents, array $poolEvents): array
    {
        $merged = [];

        foreach ($dayEvents as $event) {
            $id = $event['idEvent'] ?? null;
            if ($id !== null) {
                $merged[$id] = $event;
            } else {
                $merged[] = $event;
            }
        }

        foreach ($poolEvents as $event) {
            $id = $event['idEvent'] ?? null;
            if ($id !== null && ! isset($merged[$id])) {
                $merged[$id] = $event;
            } elseif ($id === null) {
                $merged[] = $event;
            }
        }

        return array_values($merged);
    }

    /**
     * Resolve the full list of league names to query, based on settings.
     * Merges leagues from selected countries with any manually specified custom leagues.
     *
     * @return list<string>
     */
    private function resolveLeagueNames(array $settings): array
    {
        $leagues = [];

        // Leagues from selected countries
        $selectedCountries = $settings['league_countries'] ?? ['Germany'];
        if (! is_array($selectedCountries)) {
            $selectedCountries = [$selectedCountries];
        }

        foreach ($selectedCountries as $country) {
            $countryLeagues = self::LEAGUES_BY_COUNTRY[$country] ?? [];
            foreach ($countryLeagues as $league) {
                $leagues[$league] = true;
            }
        }

        // Manually specified custom leagues (tags field)
        $customLeagues = $settings['custom_leagues'] ?? [];
        if (! is_array($customLeagues)) {
            $customLeagues = [];
        }

        foreach ($customLeagues as $league) {
            $league = trim((string) $league);
            if ($league !== '') {
                $leagues[$league] = true;
            }
        }

        return array_keys($leagues);
    }

    /**
     * Fetch TV schedule events for a single day via eventstv.php (premium).
     *
     * @return list<array>
     */
    private function fetchPremiumTvEvents(string $apiKey, string $date, string $country): array
    {
        $url = "https://www.thesportsdb.com/api/v1/json/{$apiKey}/eventstv.php?d={$date}";
        if ($country !== '') {
            $url .= '&a='.urlencode(str_replace(' ', '_', $country));
        }

        return $this->httpGetJson($url, 'tvevents') ?? [];
    }

    /**
     * Perform an HTTP GET and decode JSON, returning values under a specific key.
     *
     * @return list<array>|null
     */
    private function httpGetJson(string $url, string $key): ?array
    {
        try {
            $response = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\n",
                ],
            ]));

            if ($response === false) {
                return null;
            }

            // Handle rate limiting
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (str_starts_with($header, 'HTTP/') && str_contains($header, '429')) {
                        // Rate limited — wait 60 seconds and retry once
                        sleep(60);

                        return $this->httpGetJson($url, $key);
                    }
                }
            }

            $data = json_decode($response, true);
            $list = $data[$key] ?? null;

            return is_array($list) ? $list : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── JSONL processing ────────────────────────────────────────────

    /**
     * Process a single date's JSONL file: match Sports programmes to SportsDB events.
     *
     * @param  array<string>  $targetChannelIds
     * @param  list<array>  $events  SportsDB events for this date
     * @return array{processed: int, updated: int, skipped: int, posters: int, descriptions: int, modified: bool}
     */
    private function processDateFile(
        string $jsonlFile,
        array $targetChannelIds,
        array $events,
        bool $overwrite,
        bool $enrichPosters,
        bool $enrichDescriptions,
        PluginExecutionContext $context,
        array $settings = [],
    ): array {
        $result = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'posters' => 0,
            'descriptions' => 0,
            'modified' => false,
        ];

        if (empty($events)) {
            return $result;
        }

        $fullPath = Storage::disk('local')->path($jsonlFile);
        $targetSet = array_flip($targetChannelIds);

        $enrichedLines = [];
        if (($handle = fopen($fullPath, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (! $record || ! isset($record['channel'], $record['programme'])) {
                    $enrichedLines[] = $line;

                    continue;
                }

                if (! isset($targetSet[$record['channel']])) {
                    $enrichedLines[] = $line;

                    continue;
                }

                $programme = $record['programme'];
                $category = $programme['category'] ?? '';

                // Skip non-sport programmes unless match_without_category is enabled
                // and the programme title contains a known sports keyword.
                $isSports = $category === 'Sports';
                if (! $isSports && ($settings['match_without_category'] ?? false)) {
                    $titleLower = mb_strtolower($programme['title'] ?? '');
                    $isSports = (bool) preg_match('/\b(?:bundesliga|football|fußball|soccer|golf|f1|formula\s*1|motorsport|motogp|nba|nhl|nfl|tennis|handball|rugby|cycling|radrennen|tour de france|champions league|europa league|dfb.pokal|premier league|la liga|serie\s*a)\b/iu', $titleLower);
                }

                // Only process programmes identified as sports
                if (! $isSports) {
                    $enrichedLines[] = $line;

                    continue;
                }

                $result['processed']++;

                $matchedEvent = $this->matchSportsEvent($programme, $events, $settings);
                if ($matchedEvent) {
                    $eventName = $matchedEvent['strEvent'] ?? 'unknown';
                    $context->info("Matched: \"{$programme['title']}\" → \"{$eventName}\"");

                    $enrichResult = $this->enrichFromSportsDb(
                        $programme,
                        $matchedEvent,
                        $overwrite,
                        $enrichPosters,
                        $enrichDescriptions,
                    );

                    if ($enrichResult['changed']) {
                        $result['modified'] = true;
                        $result['updated']++;
                        $result['posters'] += $enrichResult['poster'] ? 1 : 0;
                        $result['descriptions'] += $enrichResult['description'] ? 1 : 0;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    $context->info("Unmatched: \"{$programme['title']}\"");
                    $result['skipped']++;
                }

                $enrichedLines[] = json_encode([
                    'channel' => $record['channel'],
                    'programme' => $programme,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($context->cancellationRequested()) {
                    break;
                }
            }
            fclose($handle);
        }

        if ($result['modified']) {
            $tempPath = $fullPath.'.sportsdb-enriching';
            if (($handle = fopen($tempPath, 'w')) !== false) {
                foreach ($enrichedLines as $line) {
                    fwrite($handle, $line."\n");
                }
                fclose($handle);

                if (is_dir(dirname($fullPath))) {
                    rename($tempPath, $fullPath);
                } else {
                    @unlink($tempPath);
                }
            }
        }

        return $result;
    }

    // ── Event matching ──────────────────────────────────────────────

    /**
     * Match a sports programme to a TheSportsDB event.
     *
     * Uses bidirectional token matching combined with time-window overlap.
     * Handles EPG title formats like:
     *   - "Bundesliga 25/26: FC Bayern - Dortmund"
     *   - "Live NBA: Orlando Magic @ Boston Celtics"
     *   - "F1: Rennen - GP Australien"
     *
     * @param  array  $programme  The EPG programme data
     * @param  list<array>  $events  SportsDB events for the day
     * @return array|null The best matching event, or null
     */
    private function matchSportsEvent(array $programme, array $events, array $settings = []): ?array
    {
        if (empty($events)) {
            return null;
        }

        $progTitle = mb_strtolower($programme['title'] ?? '');
        $progStart = $programme['start'] ?? null;

        if ($progTitle === '') {
            return null;
        }

        // Clean up EPG title: remove common prefixes and suffixes
        $cleanTitle = $this->cleanEpgSportsTitle($progTitle);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($events as $event) {
            $eventName = mb_strtolower($event['strEvent'] ?? '');
            if ($eventName === '') {
                continue;
            }

            $score = $this->computeMatchScore($cleanTitle, $eventName, $progStart, $event);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $event;
            }
        }

        // Require a reasonable confidence score.
        // For individual sports (Golf, Tennis, F1) where the best matched event has no team
        // names, the maximum achievable score is ~60 instead of ~115 (no 50-pt team bonus).
        // Use a lower threshold in that case to avoid filtering out valid matches.
        $threshold = (int) ($settings['match_threshold'] ?? 35);

        if ($bestMatch !== null) {
            $hasTeams = ($bestMatch['strHomeTeam'] ?? '') !== '' || ($bestMatch['strAwayTeam'] ?? '') !== '';
            if (! $hasTeams && $threshold > 20) {
                $threshold = 20;
            }
        }

        return $bestScore >= $threshold ? $bestMatch : null;
    }

    /**
     * Clean up EPG sports title for better matching.
     *
     * Removes common prefixes (Live, Es folgt:), suffixes (ᴸᶦᵛᵉ),
     * sport type prefixes (F1:, NHL:, ATP 500:), league/season markers.
     */
    private function cleanEpgSportsTitle(string $title): string
    {
        // Remove "Live " prefix and unicode superscript "ᴸᶦᵛᵉ" suffix
        $title = preg_replace('/^live\s+/iu', '', $title);
        $title = preg_replace('/\s*ᴸᶦᵛᵉ\s*$/u', '', $title);

        // Remove "Es folgt: " or "Es folgt: Live " prefix
        $title = preg_replace('/^es folgt:\s*(live\s+)?/iu', '', $title);

        // Remove "Nur in Deutschland verfügbar!" suffix
        $title = preg_replace('/\.?\s*nur in \w+ verfügbar!?\s*$/iu', '', $title);

        // Remove sport type prefixes: "F1:", "NHL:", "ATP 1000:", "WTA 500:", "PL:", "UEFA CL:", etc.
        $title = preg_replace('/^(?:F1|F1 Academy|F1 Sprint|MotoGP|IndyCar|NTT IndyCar Series|WRC|NASCAR)\s*:\s*/iu', '', $title);
        $title = preg_replace('/^(?:NHL|NBA|NFL|NRL Rugby|NRL)\s*:\s*/iu', '', $title);
        $title = preg_replace('/^(?:ATP|ATP \d+|WTA|WTA \d+)\s*:\s*/iu', '', $title);
        $title = preg_replace('/^(?:PL|UCL|UEFA (?:CL|ECL|Champions League))\s*:\s*/iu', '', $title);
        $title = preg_replace('/^(?:Bundesliga|DFB-Pokal|DFB-Pokal Frauen|Fußball|Fussball|Golf|Golf Ladies ET|Rugby Super League|TGL)\s*:\s*/iu', '', $title);
        $title = preg_replace('/^(?:Live\s+)?(?:Sky Sport News|sportstudio)\s*:?\s*/iu', '', $title);

        // Remove season markers like "25/26:" or "2025/2026:" (possibly preceded by league name)
        $title = preg_replace('/\d{2,4}\/\d{2,4}:\s*/', '', $title);

        // Remove round/matchday info like "29. Spieltag" or "32. Spieltag"
        $title = preg_replace('/,?\s*\d+\.\s*spieltag\s*/iu', '', $title);

        // Remove round info like "Viertelfinale", "Halbfinale", "Finale", "Rückspiel", "Hinspiel"
        $title = preg_replace('/,?\s*(?:Viertelfinale|Halbfinale|Finale|Achtelfinale|Rückspiel|Hinspiel)\s*/iu', '', $title);

        // Remove day info like "1. Tag", "2. Tag", "Finaltag"
        $title = preg_replace('/,?\s*(?:\d+\.\s*Tag|Finaltag)\b/iu', '', $title);

        // Normalize "Team @ Team" → "Team vs Team"
        $title = str_replace(' @ ', ' vs ', $title);

        // Normalize " - " separator to " vs " for team matchups only.
        // Do NOT apply when either side contains generic individual-sport words
        // (e.g. "F1: Rennen - GP Australien" must not become "Rennen vs GP Australien")
        if (preg_match('/^([A-ZÄÖÜa-zäöü0-9][\w\s.]+\S)\s+-\s+(\S[\w\s.]+)$/u', trim($title), $m)) {
            $individualSportPattern = '/\b(?:rennen|qualifying|training|sprint|race|session|gp|stage|tour|lap|heat|round|etappe|lauf|tag|practice|warm.?up)\b/iu';
            if (! preg_match($individualSportPattern, $m[1]) && ! preg_match($individualSportPattern, $m[2])) {
                $title = $m[1].' vs '.$m[2];
            }
        }

        return trim($title);
    }

    /**
     * Extract team names from the EPG title for direct matching.
     *
     * Handles formats like "FC Bayern München - Borussia Dortmund" or "Team A vs Team B".
     *
     * @return array{home: string, away: string}|null
     */
    private function extractTeamNames(string $title): ?array
    {
        // Try "Team A - Team B" format (most common in German EPG)
        if (preg_match('/^(.+?)\s+(?:vs\.?|[-–]|@)\s+(.+?)$/iu', $title, $m)) {
            $home = trim($m[1]);
            $away = trim($m[2]);

            // Both parts should be at least 2 chars and not look like generic sport words
            if (mb_strlen($home) >= 2 && mb_strlen($away) >= 2) {
                return ['home' => mb_strtolower($home), 'away' => mb_strtolower($away)];
            }
        }

        return null;
    }

    /**
     * Common German→English team name mappings for fuzzy matching.
     */
    private const array TEAM_NAME_ALIASES = [
        'münchen' => 'munich',
        'mailand' => 'milan',
        'neapel' => 'naples',
        'lissabon' => 'lisbon',
        'kopenhagen' => 'copenhagen',
        'brügge' => 'bruges',
        'athen' => 'athens',
        'prag' => 'prague',
        'warschau' => 'warsaw',
        'bukarest' => 'bucharest',
        'moskau' => 'moscow',
        'donezk' => 'donetsk',
    ];

    /**
     * Compute a match score between a cleaned EPG title and a SportsDB event.
     *
     * Scoring:
     *   - Team name match (both teams): 50 points
     *   - Team name match (one team): 25 points
     *   - Bidirectional token overlap: up to 30 points
     *   - Time proximity (±90 min): up to 30 points
     *   - Sport type bonus: up to 5 points
     */
    private function computeMatchScore(string $progTitle, string $eventName, ?string $progStart, array $event): float
    {
        $score = 0;

        // Team-based matching: extract team names from EPG and compare to event teams
        $homeTeam = mb_strtolower($event['strHomeTeam'] ?? '');
        $awayTeam = mb_strtolower($event['strAwayTeam'] ?? '');
        $progTitleAliased = $this->applyTeamAliases($progTitle);

        $homeFound = $this->teamNameMatches($homeTeam, $progTitle, $progTitleAliased);
        $awayFound = $this->teamNameMatches($awayTeam, $progTitle, $progTitleAliased);

        // Also try matching EPG-extracted teams against event teams
        $epgTeams = $this->extractTeamNames($progTitle);
        if ($epgTeams && $homeTeam !== '' && $awayTeam !== '') {
            $epgHome = $this->applyTeamAliases($epgTeams['home']);
            $epgAway = $this->applyTeamAliases($epgTeams['away']);

            // Check cross-matching (EPG home vs event home/away, EPG away vs event home/away)
            if (! $homeFound) {
                $homeFound = $this->fuzzyTeamContains($epgHome, $homeTeam)
                    || $this->fuzzyTeamContains($epgAway, $homeTeam)
                    || $this->fuzzyTeamContains($homeTeam, $epgHome)
                    || $this->fuzzyTeamContains($homeTeam, $epgAway);
            }
            if (! $awayFound) {
                $awayFound = $this->fuzzyTeamContains($epgHome, $awayTeam)
                    || $this->fuzzyTeamContains($epgAway, $awayTeam)
                    || $this->fuzzyTeamContains($awayTeam, $epgHome)
                    || $this->fuzzyTeamContains($awayTeam, $epgAway);
            }
        }

        if ($homeFound && $awayFound) {
            $score += 50;
        } elseif ($homeFound || $awayFound) {
            $score += 25;
        }

        // Token-based matching
        $progTokens = $this->tokenize($progTitle);
        $eventTokens = $this->tokenize($eventName);

        if (! empty($progTokens) && ! empty($eventTokens)) {
            $forwardMatches = $this->countTokenOverlap($eventTokens, $progTokens);
            $reverseMatches = $this->countTokenOverlap($progTokens, $eventTokens);

            $forwardScore = $forwardMatches / count($eventTokens);
            $reverseScore = $reverseMatches / count($progTokens);

            // Use the average instead of min to be more forgiving with German titles
            $tokenScore = ($forwardScore + $reverseScore) / 2;
            $score += $tokenScore * 30;
        }

        // If we have no team match and very low token overlap, bail early
        if ($score < 10) {
            return 0;
        }

        // Time proximity scoring
        $eventTimestamp = $event['strTimestamp'] ?? $event['strTimeStamp'] ?? null;
        $eventDate = $event['dateEvent'] ?? null;
        $eventTime = $event['strTime'] ?? null;
        if (! $eventTimestamp && $eventDate && $eventTime) {
            $eventTimestamp = $eventDate.' '.$eventTime;
        }

        if ($progStart && $eventTimestamp) {
            try {
                $progTime = Carbon::parse($progStart);
                $eventTimeCarbon = Carbon::parse($eventTimestamp);
                $diffMinutes = abs($progTime->diffInMinutes($eventTimeCarbon));

                if ($diffMinutes <= 90) {
                    $score += 30 * (1 - $diffMinutes / 90);
                }
            } catch (\Throwable) {
                // Ignore parse errors
            }
        }

        // Sport type bonus
        $sport = mb_strtolower($event['strSport'] ?? '');
        if ($sport !== '' && str_contains($progTitle, $sport)) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Tokenize a string into significant words (3+ chars).
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/[\s\-_:;,\.\/\(\)@|]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $tokens,
            fn (string $t) => mb_strlen($t) >= 3 && ! in_array($t, ['the', 'der', 'die', 'das', 'und', 'and', 'von', 'vom', 'für', 'for', 'nur'], true)
        ));
    }

    /**
     * Count how many tokens from $source appear in $target (exact or fuzzy).
     */
    private function countTokenOverlap(array $source, array $target): int
    {
        $matches = 0;
        foreach ($source as $token) {
            foreach ($target as $candidate) {
                if ($token === $candidate) {
                    $matches++;

                    break;
                }
                // Fuzzy: allow minor spelling differences (e.g. München/munchen)
                if (mb_strlen($token) >= 4 && mb_strlen($candidate) >= 4) {
                    $maxLen = max(mb_strlen($token), mb_strlen($candidate));
                    if (levenshtein($token, $candidate) <= max(1, (int) ($maxLen * 0.25))) {
                        $matches++;

                        break;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Apply German→English team name aliases to a string.
     */
    private function applyTeamAliases(string $text): string
    {
        foreach (self::TEAM_NAME_ALIASES as $de => $en) {
            $text = str_replace($de, $en, $text);
        }

        return $text;
    }

    /**
     * Check if a team name from SportsDB is found in the EPG title.
     * Tries with and without German→English aliases.
     */
    private function teamNameMatches(string $teamName, string $progTitle, string $progTitleAliased): bool
    {
        if ($teamName === '') {
            return false;
        }

        // Direct substring match
        if (str_contains($progTitle, $teamName)) {
            return true;
        }

        // Try with aliases applied to the EPG title
        if (str_contains($progTitleAliased, $teamName)) {
            return true;
        }

        // Try significant words of team name (e.g. "Bayern" from "Bayern Munich")
        $teamTokens = array_filter(
            preg_split('/[\s]+/', $teamName, -1, PREG_SPLIT_NO_EMPTY),
            fn (string $t) => mb_strlen($t) >= 4 && ! in_array($t, ['the', 'club', 'city', 'team', 'united', 'real', 'sporting'], true)
        );

        foreach ($teamTokens as $token) {
            if (str_contains($progTitle, $token) || str_contains($progTitleAliased, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fuzzy check if one team name contains significant parts of another.
     */
    private function fuzzyTeamContains(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
            return true;
        }

        $haystackAliased = $this->applyTeamAliases($haystack);
        $needleAliased = $this->applyTeamAliases($needle);

        return str_contains($haystackAliased, $needleAliased)
            || str_contains($needleAliased, $haystackAliased);
    }

    // ── Enrichment ──────────────────────────────────────────────────

    /**
     * Enrich a programme with TheSportsDB event data.
     *
     * @return array{changed: bool, poster: bool, description: bool}
     */
    private function enrichFromSportsDb(
        array &$programme,
        array $event,
        bool $overwrite,
        bool $enrichPosters,
        bool $enrichDescriptions,
    ): array {
        $result = ['changed' => false, 'poster' => false, 'description' => false];

        $posterUrl = $event['strEventThumb'] ?? $event['strEventPoster'] ?? $event['strThumb'] ?? null;
        $bannerUrl = $event['strEventBanner'] ?? null;
        $hasIcon = ! empty($programme['icon']);

        if ($enrichPosters && $posterUrl && ($overwrite || ! $hasIcon)) {
            $programme['icon'] = $posterUrl;
            $result['poster'] = true;
            $result['changed'] = true;
        }

        if ($enrichPosters && $bannerUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($bannerUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $bannerUrl,
                    'type' => 'backdrop',
                    'width' => 1920,
                    'height' => 1080,
                    'orient' => 'L',
                    'size' => 3,
                ];
                $result['changed'] = true;
            }
        }

        if ($enrichPosters && $posterUrl) {
            $existingUrls = array_column($programme['images'] ?? [], 'url');
            if (! in_array($posterUrl, $existingUrls, true)) {
                $programme['images'][] = [
                    'url' => $posterUrl,
                    'type' => 'poster',
                    'width' => 500,
                    'height' => 750,
                    'orient' => 'P',
                    'size' => 2,
                ];
                $result['changed'] = true;
            }
        }

        $hasDesc = ! empty($programme['desc']);
        if ($enrichDescriptions && ($overwrite || ! $hasDesc)) {
            $parts = [];
            $sport = $event['strSport'] ?? '';
            $league = $event['strLeague'] ?? '';
            $season = $event['strSeason'] ?? '';
            $venue = $event['strVenue'] ?? '';

            if ($sport !== '') {
                $parts[] = $sport;
            }
            if ($league !== '') {
                $parts[] = $league;
            }
            if ($season !== '') {
                $parts[] = "Season {$season}";
            }
            if ($venue !== '') {
                $parts[] = $venue;
            }

            if (! empty($parts)) {
                $programme['desc'] = implode(' · ', $parts);
                $result['description'] = true;
                $result['changed'] = true;
            }
        }

        return $result;
    }

    // ── DB queries ──────────────────────────────────────────────────

    /**
     * Resolve EPG channel_id strings that are mapped in the given playlists.
     *
     * @param  array<int>  $playlistIds
     * @return array<string>
     */
    private function resolveTargetChannelIds(int $epgId, array $playlistIds): array
    {
        if (empty($playlistIds)) {
            return [];
        }

        $epgChannelDbIds = Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->distinct()
            ->pluck('epg_channel_id');

        return EpgChannel::query()
            ->where('epg_id', $epgId)
            ->whereIn('id', $epgChannelDbIds)
            ->pluck('channel_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Count how many enabled playlist channels target the given EPG(s).
     *
     * @param  array<int>|int  $epgIds
     * @param  array<int>  $playlistIds
     */
    private function countTargetChannels(array|int $epgIds, array $playlistIds): int
    {
        if (empty($playlistIds)) {
            return 0;
        }

        $epgIds = (array) $epgIds;

        return Channel::query()
            ->whereIn('playlist_id', $playlistIds)
            ->where('enabled', true)
            ->whereNotNull('epg_channel_id')
            ->whereHas('epgChannel', fn ($q) => $q->whereIn('epg_id', $epgIds))
            ->count();
    }

    // ── State & metadata ────────────────────────────────────────────

    private function readMetadata(Epg $epg): ?array
    {
        $path = "epg-cache/{$epg->uuid}/v1/metadata.json";
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return json_decode(Storage::disk('local')->get($path), true);
    }

    private function loadEnrichmentState(): array
    {
        $path = 'plugin-data/epg-sportsdb/enrichment-state.json';
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $data = json_decode(Storage::disk('local')->get($path), true);

        return is_array($data) ? $data : [];
    }

    private function saveEnrichmentState(array $state): void
    {
        Storage::disk('local')->makeDirectory('plugin-data/epg-sportsdb');
        Storage::disk('local')->put(
            'plugin-data/epg-sportsdb/enrichment-state.json',
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function computeSettingsHash(array $settings): string
    {
        $relevant = [
            'overwrite_existing' => $settings['overwrite_existing'] ?? false,
            'enrich_posters' => $settings['enrich_posters'] ?? true,
            'enrich_descriptions' => $settings['enrich_descriptions'] ?? true,
            'sportsdb_api_key' => $settings['sportsdb_api_key'] ?? '',
            'sportsdb_country' => $settings['sportsdb_country'] ?? '',
            'league_countries' => $settings['league_countries'] ?? ['Germany'],
            'custom_leagues' => $settings['custom_leagues'] ?? [],
            'match_threshold' => $settings['match_threshold'] ?? 35,
            'match_without_category' => $settings['match_without_category'] ?? false,
        ];

        return md5(json_encode($relevant));
    }

    private function computeChannelsHash(array $channelIds): string
    {
        $sorted = $channelIds;
        sort($sorted);

        return md5(json_encode($sorted));
    }

    private function healthCheck(PluginExecutionContext $context): PluginActionResult
    {
        $settings = $context->settings;
        $apiKey = $settings['sportsdb_api_key'] ?? '';
        $isPremium = $apiKey !== '';
        $tier = $isPremium ? 'premium' : 'free (key: 123)';

        // Quick API connectivity test
        $testUrl = $isPremium
            ? "https://www.thesportsdb.com/api/v1/json/{$apiKey}/all_sports.php"
            : 'https://www.thesportsdb.com/api/v1/json/123/all_sports.php';

        $apiReachable = false;
        $sportsAvailable = 0;

        try {
            $response = @file_get_contents($testUrl, false, stream_context_create([
                'http' => ['timeout' => 5, 'ignore_errors' => true],
            ]));
            if ($response !== false) {
                $data = json_decode($response, true);
                $apiReachable = true;
                $sportsAvailable = count($data['sports'] ?? []);
            }
        } catch (\Throwable) {
            // API unreachable
        }

        // Enrichment state
        $enrichmentState = $this->loadEnrichmentState();
        $trackedEpgs = count($enrichmentState);
        $trackedFiles = 0;
        $lastEnrichedAt = null;
        foreach ($enrichmentState as $epgState) {
            foreach ($epgState['files'] ?? [] as $fileState) {
                $trackedFiles++;
                $at = $fileState['enriched_at'] ?? null;
                if ($at && ($lastEnrichedAt === null || $at > $lastEnrichedAt)) {
                    $lastEnrichedAt = $at;
                }
            }
        }

        $status = $apiReachable ? 'SportsDB API is reachable.' : 'SportsDB API is NOT reachable!';

        return PluginActionResult::success($status, [
            'plugin_id' => 'epg-sportsdb',
            'api_tier' => $tier,
            'api_reachable' => $apiReachable,
            'sports_available' => $sportsAvailable,
            'sport_types_queried' => count(self::SPORT_TYPES),
            'enrichment_state_epgs' => $trackedEpgs,
            'enrichment_state_files' => $trackedFiles,
            'last_enriched_at' => $lastEnrichedAt,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function clearEnrichmentState(PluginExecutionContext $context): PluginActionResult
    {
        $path = 'plugin-data/epg-sportsdb/enrichment-state.json';

        if (! Storage::disk('local')->exists($path)) {
            return PluginActionResult::success('No enrichment state to clear.');
        }

        $state = $this->loadEnrichmentState();
        $epgCount = count($state);
        $fileCount = 0;
        foreach ($state as $epgState) {
            $fileCount += count($epgState['files'] ?? []);
        }

        Storage::disk('local')->delete($path);

        $context->info("Cleared enrichment state: {$epgCount} EPG(s), {$fileCount} tracked file(s).");

        return PluginActionResult::success(
            "Enrichment state cleared. Next run will re-process all files ({$epgCount} EPG(s), {$fileCount} tracked file(s)).",
            ['epgs_cleared' => $epgCount, 'files_cleared' => $fileCount]
        );
    }
}
