/**
 * Puppeteer Persistent Browser Server
 * Keeps Chrome running in background, reuses browser instances
 * Solves: 3-10s startup overhead per URL
 * 
 * Usage:
 * 1. Start server: node puppeteer_server.js
 * 2. PHP sends requests to http://localhost:9222/fetch
 */

const puppeteer = require('puppeteer');
const http = require('http');

const PORT = 9222;
const MAX_PAGES = 5; // Max concurrent pages
const PAGE_TIMEOUT = 60000;

const DEBUG_ZING = process.env.DEBUG_ZING === '1';
const ZING_MAX_SONGS_DEFAULT = 30;
const NCT_WAIT_AFTER_PLAY_MS = 2500;
const MAX_JSON_PARSE_BYTES = 2 * 1024 * 1024; // 2MB safety guard
const NCT_MAX_SONGS_DEFAULT = 20;

let browser = null;
let pagePool = [];
let requestQueue = [];

// Initialize browser once
async function initBrowser() {
    console.log('[SERVER] Launching browser...');
    browser = await puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-web-security', // Allow CORS
            '--autoplay-policy=no-user-gesture-required',
            '--window-size=1920,1080'
        ]
    });
    
    // Pre-create page pool
    for (let i = 0; i < MAX_PAGES; i++) {
        const page = await browser.newPage();
        await page.setViewport({ width: 1920, height: 1080 });
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        pagePool.push({ page, busy: false });
    }
    
    console.log(`[SERVER] Browser ready with ${MAX_PAGES} pages in pool`);
}

// Get available page from pool
async function getAvailablePage() {
    // Find free page
    let pageObj = pagePool.find(p => !p.busy);
    
    if (pageObj) {
        pageObj.busy = true;
        return pageObj;
    }
    
    // Wait for page to become available
    return new Promise((resolve) => {
        const interval = setInterval(() => {
            pageObj = pagePool.find(p => !p.busy);
            if (pageObj) {
                clearInterval(interval);
                pageObj.busy = true;
                resolve(pageObj);
            }
        }, 100);
    });
}

// Release page back to pool
function releasePage(pageObj) {
    pageObj.busy = false;
}

