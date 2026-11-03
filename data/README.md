# data/

This folder holds runtime data for WebCrawler. Its contents are created by the application and are not part of the source code. Git tracks only this README and `.gitkeep`. The database, logs, and PID file are ignored by `.gitignore`.

## Files created here

| File | Created by | Purpose |
|---|---|---|
| `crawler.db` | First crawl (`includes/database.php`) | SQLite database with crawl data. See below. |
| `crawler.db-wal`, `crawler.db-shm` | SQLite, while the database is open | Write-ahead log files that belong to `crawler.db`. |
| `crawl_debug.log` | `api/crawl.php` and `includes/crawler.php` | Detailed crawl log. Moved to `crawl_debug.log.1` once it passes 1 MB, and only one previous copy is kept. |
| `logs/` | `start_puppeteer.bat` | Output of the Puppeteer server (`puppeteer_server.out.log`, `puppeteer_server.err.log`). |
| `puppeteer.pid` | `start_puppeteer.bat` | Process ID of the Puppeteer server it started. Removed by `stop_puppeteer.bat`. |

Progress files, cookie jars, and temporary downloads are written to the system temp folder, not to `data/`. See the table in the main [README](../README.md#logs-and-runtime-files).

## Database: `crawler.db`

The database is created by `CrawlerDatabase` in `includes/database.php`. The default path is `data/crawler.db`, and the folder is created if it is missing. The crawl API enables the database by default (`use_database`).

On open, the code sets:

- `PRAGMA journal_mode=WAL`, so readers and the writer do not block each other.
- `PRAGMA busy_timeout=5000`, so a busy database waits up to five seconds.

Writes that hit a locked database are retried a limited number of times.

### Tables

**`urls`**: one row per crawled page.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Autoincrement. |
| `url` | TEXT, UNIQUE, NOT NULL | Page URL. |
| `base_url` | TEXT, NOT NULL | The start URL of the crawl. |
| `status` | TEXT | Set to `crawled` when a page is recorded. The default `pending` is not used by the current code. |
| `depth` | INTEGER | Link depth of the page. |
| `http_code` | INTEGER | Recorded as 200 by the current code. It is not taken from the real response. |
| `crawled_at` | TIMESTAMP | Set when the row is written. |
| `created_at` | TIMESTAMP | Default `CURRENT_TIMESTAMP`. |

**`files`**: one row per found file.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Autoincrement. |
| `url` | TEXT, UNIQUE, NOT NULL | File URL. Duplicates are ignored (`INSERT OR IGNORE`). |
| `filename` | TEXT | File name derived from the URL. |
| `type` | TEXT | Category: `documents`, `images`, `videos`, `audio`, `archives`, `code`, or `other`. |
| `size` | INTEGER | Always `NULL` in the current code. Sizes are looked up at request time by `api/get_file_sizes.php`. |
| `source_url` | TEXT | The crawl's start URL (`base_url`), not the page where the file was found. |
| `found_at` | TIMESTAMP | Default `CURRENT_TIMESTAMP`. |

**`sessions`**: one row per crawl.

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | Autoincrement. |
| `base_url` | TEXT, NOT NULL | Start URL. |
| `max_depth`, `max_pages` | INTEGER | Limits used for the crawl. |
| `pages_crawled`, `files_found` | INTEGER | Updated periodically during the crawl. |
| `status` | TEXT | Set to `running` when the session starts. |
| `started_at` | TIMESTAMP | Default `CURRENT_TIMESTAMP`. |
| `completed_at` | TIMESTAMP | Empty. See the note below. |

### Indexes

- `idx_urls_url` on `urls(url)`
- `idx_urls_status` on `urls(status)`
- `idx_files_url` on `files(url)`
- `idx_files_type` on `files(type)`

### Known gaps

- **Sessions never complete.** `CrawlerDatabase::completeSession()` exists, but the crawler does not call it. Every row in `sessions` therefore stays `status = 'running'` with an empty `completed_at`, even after the crawl ends. Use `pages_crawled` and `files_found` to see the final numbers.
- **`http_code` is not the real status.** It is recorded as 200 for every page.
- **`files.size` is always empty**, and `files.source_url` is the start URL rather than the page the file came from.
- **The database keeps data across crawls.** Nothing is cleared automatically. `CrawlerDatabase::clearData($baseUrl)` deletes one start URL's rows, but no code path calls it.

## Reading the database

Use any SQLite client, for example the `sqlite3` command-line tool or DB Browser for SQLite. Open the file read-only if you only want to inspect it:

```sql
SELECT base_url, COUNT(*) AS pages FROM urls GROUP BY base_url;
SELECT type, COUNT(*) AS files FROM files GROUP BY type;
SELECT id, base_url, pages_crawled, files_found, status FROM sessions ORDER BY id DESC;
```

## Resetting the data

To remove all crawl data:

1. Stop Apache, so that no crawl is writing to the database.
2. Delete `crawler.db`, `crawler.db-wal`, and `crawler.db-shm` from this folder. Keep `.gitkeep`.
3. Start Apache. The database is recreated on the next crawl.

Do not delete only `crawler.db` while the `-wal` file exists. The WAL file contains changes that have not yet been written to the main database.

To remove only the logs, delete `crawl_debug.log` and `crawl_debug.log.1`. They are recreated when needed.
