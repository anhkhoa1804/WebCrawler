/**
 * Puppeteer Bridge for PHP
 * This script acts as a bridge between PHP and Puppeteer (Node.js)
 * 
 * Installation: npm install puppeteer
 */

const puppeteer = require('puppeteer');

async function fetchPage(options) {
    let browser = null;
    
    try {
        // Launch browser
        browser = await puppeteer.launch({
            headless: 'new',
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--disable-gpu',
                '--window-size=1920,1080'
            ]
        });
        
        const page = await browser.newPage();
        
        // Arrays to store captured URLs
        const capturedMedia = {
            direct: [],      // Direct MP3/MP4 files
            streaming: [],   // HLS (.m3u8) or DASH streams
            all: []         // All captured URLs
        };
        
        // Intercept network requests to capture media files
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            request.continue();
        });
        
        // Listen for responses - THIS IS WHERE WE CATCH STREAMING LINKS
        page.on('response', async (response) => {
            const url = response.url();
            const contentType = response.headers()['content-type'] || '';
            
            // Skip advertisements
            if (url.includes('advertisement') || url.includes('/ads/') || url.includes('google')) {
                return;
            }
            
            // 1. Catch HLS Streaming (.m3u8) - ZingMP3, YouTube, etc.
            if (url.includes('.m3u8') || contentType.includes('application/x-mpegURL') || 
                contentType.includes('application/vnd.apple.mpegurl')) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.streaming.push({ type: 'HLS', url: url });
                    capturedMedia.all.push(url);
                    console.error(`[PUPPETEER] 🎵 Captured HLS stream: ${url}`);
                }
            }
            
            // 2. Catch DASH Streaming (.mpd)
            else if (url.includes('.mpd') || contentType.includes('application/dash+xml')) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.streaming.push({ type: 'DASH', url: url });
                    capturedMedia.all.push(url);
                    console.error(`[PUPPETEER] 🎵 Captured DASH stream: ${url}`);
                }
            }
            
            // 3. Catch Direct Audio Files (.mp3, .m4a, .aac, etc.)
            else if (contentType.includes('audio/') || 
                     url.match(/\.(mp3|m4a|aac|wav|flac|ogg|opus|wma)(\?|$)/i)) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.direct.push({ type: 'audio', url: url, contentType: contentType });
                    capturedMedia.all.push(url);
                    console.error(`[PUPPETEER] 🎵 Captured direct audio: ${url}`);
                }
            }
            
            // 4. Catch Direct Video Files
            else if (contentType.includes('video/') || 
                     url.match(/\.(mp4|webm|mkv|avi|mov|flv|m4v)(\?|$)/i)) {
                if (!capturedMedia.all.includes(url)) {
                    capturedMedia.direct.push({ type: 'video', url: url, contentType: contentType });
                    capturedMedia.all.push(url);
                    console.error(`[PUPPETEER] 🎬 Captured direct video: ${url}`);
                }
            }
        });
        
        // Set user agent
        await page.setUserAgent(options.userAgent || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        
        // Set viewport
        await page.setViewport({ width: 1920, height: 1080 });
        
        // Navigate to page with longer timeout for heavy JS sites
        const timeout = options.timeout || 60000; // Increased to 60s
        await page.goto(options.url, {
            waitUntil: 'networkidle0', // Wait until no network activity
            timeout: timeout
        });
        
        // Wait for specific selector if provided
        if (options.wait_for_selector) {
            await page.waitForSelector(options.wait_for_selector, {
                timeout: options.timeout || 30000
            });
        }
        
        // Try to trigger playback for streaming sites
        // This helps load audio streams that only appear when playing
        if (options.url.includes('zingmp3') || options.url.includes('zing.vn')) {
            try {
                // Wait a bit for page to fully load
                await new Promise(r => setTimeout(r, 2000));
                
                // Try to click play button (various possible selectors)
                const playButtonSelectors = [
                    '.z-btn-play',
                    '.zm-btn',
                    '[class*="play"]',
                    'button[aria-label*="play" i]',
                    '.player-controls button'
                ];
                
                for (const selector of playButtonSelectors) {
                    try {
                        const button = await page.$(selector);
                        if (button) {
                            await button.click();
                            console.error('[PUPPETEER] Clicked play button:', selector);
                            await new Promise(r => setTimeout(r, 2000)); // Wait for stream to start
                            break;
                        }
                    } catch (e) {
                        // Try next selector
                    }
                }
            } catch (e) {
                console.error('[PUPPETEER] Could not auto-play:', e.message);
            }
        }
        
        // Execute custom JavaScript
        if (options.execute_js && Array.isArray(options.execute_js)) {
            for (const script of options.execute_js) {
                await page.evaluate(script);
            }
        }
        
        // Scroll to bottom to trigger lazy loading
        if (options.scroll_to_bottom) {
            await autoScroll(page);
        }
        
        // Wait additional time for JavaScript execution and network requests
        if (options.wait_time) {
            await new Promise(resolve => setTimeout(resolve, options.wait_time * 1000));
        } else {
            // Default wait for streaming sites to load media
            await new Promise(resolve => setTimeout(resolve, 3000));
        }
        
        // Take screenshot if requested
        if (options.screenshot_path) {
            await page.screenshot({ path: options.screenshot_path, fullPage: true });
        }
        
        // Get rendered HTML
        const html = await page.content();
        
        // Log summary
        console.error(`[PUPPETEER] Summary - Direct: ${capturedMedia.direct.length}, Streaming: ${capturedMedia.streaming.length}, Total: ${capturedMedia.all.length}`);
        
        // Return success response with captured media URLs
        console.log(JSON.stringify({
            success: true,
            html: html,
            url: page.url(),
            capturedMedia: capturedMedia
        }));
        
    } catch (error) {
        // Return error response
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

async function autoScroll(page) {
    await page.evaluate(async () => {
        await new Promise((resolve) => {
            let totalHeight = 0;
            const distance = 100;
            const timer = setInterval(() => {
                const scrollHeight = document.body.scrollHeight;
                window.scrollBy(0, distance);
                totalHeight += distance;
                
                if (totalHeight >= scrollHeight) {
                    clearInterval(timer);
                    resolve();
                }
            }, 100);
        });
    });
}

// Main execution
if (process.argv.length < 3) {
    console.log(JSON.stringify({
        success: false,
        error: 'Missing options parameter'
    }));
    process.exit(1);
}

try {
    let optionsStr = process.argv[2];
    
    // Support for file-based JSON (Windows-safe)
    if (optionsStr.startsWith('@')) {
        const fs = require('fs');
        const filePath = optionsStr.substring(1);
        optionsStr = fs.readFileSync(filePath, 'utf8');
    }
    
    const options = JSON.parse(optionsStr);
    fetchPage(options);
} catch (error) {
    console.log(JSON.stringify({
        success: false,
        error: 'Invalid JSON options: ' + error.message
    }));
    process.exit(1);
}