// Fetch page with network capture
async function fetchPage(options) {
    const pageObj = await getAvailablePage();
    const { page } = pageObj;
    let requestHandler = null;
    let responseHandler = null;
    
    try {
        const capturedMedia = {
            direct: [],
            streaming: [],
            all: []
        };

        async function tryClickFirst(selectors, timeoutMs = 3000) {
            for (const sel of selectors) {
                try {
                    const handle = await page.$(sel);
                    if (handle) {
                        await handle.click({ delay: 30 });
                        return sel;
                    }
                    await page.waitForSelector(sel, { timeout: timeoutMs, visible: true }).then(async () => {
                        await page.click(sel, { delay: 30 });
                    });
                    return sel;
                } catch (_) {}
            }
            return null;
        }

        async function captureAudioFromDom(sourceLabel, opts = {}) {
            try {
                const includeScriptScan = !!opts.includeScriptScan;
                const scriptNeedle = (typeof opts.scriptNeedle === 'string' && opts.scriptNeedle.trim() !== '') ? opts.scriptNeedle.trim() : null;
                const urls = await page.evaluate((includeScriptScan, scriptNeedle) => {
                    const out = [];
                    const a = document.querySelector('audio');
                    if (a) {
                        const u = a.currentSrc || a.src;
                        if (u) out.push(u);
                        // Sometimes sources are in <source>
                        a.querySelectorAll('source').forEach(s => {
                            const su = s.src || s.getAttribute('src');
                            if (su) out.push(su);
                        });
                    }

                    // NOTE: Script scanning can massively over-capture (recommended lists, ads, unrelated tracks).
                    // Only enable when necessary.
                    if (includeScriptScan) {
                        const re = /https?:\/\/[^"'\s]+\.(?:mp3|m4a|aac|flac|wav|ogg|opus)(?:\?[^"'\s]*)?/gi;
                        document.querySelectorAll('script').forEach(sc => {
                            const t = sc.textContent || '';
                            if (scriptNeedle && !t.includes(scriptNeedle)) return;
                            let m;
                            while ((m = re.exec(t)) !== null) out.push(m[0]);
                        });
                    }
                    return Array.from(new Set(out));
                }, includeScriptScan, scriptNeedle);

                let added = 0;

                for (const u of urls) {
                    if (!capturedMedia.all.includes(u)) {
                        capturedMedia.direct.push({ type: 'audio', url: u, contentType: 'audio/*', source: sourceLabel, page: captureContext.pageUrl });
                        capturedMedia.all.push(u);
                        added++;
                        console.log('[CAPTURE] 🎵 DOM Audio:', u.substring(0, 120));
                    }
                }

                return added;
            } catch (_) {}

            return 0;
        }

        // ZingMP3 state (used to handle album/playlist pages reliably)
        const zingState = {
            songLinksFromApi: new Set(),
            sawPlaylistApi: false
        };

        const captureContext = {
            pageUrl: options.url
        };
        
        // Setup network interception
        await page.setRequestInterception(true);
        
        requestHandler = (request) => request.continue();
        responseHandler = async (response) => {
            const url = response.url();
            const contentType = response.headers()['content-type'] || '';
            
            if (DEBUG_ZING && (url.includes('zingmp3.vn') || url.includes('zing.vn'))) {
                console.log('[DEBUG] ZingMP3 request:', url.substring(0, 120));
            }
            
            // Skip ads
            if (url.includes('advertisement') || url.includes('/ads/') || url.includes('google')) {
                return;
            }
            
            // API Interception - only parse JSON when useful (avoid huge CPU/memory on noisy endpoints)
            const isZingApi = url.includes('zingmp3.vn/api') || url.includes('zing.vn/api');
            if (isZingApi) {
                if (DEBUG_ZING) {
                    console.log('[DEBUG] ZingMP3 API call detected:', url);
                }

                const shouldParse =
                    url.includes('/song/get/streaming') ||
                    url.includes('/page/get/playlist') ||
                    url.includes('/page/get/album') ||
                    url.includes('/playlist/get/section');

                if (!shouldParse) {
                    return;
                }

                try {
                    const text = await response.text();

                    if (text.length > MAX_JSON_PARSE_BYTES) {
                        if (DEBUG_ZING) {
                            console.log('[DEBUG] Skipping large JSON body:', text.length, 'bytes');
                        }
                        return;
                    }

                    if (DEBUG_ZING) {
                        console.log('[DEBUG] Response text length:', text.length);
                    }
                    
                    const json = JSON.parse(text);

                    if (DEBUG_ZING) {
                        console.log('[DEBUG] JSON parsed, has data:', !!json.data);
                    }
                    
                    // Capture playlist JSON for album/playlist crawling
                    if (url.includes('/page/get/playlist') || url.includes('/page/get/album')) {
                        zingState.sawPlaylistApi = true;
                        const collectSongLinks = (obj) => {
                            if (!obj) return;
                            if (typeof obj === 'string') {
                                if (obj.includes('/bai-hat/')) {
                                    try {
                                        let u = obj;
                                        if (u.startsWith('//')) u = 'https:' + u;
                                        if (u.startsWith('/')) u = 'https://zingmp3.vn' + u;
                                        if (u.startsWith('http')) zingState.songLinksFromApi.add(u);
                                    } catch (_) {}
                                }
                                return;
                            }
                            if (Array.isArray(obj)) {
                                obj.forEach(collectSongLinks);
                                return;
                            }
                            if (typeof obj === 'object') {
                                Object.values(obj).forEach(collectSongLinks);
                            }
                        };
                        collectSongLinks(json);

                        if (DEBUG_ZING) {
                            console.log('[ZING] Playlist API captured, song links so far:', zingState.songLinksFromApi.size);
                        }
                    }

                    // Check for streaming data in response
                    if (json && json.data) {
                        const data = json.data;
                        
                        // Special handling for /song/get/streaming - Direct MP3 URLs
                        if (url.includes('/song/get/streaming')) {
                            // ZingMP3 structure: { "128": "url", "320": "url" }
                            ['128', '320', '500', 'lossless'].forEach(quality => {
                                const audioUrl = data[quality];
                                if (audioUrl && typeof audioUrl === 'string' && audioUrl.startsWith('http')) {
                                    if (!capturedMedia.all.includes(audioUrl)) {
                                        capturedMedia.direct.push({
                                            type: 'audio',
                                            url: audioUrl,
                                            contentType: 'audio/mpeg',
                                            source: 'zing-streaming',
                                            page: captureContext.pageUrl
                                        });
                                        capturedMedia.all.push(audioUrl);
                                        console.log('[CAPTURE] 🎵 ZingMP3 Direct MP3:', audioUrl.substring(0, 100));
                                    }
                                }
                            });
                            return; // done
                        }
                        
                        // Extract .m3u8 URLs from various possible structures (for other sites)
                        const extractUrls = (obj, urls = [], depth = 0) => {
                            if (depth > 10) return urls; // Prevent infinite recursion
                            
                            if (typeof obj === 'string') {
                                // Match any URL with .m3u8 or streaming patterns
                                if (obj.includes('.m3u8') || obj.includes('/hls/') || 
                                    (obj.includes('http') && obj.includes('stream'))) {
                                    urls.push(obj);
                                }
                            } else if (Array.isArray(obj)) {
                                obj.forEach(item => extractUrls(item, urls, depth + 1));
                            } else if (typeof obj === 'object' && obj !== null) {
                                Object.values(obj).forEach(val => extractUrls(val, urls, depth + 1));
                            }
                            return urls;
                        };
                        
                        const streamUrls = extractUrls(data);
                        if (DEBUG_ZING) {
                            console.log('[DEBUG] Extracted', streamUrls.length, 'streaming URLs from API response');
                        }
                        
                        streamUrls.forEach(streamUrl => {
                            if (!capturedMedia.all.includes(streamUrl)) {
                                capturedMedia.streaming.push({ type: 'HLS', url: streamUrl });
                                capturedMedia.all.push(streamUrl);
                                console.log('[CAPTURE] 🎵 Streaming URL:', streamUrl.substring(0, 100));
                            }
                        });
                    }
                } catch (e) {
                    if (DEBUG_ZING) {
                        console.log('[DEBUG] API parse error:', e.message);
                    }
                }
            }

            // Nhaccuatui: many pages load track URLs via JSON APIs; try to parse and extract direct audio links
            const isNct = url.includes('nhaccuatui.com') || url.includes('nct.vn') || url.includes('nhaccuatui.vn');
            const isJson = contentType.includes('application/json') || contentType.includes('text/json') || contentType.includes('application/javascript');
            if (isNct && isJson) {
                try {
                    const text = await response.text();
                    if (text.length > MAX_JSON_PARSE_BYTES) return;

                    // Try strict JSON parse first
                    let json = null;
                    try {
                        json = JSON.parse(text);
                    } catch (_) {
                        // Fallback: scan raw text for audio URLs
                    }

                    const found = [];
                    const addUrl = (u) => {
                        if (!u || typeof u !== 'string') return;
                        if (!u.startsWith('http')) return;
                        if (!/(\.mp3|\.m4a|\.aac|\.flac|\.wav|\.ogg|\.opus)(\?|$)/i.test(u)) return;
                        found.push(u);
                    };

                    const walk = (obj, depth = 0) => {
                        if (depth > 10 || obj == null) return;
                        if (typeof obj === 'string') {
                            addUrl(obj);
                            return;
                        }
                        if (Array.isArray(obj)) {
                            obj.forEach(x => walk(x, depth + 1));
                            return;
                        }
                        if (typeof obj === 'object') {
                            Object.values(obj).forEach(x => walk(x, depth + 1));
                        }
                    };

                    if (json) {
                        walk(json);
                    } else {
                        // raw scan
                        const re = /https?:\/\/[^"'\s]+\.(?:mp3|m4a|aac|flac|wav|ogg|opus)(?:\?[^"'\s]*)?/gi;
                        let m;
                        while ((m = re.exec(text)) !== null) {
                            addUrl(m[0]);
                        }
                    }

                    for (const audioUrl of found) {
                        if (!capturedMedia.all.includes(audioUrl)) {
                            capturedMedia.direct.push({ type: 'audio', url: audioUrl, contentType: 'audio/*', source: 'nct-json', page: captureContext.pageUrl });
                            capturedMedia.all.push(audioUrl);
                            console.log('[CAPTURE] 🎵 NCT Audio:', audioUrl.substring(0, 120));
                        }
                    }
                } catch (_) {}
            }
            
            // HLS Streaming
            if (url.includes('.m3u8') || contentType.includes('mpegURL')) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.streaming.push({ type: 'HLS', url });
                    capturedMedia.all.push(url);
                    console.log('[CAPTURE] 🎵 HLS:', url);
                }
            }
            // DASH Streaming
            else if (url.includes('.mpd') || contentType.includes('dash+xml')) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.streaming.push({ type: 'DASH', url });
                    capturedMedia.all.push(url);
                    console.log('[CAPTURE] 🎵 DASH:', url);
                }
            }
            // Direct Audio
            else if (contentType.includes('audio/') || url.match(/\.(mp3|m4a|aac|wav|flac|ogg)(\?|$)/i)) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.direct.push({ type: 'audio', url, contentType, source: 'network-audio', page: captureContext.pageUrl });
                    capturedMedia.all.push(url);
                    console.log('[CAPTURE] 🎵 Audio:', url);
                }
            }
            // Direct Video
            else if (contentType.includes('video/') || url.match(/\.(mp4|webm|mkv|mov)(\?|$)/i)) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.direct.push({ type: 'video', url, contentType });
                    capturedMedia.all.push(url);
                    console.log('[CAPTURE] 🎬 Video:', url);
                }
            }
        };
        
        page.on('request', requestHandler);
        page.on('response', responseHandler);
        
        // Navigate to page
        await page.goto(options.url, {
            waitUntil: 'networkidle2',
            timeout: options.timeout || PAGE_TIMEOUT
        });
        
        // Wait extra time for JS
        const waitTime = options.wait_time || 2000;
        await new Promise(r => setTimeout(r, waitTime));
        
        // Execute custom JS
        if (options.execute_js) {
            for (const script of options.execute_js) {
                await page.evaluate(script);
            }
        }
        
        // Scroll if needed
        if (options.scroll_to_bottom) {
            await page.evaluate(() => {
                window.scrollTo(0, document.body.scrollHeight);
            });
            await new Promise(r => setTimeout(r, 1000));
        }

        // Nhaccuatui: try to trigger playback to expose audio URLs
        if (options.url.includes('nhaccuatui.com')) {
            try {
                console.log('[NCT] Trying to trigger playback...');
                const clicked = await tryClickFirst([
                    '[data-action="play"]',
                    '.btn-play',
                    '.icon-play',
                    '.player_play',
                    '.btnPlay',
                    'button[aria-label*="Play" i]',
                    'button[title*="Play" i]',
                    'button[aria-label*="Phát" i]',
                    'button[title*="Phát" i]',
                    '[class*="play" i]'
                ]);
                if (clicked) {
                    console.log('[NCT] Clicked:', clicked);
                } else {
                    // Fallback: attempt to play any audio element
                    await page.evaluate(() => {
                        const a = document.querySelector('audio');
                        if (a) {
                            a.muted = true;
                            a.play?.().catch?.(() => {});
                        }
                    });
                }
                await new Promise(r => setTimeout(r, NCT_WAIT_AFTER_PLAY_MS));

                // If network capture missed, try reading from DOM
                const added = await captureAudioFromDom('nct-dom');
                if (!added) {
                    const m = String(captureContext.pageUrl || '').match(/\/song\/([^/?#]+)/i);
                    const needle = m ? m[1] : null;
                    await captureAudioFromDom('nct-dom', { includeScriptScan: true, scriptNeedle: needle });
                }
            } catch (_) {}
        }

        // TikTok: allow more time + scroll a bit to trigger media requests
        if (options.url.includes('tiktok.com')) {
            try {
                console.log('[TIKTOK] Settling + scrolling...');
                await new Promise(r => setTimeout(r, 2500));
                for (let i = 0; i < 3; i++) {
                    await page.evaluate(() => window.scrollBy(0, window.innerHeight));
                    await new Promise(r => setTimeout(r, 1200));
                }
            } catch (_) {}
        }
        
        // Wait extra for ZingMP3 AJAX
        if (options.url.includes('zingmp3') || options.url.includes('zing.vn')) {
            console.log('[ZING] Triggering play buttons to load streaming URLs...');
            
            // Extract embedded URLs from page first
            const embeddedUrls = await page.evaluate(() => {
                const urls = [];
                
                // Check all script tags for URLs
                document.querySelectorAll('script').forEach(script => {
                    const text = script.textContent;
                    
                    // Match .m3u8 URLs
                    const m3u8Matches = text.match(/https?:\/\/[^"'\s]+\.m3u8[^"'\s]*/g);
                    if (m3u8Matches) {
                        urls.push(...m3u8Matches);
                    }
                    
                    // Match streaming object patterns
                    const streamPatterns = [
                        /"128":\s*"([^"]+)"/g,
                        /"320":\s*"([^"]+)"/g,
                        /"streaming":\s*"([^"]+)"/g,
                        /"link":\s*"([^"]+\.m3u8[^"]*)"/g
                    ];
                    
                    streamPatterns.forEach(pattern => {
                        let match;
                        while ((match = pattern.exec(text)) !== null) {
                            if (match[1].includes('http')) {
                                urls.push(match[1]);
                            }
                        }
                    });
                });
                
                return urls;
            });
            
            console.log('[ZING] Extracted', embeddedUrls.length, 'URLs from HTML/JS');
            embeddedUrls.forEach(url => {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.streaming.push({ type: 'HLS', url });
                    capturedMedia.all.push(url);
                    console.log('[CAPTURE] 🎵 Embedded:', url.substring(0, 100));
                }
            });
            
            const isAlbumOrPlaylist = /\/\/(?:www\.)?zingmp3\.vn\/(album|playlist)\//i.test(options.url);
            const maxSongs = Number.isFinite(options.zing_max_songs) ? options.zing_max_songs : ZING_MAX_SONGS_DEFAULT;

            // For album/playlist: DOM selectors are brittle; prefer extracting song links then visiting each song page
            if (isAlbumOrPlaylist) {
                const linksFromDom = await page.evaluate(() => {
                    const out = [];
                    document.querySelectorAll('a[href*="/bai-hat/"]').forEach(a => {
                        const href = a.getAttribute('href');
                        if (!href) return;
                        // Usually song pages end with .html
                        if (href.includes('/bai-hat/')) out.push(href);
                    });
                    return out;
                });

                // Zing often embeds song links inside inline JSON/JS (not real <a href> nodes)
                const linksFromScripts = await page.evaluate(() => {
                    const out = [];
                    const re = /(https?:\/\/zingmp3\.vn)?(\/bai-hat\/[^"'\s\\]+?\.html)/g;
                    document.querySelectorAll('script').forEach(script => {
                        const text = script.textContent || '';
                        let match;
                        while ((match = re.exec(text)) !== null) {
                            out.push(match[0]);
                        }
                    });
                    return out;
                });

                const normalizeSongUrl = (u) => {
                    if (!u) return null;
                    if (u.startsWith('//')) return 'https:' + u;
                    if (u.startsWith('/')) return 'https://zingmp3.vn' + u;
                    if (u.startsWith('http')) return u;
                    return null;
                };

                let candidateLinks;
                if (zingState.songLinksFromApi.size > 0) {
                    candidateLinks = Array.from(zingState.songLinksFromApi);
                } else {
                    const allLinks = new Set();
                    linksFromDom.forEach(u => allLinks.add(u));
                    linksFromScripts.forEach(u => allLinks.add(u));
                    candidateLinks = Array.from(allLinks);
                }

                const uniqueSongLinks = Array.from(new Set(candidateLinks
                    .map(normalizeSongUrl)
                    .filter(Boolean)
                    .filter(u => u.includes('/bai-hat/'))));

                console.log(`[ZING] Album/playlist detected. DOM links: ${linksFromDom.length}, Script links: ${linksFromScripts.length}, API links: ${zingState.songLinksFromApi.size}, using: ${zingState.songLinksFromApi.size > 0 ? 'API' : 'DOM/scripts'}, unique: ${uniqueSongLinks.length}`);

                let visited = 0;
                for (const songUrl of uniqueSongLinks.slice(0, maxSongs)) {
                    try {
                        visited++;
                        console.log(`[ZING] Visiting song ${visited}/${Math.min(uniqueSongLinks.length, maxSongs)}: ${songUrl}`);

                        captureContext.pageUrl = songUrl;

                        await page.goto(songUrl, {
                            waitUntil: 'networkidle2',
                            timeout: options.timeout || PAGE_TIMEOUT
                        });

                        // Small settle time
                        await new Promise(r => setTimeout(r, 1200));

                        // Try click play to trigger /song/get/streaming (keep runtime bounded)
                        for (let attempt = 1; attempt <= 2; attempt++) {
                            await page.evaluate(() => {
                                const selectors = [
                                    '.zm-btn-play',
                                    '.fn-play',
                                    'button[aria-label*="Phát"]',
                                    'button[title*="Phát" i]',
                                    '[role="button"][title*="Phát" i]'
                                ];

                                for (const sel of selectors) {
                                    const btn = document.querySelector(sel);
                                    if (btn) {
                                        btn.click();
                                        return;
                                    }
                                }
                            });

                            await new Promise(r => setTimeout(r, 1800));
                            const hasStreaming = capturedMedia.direct.some(x => x && typeof x === 'object' && x.source === 'zing-streaming');
                            if (hasStreaming) break;

                            if (DEBUG_ZING) {
                                console.log(`[ZING] No zing-streaming MP3 yet (attempt ${attempt}/3)`);
                            }
                        }
                    } catch (e) {
                        console.log('[ZING] Song visit error:', e.message);
                    }
                }

                console.log(`[ZING] Done visiting ${visited} song pages. Captured so far: ${capturedMedia.direct.length} direct files`);
            } else {
                // Song page / other Zing pages: just try a quick play click
                captureContext.pageUrl = options.url;
                for (let attempt = 1; attempt <= 2; attempt++) {
                    await page.evaluate(() => {
                        const selectors = [
                            '.zm-btn-play',
                            '.fn-play',
                            'button[aria-label*="Phát"]',
                            'button[title*="Phát" i]',
                            '[role="button"][title*="Phát" i]'
                        ];
                        for (const sel of selectors) {
                            const btn = document.querySelector(sel);
                            if (btn) {
                                btn.click();
                                return;
                            }
                        }
                    });
                    await new Promise(r => setTimeout(r, 1800));
                    const hasStreaming = capturedMedia.direct.some(x => x && typeof x === 'object' && x.source === 'zing-streaming');
                    if (hasStreaming) break;
                    if (DEBUG_ZING) {
                        console.log(`[ZING] No zing-streaming MP3 yet (attempt ${attempt}/3)`);
                    }
                }
            }
        }

        // Nhaccuatui playlist: visit track links and trigger playback for each
        if (options.url.includes('nhaccuatui.com') && /\/playlist\//i.test(options.url)) {
            const maxSongs = Number.isFinite(options.nct_max_songs) ? options.nct_max_songs : NCT_MAX_SONGS_DEFAULT;

            const linksFromDom = await page.evaluate(() => {
                const out = [];
                document.querySelectorAll('a[href]').forEach(a => {
                    const href = a.getAttribute('href');
                    if (!href) return;
                    if (href.includes('/bai-hat/') || href.includes('/song/')) out.push(href);
                });
                return out;
            });

            const linksFromScripts = await page.evaluate(() => {
                const out = [];
                const re = /(https?:\/\/www\.nhaccuatui\.com)?(\/(?:bai-hat|song)\/[^"'\s\\]+?\.html)/g;
                document.querySelectorAll('script').forEach(script => {
                    const text = script.textContent || '';
                    let match;
                    while ((match = re.exec(text)) !== null) {
                        out.push(match[0]);
                    }
                });
                return out;
            });

            const normalize = (u) => {
                if (!u) return null;
                if (u.startsWith('//')) return 'https:' + u;
                if (u.startsWith('/')) return 'https://www.nhaccuatui.com' + u;
                if (u.startsWith('http')) return u;
                return null;
            };

            const uniqueTrackLinks = Array.from(new Set([...linksFromDom, ...linksFromScripts]
                .map(normalize)
                .filter(Boolean)
                .filter(u => u.includes('nhaccuatui.com'))));

            console.log(`[NCT] Playlist detected. Track links: ${uniqueTrackLinks.length}, max: ${maxSongs}`);

            let visited = 0;
            for (const trackUrl of uniqueTrackLinks.slice(0, maxSongs)) {
                try {
                    visited++;
                    captureContext.pageUrl = trackUrl;
                    console.log(`[NCT] Visiting track ${visited}/${Math.min(uniqueTrackLinks.length, maxSongs)}: ${trackUrl}`);
                    await page.goto(trackUrl, { waitUntil: 'networkidle2', timeout: options.timeout || PAGE_TIMEOUT });
                    await new Promise(r => setTimeout(r, 1200));

                    // Try to start playback
                    await tryClickFirst([
                        '[data-action="play"]',
                        '.btn-play',
                        '.icon-play',
                        '.player_play',
                        '.btnPlay',
                        'button[aria-label*="Play" i]',
                        'button[title*="Play" i]',
                        'button[aria-label*="Phát" i]',
                        'button[title*="Phát" i]',
                        '[class*="play" i]'
                    ]);

                    await new Promise(r => setTimeout(r, NCT_WAIT_AFTER_PLAY_MS));

                    const added = await captureAudioFromDom('nct-dom');
                    if (!added) {
                        const m = String(captureContext.pageUrl || '').match(/\/song\/([^/?#]+)/i);
                        const needle = m ? m[1] : null;
                        await captureAudioFromDom('nct-dom', { includeScriptScan: true, scriptNeedle: needle });
                    }
                } catch (e) {
                    console.log('[NCT] Track visit error:', e.message);
                }
            }

            console.log(`[NCT] Done visiting ${visited} tracks. Captured so far: ${capturedMedia.direct.length} direct files`);
        }
        
        // Get HTML
        const html = await page.content();
        
        // Log captured media summary
        console.log(`[CAPTURE] Summary: ${capturedMedia.streaming.length} streams, ${capturedMedia.direct.length} direct files`);
        
        // Cleanup listeners
        page.removeListener('request', requestHandler);
        page.removeListener('response', responseHandler);
        
        return {
            success: true,
            html,
            capturedMedia,
            contentLength: html.length
        };
        
    } catch (error) {
        console.error('[SERVER] Fetch error:', error.message);
        return {
            success: false,
            error: error.message
        };
    } finally {
        // Detach this request's listeners on every path so a failed fetch cannot leave handlers on the pooled page.
        if (requestHandler) page.removeListener('request', requestHandler);
        if (responseHandler) page.removeListener('response', responseHandler);
        // Clear page for next use
        try {
            await page.goto('about:blank');
        } catch (e) {}
        
        releasePage(pageObj);
    }
}

// HTTP Server
const server = http.createServer(async (req, res) => {
    // CORS headers
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.setHeader('Content-Type', 'application/json');
    
    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }
    
    if (req.method === 'POST' && req.url === '/fetch') {
        let body = '';
        
        req.on('data', chunk => {
            body += chunk.toString();
        });
        
        req.on('end', async () => {
            try {
                const options = JSON.parse(body);
                
                if (!options.url) {
                    res.writeHead(400);
                    res.end(JSON.stringify({ error: 'Missing url parameter' }));
                    return;
                }
                
                console.log(`[SERVER] Fetching: ${options.url}`);
                const result = await fetchPage(options);
                
                res.writeHead(200);
                res.end(JSON.stringify(result));
                
            } catch (error) {
                res.writeHead(500);
                res.end(JSON.stringify({ error: error.message }));
            }
        });
        
        return;
    }
    
    // Health check
    if (req.url === '/health') {
        const busyPages = pagePool.filter(p => p.busy).length;
        res.writeHead(200);
        res.end(JSON.stringify({
            status: 'ok',
            pages: {
                total: MAX_PAGES,
                busy: busyPages,
                available: MAX_PAGES - busyPages
            }
        }));
        return;
    }
    
    res.writeHead(404);
    res.end(JSON.stringify({ error: 'Not found' }));
});

// Graceful shutdown
process.on('SIGINT', async () => {
    console.log('\n[SERVER] Shutting down...');
    if (browser) {
        await browser.close();
    }
    process.exit(0);
});

// Start server
initBrowser().then(() => {
    // Loopback only: this endpoint drives a browser with web security disabled.
    server.listen(PORT, '127.0.0.1', () => {
        console.log(`[SERVER] Puppeteer server listening on http://localhost:${PORT}`);
        console.log(`[SERVER] Usage: POST to /fetch with JSON: {"url":"https://example.com"}`);
        console.log(`[SERVER] Health check: GET /health`);
    });
}).catch(error => {
    console.error('[SERVER] Failed to start:', error);
    process.exit(1);
});
