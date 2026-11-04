# WebCrawler

A PHP web application that crawls a website, finds downloadable media and documents, and lets you preview them, download them one at a time, or download a selection as a ZIP archive. It runs on an Apache/PHP stack such as XAMPP, and it has no required external services.

> **Status: work in progress.** This is a personal and educational project. The code has not been load-tested or independently security-reviewed, so treat it as not production-ready. Read [Security](#security) before running it anywhere other than your own machine.

Licensed under the [MIT License](LICENSE).

---

## Contents

- [What it does](#what-it-does)
- [What is not available](#what-is-not-available)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Using the web interface](#using-the-web-interface)
- [API reference](#api-reference)
- [Headless browser mode (optional)](#headless-browser-mode-optional)
- [FFmpeg for streams (optional)](#ffmpeg-for-streams-optional)
- [Database](#database)
- [Logs and runtime files](#logs-and-runtime-files)
- [TLS certificate verification](#tls-certificate-verification)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [Responsible use](#responsible-use)
- [Project structure](#project-structure)
- [Known limitations](#known-limitations)
- [License](#license)

---

## What it does

- **Crawls one site.** Starting from a URL, it follows links only to the same host. A leading `www.` is ignored when comparing hosts, so `example.com` and `www.example.com` count as the same site.
- **Limits the crawl.** Depth can be set from 1 to 5 in the UI (default 2). Pages are limited to 100 per crawl, and the server enforces that cap even if a request asks for more.
- **Selects file types.** You choose extensions from the UI checkboxes or type custom ones (for example `epub, mkv`). Extensions are matched with a leading dot, such as `.pdf`.
- **Groups results.** Each found file is placed in a category: `documents`, `images`, `videos`, `audio`, `archives`, `code`, or `other`.
- **Filters results.** Heuristic filters from `includes/smart_filters.php` remove some low-value items. Read that file for the exact rules.
- **Downloads.** Single files, a ZIP of selected files, or FFmpeg-based downloads of HLS (`.m3u8`) and DASH (`.mpd`) streams.
- **Fetches pages in three ways.** Plain cURL is the default. An optional headless browser (Puppeteer or Symfony Panther) is used for pages that need JavaScript. See [Headless browser mode](#headless-browser-mode-optional).
- **Fetches in parallel.** When headless mode is not in use, the crawler fetches several pages at once with cURL multi (5 connections by default).
- **Stores crawl data.** Visited URLs, found files, and crawl sessions are written to a SQLite database in `data/crawler.db`. See [Database](#database).

---

## What is not available

Earlier versions of this project described features that are not in this codebase. They are listed here so nothing is assumed to exist.

| Feature | Status |
|---|---|
| Login or form authentication for protected sites | **Not available.** There is no login handler. The API ignores a `login` field and reports it in `warnings`. |
| Queue system or multi-worker crawling (Redis or otherwise) | **Not available.** There is no queue class and no worker script. The API ignores `use_queue` and reports it in `warnings`. |
| Database viewer page (`view_database.php`) | **Not present.** Use any SQLite client to read `data/crawler.db`. |
| robots.txt compliance | **Off by default and not enabled by the UI.** The crawler has a `respect_robots` option that defaults to `false`, and the robots checker is not loaded by the normal request path. Check each site's robots.txt and terms yourself. |
| Performance benchmarks | **None published.** Crawl speed depends on the target site, the network, and the settings you choose. |
| Browser compatibility matrix | **None published.** The interface is plain HTML and JavaScript. Test it in your own browser. |

---

## Requirements

| Component | Needed for | Notes |
|---|---|---|
| **PHP 8.0 or newer** | Everything | The code uses `str_contains` and `str_starts_with`. Tested with PHP 8.2.12 from XAMPP. |
| **Apache** (or another web server that runs PHP) | Serving the app | The `.htaccess` file sets PHP limits. Those settings apply only when Apache allows overrides (`AllowOverride`) and PHP runs as an Apache module. |
| **PHP extensions** `curl`, `pdo_sqlite`, `dom`, `zip`, `json` | Everything (`zip` only for ZIP downloads) | `curl`, `pdo_sqlite`, and `dom` are needed for crawling and the database. `zip` is needed for `api/download_zip.php`. `json` is normally built in. |
| Node.js LTS and npm | Optional: Puppeteer headless mode | Only needed for the Puppeteer server or bridge. See [Headless browser mode](#headless-browser-mode-optional). |
| Composer | Optional: Symfony Panther headless mode | Only needed for `symfony/panther`, which `composer.json` lists. |
| FFmpeg | Optional: HLS and DASH stream downloads | Only needed for `.m3u8` and `.mpd` downloads. See [FFmpeg for streams](#ffmpeg-for-streams-optional). |

`composer.json` currently declares `php >=7.4`. The code needs PHP 8.0 or newer, so use 8.0 or newer.

---

## Installation

### Option 1: XAMPP on Windows

1. Install XAMPP and start **Apache** from the XAMPP Control Panel.
2. Copy or clone this repository so that it ends up at `C:\xampp\htdocs\WebCrawler`.
3. Open `http://localhost/WebCrawler/`.

Confirm that the `pdo_sqlite`, `curl`, `dom`, and `zip` extensions are enabled. In the XAMPP Control Panel, open **Config → PHP (php.ini)** and check that these lines are not commented out:

```ini
extension=curl
extension=pdo_sqlite
extension=dom
extension=zip
```

Restart Apache after editing `php.ini`.

### Option 2: Linux (Apache + PHP)

```bash
sudo git clone <this-repository-url> /var/www/html/WebCrawler
sudo chown -R www-data:www-data /var/www/html/WebCrawler/data
sudo systemctl restart apache2
```

Then open `http://localhost/WebCrawler/`.

The `data/` directory must be writable by the web server user. The database and logs are created there at runtime.

### Optional: Composer packages

Only needed for Symfony Panther headless mode:

```bash
composer install
```

The `vendor/` directory is ignored by git.

---

## Configuration

### `.htaccess` PHP limits

The repository's `.htaccess` sets these values:

```apache
php_value max_execution_time 600
php_value memory_limit 512M
php_value post_max_size 50M
php_value upload_max_filesize 50M
```

Crawl requests can run for a long time, so the execution time is raised to 10 minutes. If Apache does not read `.htaccess` (for example with PHP-FPM or `AllowOverride None`), set the same values in `php.ini` instead.

### Environment variables

| Variable | Purpose |
|---|---|
| `WEBCRAWLER_INSECURE_TLS=1` | Turns off TLS certificate verification for every outbound cURL request. Use only for local testing. See [TLS certificate verification](#tls-certificate-verification). |
| `FFMPEG_PATH` | Full path to `ffmpeg.exe` (or `ffmpeg`) if it is not on `PATH` and not in a project-local `ffmpeg-*/bin` folder. |

### Crawl limits in the code

| Setting | Value | Where |
|---|---|---|
| Max pages per crawl | 100 (server cap; higher requests are reduced and reported in `warnings`) | `api/crawl.php` |
| Default max depth | 2 | `api/crawl.php` |
| Default max pages | 50 | `api/crawl.php` |
| Request timeout | 15 seconds | `api/crawl.php` |
| Parallel connections | 5 | `api/crawl.php` |
| Stream download time limit | 300 seconds | `includes/streaming_downloader.php` |

---

## Using the web interface

1. Open `http://localhost/WebCrawler/`.
2. Enter the start URL, for example `https://example.com`.
3. Choose the file types to look for, or type custom extensions.
4. Set the depth (1–5) and the maximum number of pages (10–100).
5. Start the crawl and watch the progress indicator.
6. When the crawl finishes, filter the results by category or search term. You can preview files, download them one at a time, or select several and download them as a ZIP.

If the server ignored an option you asked for (queue or login), the interface shows a notification that says so.

---

## API reference

All endpoints are plain HTTP endpoints without authentication. Do not expose them to untrusted users. See [Security](#security).

Errors are returned as JSON with an `error` key. Several endpoints return HTTP 200 for these errors, so check for the `error` key rather than only the HTTP status code.

### `POST api/crawl.php`: start a crawl

Request body (JSON):

```json
{
  "url": "https://example.com",
  "extensions": [".pdf", ".jpg", ".mp4"],
  "maxDepth": 2,
  "maxPages": 50,
  "use_headless": false,
  "strict_media_origin": false,
  "use_proxy": false,
  "proxy_list": "host1:8080\nhost2:3128:user:pass"
}
```

| Field | Required | Default | Meaning |
|---|---|---|---|
| `url` | yes | — | Start URL. Requests without it return `{"error": "Missing URL"}`. |
| `extensions` | no | `[]` | Dotted extensions to collect. |
| `maxDepth` | no | `2` | Link depth to follow. |
| `maxPages` | no | `50` | Pages to visit. Capped at 100. |
| `use_headless` | no | `false` | Use the headless browser. Sites in the built-in JavaScript-heavy list turn this on automatically. |
| `strict_media_origin` | no | `false` | Headless mode only. Keeps results focused on media captured from the page. |
| `use_proxy`, `proxy_list` | no | `false`, empty | When `use_proxy` is true, `proxy_list` is read one entry per line in the form `host:port` or `host:port:user:pass`. |
| `use_queue`, `login` | no | — | **Not available.** Ignored, and reported in `warnings`. |

Response (success):

```json
{
  "success": true,
  "sid": "<session id>",
  "files": [
    {
      "url": "https://example.com/file.pdf",
      "filename": "file.pdf",
      "extension": ".pdf",
      "size": "Unknown",
      "category": "documents"
    }
  ],
  "stats": {
    "visited_urls": 30,
    "found_files": 1,
    "headless": { "enabled": false, "mode": "none" },
    "errors": []
  },
  "warnings": ["maxPages was capped at 100."]
}
```

`warnings` is present only when something was ignored or adjusted. `size` is `"Unknown"` here; use `api/get_file_sizes.php` to fill in sizes. A `debug` object is included only for requests from localhost that also pass `?debug=1`.

### `GET api/crawl.php?progress=1`: check progress

Progress is read with the same `PHPSESSID` cookie that the browser received from the crawl request, so call it from the same browser session.

```json
{
  "current": 25,
  "total": 50,
  "status": "crawling",
  "time_elapsed": 45,
  "sid": "<session id>"
}
```

When no crawl is running for the session, the response is `{"current": 0, "total": 0, "status": "idle", "sid": ...}`.

### `POST api/get_file_sizes.php`: look up file sizes

```json
{ "urls": ["https://example.com/file.pdf", "https://example.com/image.jpg"] }
```

Up to 50 URLs are processed per request. The response is `{"sizes": {"<url>": "<human-readable size or Unknown>"}}`.

### `POST api/download_single.php`: download one file

```json
{ "url": "https://example.com/file.pdf", "filename": "optional-name.pdf" }
```

On success the response is the file itself, with a `Content-Disposition: attachment` header. The file is downloaded to a temporary file on the server first. Small responses (under 200 KB) that look like HTML or JSON, such as a blocked page, are rejected with HTTP 502 and a JSON error. Filenames are cleaned of quotes, slashes, semicolons, and control characters.

### `POST api/download_zip.php`: download several files as a ZIP

```json
{ "files": ["https://example.com/file1.pdf", "https://example.com/image.jpg"] }
```

On success the response is a ZIP archive. Files that fail to download are skipped. If none succeed, the response is HTTP 500 with `{"error": "...", "failed": [...]}`.

### `GET` or `POST api/download_stream.php`: download an HLS or DASH stream

Parameters: `url` (required), `format` (`mp4` by default, or `mp3`), and `info=1` to return stream details as JSON instead of downloading. Only `http` and `https` URLs ending in `.m3u8` or `.mpd` (or with a `format=m3u8` or `format=mpd` query parameter) are accepted. This endpoint needs FFmpeg. See [FFmpeg for streams](#ffmpeg-for-streams-optional).

---

## Headless browser mode (optional)

Some sites build their content with JavaScript. For those pages, the crawler can use a headless Chromium browser. Headless mode runs when a request sets `use_headless`, or when the start URL matches the built-in JavaScript-heavy site list. When it runs, the crawler picks a browser in this order:

1. **Symfony Panther**, if `symfony/panther` is installed through Composer.
2. **Puppeteer**, if the Puppeteer server is answering on `127.0.0.1:9222`, or if Node.js and the bridge script are available.
3. **cURL**, if neither is available. The crawler then logs a message and fetches pages without JavaScript.

Headless mode turns off parallel fetching.

### Setting up Puppeteer

Run these once in the repository root:

```bash
npm install
```

On Windows, start the server with the provided script and stop it with the other:

```bat
start_puppeteer.bat
stop_puppeteer.bat
```

`start_puppeteer.bat` does the following:

- If a healthy server already answers at `http://127.0.0.1:9222/health`, it reports that and exits.
- If another process holds port 9222 and is not a healthy Puppeteer server, it reports a conflict and does **not** stop that process.
- Otherwise it starts `includes/puppeteer_server.js` hidden, writes the process ID to `data/puppeteer.pid`, and waits for the health check.

`stop_puppeteer.bat` stops only the process whose ID is in `data/puppeteer.pid`, and only after confirming that the process command line contains `puppeteer_server.js`. It never stops a process by port number or by name.

On Linux or macOS, run `node includes/puppeteer_server.js` yourself and stop it with Ctrl+C.

The server binds only to `127.0.0.1`. It starts Chromium with `--no-sandbox`, so treat it as a tool for trusted pages only. The server no longer disables browser web security, but that does not make it safe for untrusted sites.

If no server is running, the crawler can still start a Node process per request through `includes/puppeteer_bridge.js`. That is slower.

### Symfony Panther

Install with `composer install`. Panther also needs a browser driver that matches your installed Chrome or Chromium. Panther is not tested as part of this project.

---

## FFmpeg for streams (optional)

FFmpeg is needed only for `.m3u8` (HLS) and `.mpd` (DASH) downloads through `api/download_stream.php`. Everything else works without it.

FFmpeg is found in this order:

1. The `FFMPEG_PATH` environment variable.
2. A project-local folder matching `ffmpeg-*/bin/ffmpeg.exe`.
3. `ffmpeg` on `PATH`.

On Windows, `install_ffmpeg.bat` downloads a FFmpeg build from gyan.dev into `C:\ffmpeg` and can add `C:\ffmpeg\bin` to your user `PATH`. It does not change your `PATH` when the existing value is too long for `setx`, and it prints the manual step instead. After it finishes, open a new terminal and run `ffmpeg -version` and `ffprobe -version`. The script also runs both tools by full path to check them.

FFmpeg runs with `-protocol_whitelist http,https,tcp,tls,crypto`, so a playlist cannot make it read local files. Each run is limited to 300 seconds. On Windows, FFmpeg is stopped only if it exceeds that limit, and only its own process tree is stopped.

---

## Database

Each crawl writes to a SQLite database at `data/crawler.db`. The file and its `-wal` and `-shm` companions are created automatically the first time a crawl runs. Data accumulates across crawls.

The schema is described in [data/README.md](data/README.md). In short, the database has three tables: `urls` (visited pages), `files` (found files), and `sessions` (one row per crawl). It uses WAL mode with a five-second busy timeout.

To reset the database, stop Apache first, then delete `data/crawler.db`, `data/crawler.db-wal`, and `data/crawler.db-shm`. Apache will recreate them on the next crawl.

---

## Logs and runtime files

| File or location | What it is | Lifetime |
|---|---|---|
| `data/crawl_debug.log` | Detailed crawl log written by the API and the crawler | Moved to `data/crawl_debug.log.1` once it passes 1 MB. Only one previous copy is kept. |
| Apache error log | PHP `error_log` messages (`[CRAWLER]`, `[STREAM]`, `[HTTP]`, and others) | Managed by Apache. |
| `data/puppeteer.pid` | Process ID of the Puppeteer server started by `start_puppeteer.bat` | Removed by `stop_puppeteer.bat`. Git-ignored. |
| `data/logs/` | Output of `start_puppeteer.bat` | Git-ignored. Not rotated. |
| System temp folder: `crawler_progress_<sid>.json` | Progress for one crawl session | Overwritten during the crawl. |
| System temp folder: `crawler_cookies_*.txt`, `headless_cookies.txt` | Cookie jars used by cURL and headless requests | Left in place; safe to delete when no crawl is running. |
| System temp folder: `wc_dl_*`, `dl_*.tmp`, `stream_*`, `crawler_*.zip` | Temporary downloads | Deleted after each request. |

The database, log, and PID files in `data/` are excluded from git by `.gitignore`. `data/README.md` and `data/.gitkeep` are tracked.

---

## TLS certificate verification

Outbound HTTPS requests verify the server's certificate by default. The helper in `includes/http_safety.php` applies this to every cURL request in the app. On XAMPP, the bundled CA file is used.

If you see certificate errors, fix the cause (for example, an out-of-date CA bundle or a corporate proxy that needs its CA added to the bundle). Do not disable verification for normal use.

For local testing only, you can disable verification with `WEBCRAWLER_INSECURE_TLS=1`. The app then logs a warning on each request. Do not set this on a machine that handles real data, and do not use it when crawling sites you do not control.

---

## Security

**Read this section before exposing the app to anyone.**

- **No authentication.** The API and the web interface have no login. Anyone who can reach the server can start crawls and download files through it. Keep it on `localhost`, or put it behind your own authentication and network controls.
- **Outbound requests can reach private networks.** The crawl and download endpoints fetch whatever URL the caller gives them. The code does not block requests to `localhost`, private IP ranges, or cloud metadata addresses. This makes the server a potential way to reach internal services (server-side request forgery). Do not run it on a network where that matters.
- **Scheme restrictions.** Outbound cURL requests are limited to `http` and `https`, so `file:` and other schemes are refused. Stream downloads are limited the same way.
- **Debug output.** `?debug=1` adds diagnostic data only for requests from `127.0.0.1` or `::1`.
- **Filenames** returned in `Content-Disposition` headers are cleaned of quotes, slashes, semicolons, and control characters.
- **Puppeteer server** binds to `127.0.0.1` only and runs Chromium with `--no-sandbox`. Use it only for trusted pages.
- **Stored data.** Crawl results (URLs and file names) are kept in `data/crawler.db` and in `data/crawl_debug.log`. Those files may contain URLs with private paths or query strings. Delete them when you no longer need them.

---

## Troubleshooting

| Problem | What to check |
|---|---|
| `ZIP extension not enabled` | Enable `extension=zip` in `php.ini` and restart Apache. |
| Crawl returns nothing and `files` is empty | Check the file type selection. Check `data/crawl_debug.log` and the Apache error log. Confirm the site is reachable from the server. |
| Crawl fails or times out | Lower `maxPages` or the depth. Check that `max_execution_time` is 600 and that the site responds quickly. |
| `Memory limit` or `Allowed memory size` errors | Raise `memory_limit` in `.htaccess` or `php.ini`. |
| `curl error 60` or `SSL certificate problem` | Certificate verification failed. Fix the CA bundle. Do not disable verification for normal use. See [TLS certificate verification](#tls-certificate-verification). |
| Download returns HTTP 502 `Upstream did not return a media file` | The source returned an HTML or JSON page, usually because the file is blocked, expired, or needs a login. |
| `FFmpeg not installed` | Install FFmpeg or set `FFMPEG_PATH`. See [FFmpeg for streams](#ffmpeg-for-streams-optional). |
| `start_puppeteer.bat` reports a conflict on port 9222 | Another process is using the port. Find it with `netstat -ano \| findstr :9222`. The script does not stop it for you. |
| `start_puppeteer.bat` says Puppeteer is not installed | Run `npm install` in the repository root. |
| Database or log write errors | Make sure the web server user can write to `data/`. |

---

## Responsible use

- Crawl only sites you own or have permission to crawl.
- The crawler does **not** check robots.txt by default. Check the site's robots.txt and terms of service yourself before you crawl.
- Keep crawls small. Use low depth and page limits, and avoid repeated crawls of the same site.
- Follow the laws that apply to you, including copyright and data protection rules.

This tool is for educational and legitimate purposes. You are responsible for how you use it.

---

## Project structure

```
WebCrawler/
├── index.html                    Web interface
├── .htaccess                     PHP limits for Apache
├── api/
│   ├── crawl.php                 Start a crawl; progress endpoint
│   ├── download_single.php       Download one file
│   ├── download_zip.php          Download several files as a ZIP
│   ├── download_stream.php       Download HLS/DASH streams (FFmpeg)
│   └── get_file_sizes.php        Look up file sizes
├── assets/
│   ├── css/style.css             Interface styles
│   └── js/script.js              Interface logic
├── includes/
│   ├── crawler.php               Crawl engine (WebCrawler class)
│   ├── database.php              SQLite storage (CrawlerDatabase class)
│   ├── http_safety.php           Shared cURL settings (protocols, TLS)
│   ├── multi_curl.php            Parallel cURL fetching
│   ├── headless_browser.php      Headless browser selection and fetching
│   ├── puppeteer_server.js       Persistent Puppeteer server (127.0.0.1:9222)
│   ├── puppeteer_bridge.js       One-shot Puppeteer bridge (Node)
│   ├── smart_filters.php         Heuristic result filters
│   ├── streaming_downloader.php  FFmpeg-based stream downloads
│   └── robots_checker.php        robots.txt parser (not loaded by default)
├── data/
│   ├── README.md                 Database and runtime files
│   └── .gitkeep                  Keeps the folder in git
├── latex/                        LaTeX source file (not part of the application)
├── start_puppeteer.bat           Start the Puppeteer server (Windows)
├── stop_puppeteer.bat            Stop the Puppeteer server it started (Windows)
├── install_ffmpeg.bat            Install FFmpeg (Windows)
├── composer.json / composer.lock Optional PHP dependencies (Symfony Panther)
├── package.json / package-lock.json  Node dependencies (Puppeteer)
└── LICENSE                       MIT License
```

---

## Known limitations

- No authentication, no login support, and no multi-user separation.
- No queue or multi-worker mode.
- The API does not validate the target host, so it can be used for server-side requests to internal addresses. See [Security](#security).
- `respect_robots` is off by default and is not exposed in the UI.
- Crawled pages are fetched and parsed in PHP. Very large sites and very large files are limited by the time and memory settings in `.htaccess`.
- The Puppeteer server runs Chromium with `--no-sandbox`.
- Panther support has not been tested as part of this project.
- Browser and operating-system support has not been tested beyond Windows 11 with XAMPP.
- No automated test suite is included.

---

## License

MIT License. Copyright (c) 2026 WebCrawler. See [LICENSE](LICENSE).
