# WebCrawler

PHP crawler UI that extracts downloadable audio/video links, including JS-heavy streaming sites via a persistent Puppeteer capture server.

**Key entry points**
- UI: [index.html](index.html)
- Crawl API: [api/crawl.php](api/crawl.php)
- PHP crawler core: [includes/crawler.php](includes/crawler.php)
- Headless bridge: [includes/headless_browser.php](includes/headless_browser.php)
- Puppeteer server: [includes/puppeteer_server.js](includes/puppeteer_server.js)

## Requirements
- XAMPP (Apache + PHP)
- Node.js (for Puppeteer server)
- Optional: Composer (only if you use Panther)
- Optional: FFmpeg (only needed for HLS/DASH conversion)

## Quick start (Windows)
1) Install Node dependencies:
   - `npm install`
2) Start Apache in XAMPP and open:
   - `http://localhost/WebCrawler/`
3) Start the Puppeteer server:
   - Run [start_puppeteer.bat](start_puppeteer.bat)

## FFmpeg (optional)
If you want to download/convert streaming manifests (`.m3u8`/`.mpd`), install FFmpeg:
- Run [install_ffmpeg.bat](install_ffmpeg.bat)

## Packaging (zip/email)
Exclude these folders/files (they are generated or very large):
- `node_modules/`
- `vendor/`
- `data/*.db`
- `data/*.log`
- Any local FFmpeg bundles or extracted archives
