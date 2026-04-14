# EPG SportsDB Enricher Plugin

Enriches EPG sport programmes with artwork and event details from [TheSportsDB](https://www.thesportsdb.com/). Matches live sports broadcasts in your EPG to real events and adds posters, banners and structured descriptions.

## Features

- **Multi-sport fetching** — Queries 12 sport types (Soccer, Motorsport, Ice Hockey, Basketball, Tennis, Golf, Fighting, American Football, Baseball, Rugby, Cycling, Handball) per day for maximum coverage on the free tier
- **Smart title matching** — Cleans EPG titles (removes "Live", season markers like "25/26:", "Spieltag" info), uses bidirectional fuzzy token matching with Levenshtein distance, team name detection, and time proximity scoring
- **Free & premium tiers** — Free API key (123): ~5 events per sport per day via `eventsday.php`. Premium key: up to 500 TV events/day with channel info via `eventstv.php`
- **Rate limiting** — 2.1s delay between requests to stay under 30 req/min on the free tier
- **Playlist-scoped** — Only enriches sport channels mapped in your playlists
- **Incremental processing** — Tracks file hashes to skip unchanged days on re-runs
- **Cancellation support** — Saves progress if cancelled mid-run

## Requirements

- At least one EPG source with a valid cache
- Channels mapped to EPG channels in at least one playlist
- Sport programmes must have `category: Sports` in the EPG (use the EPG Enricher plugin's keyword detection to auto-detect sports categories)

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| SportsDB API key | *(empty)* | Premium API key for full TV schedule matching. Leave empty for free tier |
| Country filter | Germany | Country for TV schedule filtering (premium only) |
| Add event artwork | On | Add event poster/thumbnail images to sport programmes |
| Add event descriptions | On | Add sport, league, season and venue info as description |
| Overwrite existing data | Off | Replace existing artwork and descriptions |
| Auto-run after EPG cache | On | Run automatically after EPG cache generation |
| Restrict auto-run | *(all)* | Only auto-run for specific playlists |

## How It Works

1. Triggered after EPG cache generation (hook: `epg.cache.generated`) or manually
2. Resolves which EPG channel IDs are used in your playlists
3. Pre-fetches all SportsDB events for the full date range:
   - **Free tier**: Queries `eventsday.php?d=DATE&s=SPORT` for each of 12 sport types per day
   - **Premium**: Queries `eventstv.php?d=DATE&a=COUNTRY` for the full TV schedule
4. Reads each day's cached programme JSONL files
5. For each programme with `category: Sports`:
   - Cleans the EPG title (strips "Live", season markers, matchday info)
   - Matches against fetched events using token overlap + time proximity + team names
   - Enriches with poster, banner and structured description (Sport · League · Season · Venue)
6. Writes enriched data back to the JSONL cache

## Version History

### 1.0.0
- Initial release — extracted from [EPG Enricher](https://github.com/Serph91P/m3u-editor-epg-enricher) plugin as standalone SportsDB enricher
- Multi-sport fetching strategy for free tier (12 sport types × n days)
- Improved title matching with EPG title cleaning, bidirectional fuzzy matching, team name detection
- Premium tier support via `eventstv.php` with country filtering
- Rate limiting (2.1s between requests) to respect 30 req/min free tier limit
- Incremental processing with file hash tracking to skip unchanged days
