<?php
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
error_reporting(E_ALL);

// Load required modules
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/multi_curl.php';
require_once __DIR__ . '/smart_filters.php';
require_once __DIR__ . '/headless_browser.php';
require_once __DIR__ . '/streaming_downloader.php';

class WebCrawler {
    private $baseUrl;
    private $visitedUrls = [];
    private $foundFiles = [];
    private $allowedExtensions = [];
    private $maxDepth;
    private $maxPages;
    private $pageCount = 0;
    private $errors = [];
    private $timeout = 15;
    private $cookieFile;
    private $robotsChecker = null;
    private $respectRobots = false;
    private $startTime;
    private $maxExecutionTime = 300;
    private $debugLogFile = null;

    // ZingMP3 media-focused mode (avoid crawling unrelated links/ads)
    private $strictMediaOrigin = false;
    private $baseHost = '';
    private $zingMaxSongs = null;
    
    // New modules
    private $database = null;
    private $multiCurl = null;
    private $loginHandler = null;
    private $useDatabase = false;
    private $useParallelFetch = false;
    private $sessionId = null;
    
    // Advanced features
    private $headlessBrowser = null;
    private $useHeadless = false;
    private $queue = null;
    private $useQueue = false;
    
    public function __construct($baseUrl, $allowedExtensions, $maxDepth = 2, $maxPages = 200, $options = []) {
        $this->baseUrl = $this->normalizeUrl($baseUrl);
        $this->baseHost = strtolower((string)(parse_url($this->baseUrl, PHP_URL_HOST) ?? ''));
        if (str_starts_with($this->baseHost, 'www.')) {
            $this->baseHost = substr($this->baseHost, 4);
        }
        $this->allowedExtensions = $allowedExtensions;
        $this->maxDepth = $maxDepth;
        $this->maxPages = $maxPages;
        $this->startTime = time();
        $this->timeout = $options['timeout'] ?? 15;  // Set timeout từ options
        $this->respectRobots = $options['respect_robots'] ?? false;  // Default: ignore robots.txt for better crawling
        $this->debugLogFile = __DIR__ . '/../data/crawl_debug.log';
        
        $this->debugLog("[CRAWLER] Initialized with URL: {$this->baseUrl}");
        $this->debugLog("[CRAWLER] Extensions: " . json_encode($allowedExtensions));
        $this->debugLog("[CRAWLER] Max Depth: {$maxDepth}, Max Pages: {$maxPages}");
        $this->debugLog("[CRAWLER] Timeout: {$this->timeout}s");
        
        error_log("[CRAWLER] Initialized with extensions: " . json_encode($allowedExtensions));
        error_log("[CRAWLER] Base URL: " . $this->baseUrl);
        error_log("[CRAWLER] Max Depth: " . $maxDepth . ", Max Pages: " . $maxPages);
        error_log("[CRAWLER] Timeout: {$this->timeout}s");
        
        $this->cookieFile = sys_get_temp_dir() . '/crawler_cookies_' . md5($this->baseUrl) . '.txt';
        
        // Initialize Database if enabled
        if (isset($options['use_database']) && $options['use_database']) {
            try {
                $this->database = new CrawlerDatabase($options['db_path'] ?? null);
                $this->useDatabase = true;
                $this->sessionId = $this->database->createSession($this->baseUrl, $maxDepth, $maxPages);
                error_log("[CRAWLER] SQLite Database enabled");
            } catch (Exception $e) {
                error_log("[CRAWLER] Database initialization failed: " . $e->getMessage());
            }
        }
        
        // Initialize Multi-cURL if enabled
        if (isset($options['use_parallel']) && $options['use_parallel']) {
            $this->multiCurl = new MultiCurl([
                'max_connections' => $options['parallel_connections'] ?? 5,
                'timeout' => $options['timeout'] ?? 15,
                'cookie_file' => $this->cookieFile
            ]);
            $this->useParallelFetch = true;
            error_log("[CRAWLER] Parallel fetching enabled (max {$options['parallel_connections']} connections)");
        }
        
        // Initialize Login Handler if credentials provided
        if (isset($options['login']) && is_array($options['login'])) {
            $this->loginHandler = new LoginHandler($this->cookieFile);
            
            // Perform login
            $loginSuccess = $this->loginHandler->login(
                $options['login']['url'],
                $options['login']['credentials'],
                $options['login']['options'] ?? []
            );
            
            if ($loginSuccess) {
                error_log("[CRAWLER] Login successful");
            } else {
                error_log("[CRAWLER] Login failed");
            }
        }
        
        // Initialize Headless Browser if enabled
        if (isset($options['use_headless']) && $options['use_headless']) {
            try {
                $this->headlessBrowser = new HeadlessBrowser([
                    'preferred_mode' => $options['headless_mode'] ?? null,
                    'timeout' => $this->timeout,
                    'cookie_file' => $this->cookieFile,
                ]);
                $this->useHeadless = $this->headlessBrowser->isHeadless();
                error_log("[CRAWLER] Headless Browser enabled: {$this->headlessBrowser->getMode()}");
            } catch (Exception $e) {
                error_log("[CRAWLER] Headless Browser initialization failed: " . $e->getMessage());
            }
        }

        // Strict media-origin mode (default for Zing when requested)
        $isZing = (str_contains($this->baseHost, 'zingmp3.vn') || str_contains($this->baseHost, 'zing.vn') || str_contains($this->baseHost, 'mp3.zing.vn'));
        if ($isZing && $this->useHeadless && !empty($options['strict_media_origin'])) {
            $this->strictMediaOrigin = true;
            // Puppeteer layer already visits song pages for album/playlist and captures media.
            // Avoid crawling deep/extra pages to reduce unrelated captures.
            $this->maxDepth = 0;
            error_log('[CRAWLER] Strict media-origin mode enabled for Zing (depth=0, pages=1)');
        }

        if ($isZing && isset($options['zing_max_songs'])) {
            $this->zingMaxSongs = (int)$options['zing_max_songs'];
        }

        // Parallel fetching uses plain cURL and will bypass JS rendering.
        // If headless is active, disable parallel mode to keep extraction consistent.
        if ($this->useHeadless && $this->useParallelFetch) {
            $this->useParallelFetch = false;
            error_log('[CRAWLER] Disabled parallel fetching because headless mode is active');
        }
        
        // Initialize Queue System if enabled
        if (isset($options['use_queue']) && $options['use_queue']) {
            try {
                $this->queue = new CrawlerQueue([
                    'session_id' => $this->sessionId ?? uniqid('session_', true),
                    'redis_host' => $options['redis_host'] ?? '127.0.0.1',
                    'redis_port' => $options['redis_port'] ?? 6379,
                ]);
                $this->useQueue = true;
                error_log("[CRAWLER] Queue System enabled");
            } catch (Exception $e) {
                error_log("[CRAWLER] Queue System initialization failed: " . $e->getMessage());
            }
        }
        
        $this->robotsChecker = null;
    }
    
    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }
    
    private function debugLog($message) {
        if ($this->debugLogFile) {
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents($this->debugLogFile, "[$timestamp] $message\n", FILE_APPEND);
        }
    }

    private function normalizeUrl($url) {
        $pos = strpos($url, '#');
        if ($pos !== false) $url = substr($url, 0, $pos);
        $url = trim($url);
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "https://" . $url;
        return rtrim($url, '/');
    }
    
    private function fetchUrl($url) {
        // Use Headless Browser if enabled
        if ($this->useHeadless && $this->headlessBrowser) {
            error_log("[CRAWLER] Using headless browser for: $url");
            $content = $this->headlessBrowser->fetchPage($url, [
                'scroll_to_bottom' => true,
                'wait_time' => 3, // Increased from 2 to 3
                'timeout' => $this->timeout,
                'zing_max_songs' => $this->zingMaxSongs,
            ]);
            if ($content) {
                error_log("[CRAWLER] Headless browser fetched: $url");
                return $content;
            }
            error_log("[CRAWLER] Headless browser failed, falling back to cURL");
        }
        
        // Use Login Handler for authenticated requests
        if ($this->loginHandler && $this->loginHandler->isLoggedIn()) {
            $content = $this->loginHandler->fetchAuthenticated($url);
            if ($content) {
                return $content;
            }
        }
        
        // Standard CURL fetch
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,vi;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Cache-Control: max-age=0',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Ch-Ua: "Not A(Brand";v="99", "Google Chrome";v="121", "Chromium";v="121"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->errors[] = "CURL Error for $url: $error";
            
            error_log("[CRAWLER] CURL failed, trying file_get_contents fallback for $url");
            $content = @file_get_contents($url, false, stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                               "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                               "Accept-Encoding: gzip, deflate, br\r\n",
                    'timeout' => 30,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]));
            
            if ($content !== false) {
                // Decompress if gzipped
                if (substr($content, 0, 2) === "\x1f\x8b") {
                    error_log("[CRAWLER] Decompressing gzipped content");
                    $content = @gzdecode($content);
                }
                error_log("[CRAWLER] file_get_contents succeeded for $url");
                return $content;
            }
            
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 400 && $content) {
            // Decompress if gzipped (cURL usually handles this, but double-check)
            if (is_string($content) && substr($content, 0, 2) === "\x1f\x8b") {
                error_log("[CRAWLER] Decompressing gzipped cURL response");
                $content = @gzdecode($content);
            }
            return $content;
        }
        
        // DEBUG: Log why fetch failed
        error_log("[CRAWLER] ❌ fetchUrl() returning FALSE - HTTP Code: $httpCode, Content length: " . (is_string($content) ? strlen($content) : 0));
        $this->debugLog("[CRAWLER] ❌ fetchUrl() returning FALSE - HTTP Code: $httpCode, Content: " . (empty($content) ? 'EMPTY' : strlen($content) . ' bytes'));
        
        return false;
    }

    public function crawl($url = null, $depth = 0) {
        if ($url === null) $url = $this->baseUrl;
        
        $this->debugLog("[CRAWLER] crawl() called - URL: $url, Depth: $depth");
        error_log("[CRAWLER] crawl() called with URL: $url, depth: $depth");
        
        // Only initialize robots checker if respectRobots is enabled
        if ($this->respectRobots && $this->robotsChecker === null && class_exists('RobotsChecker')) {
            $this->robotsChecker = new RobotsChecker($this->baseUrl);
            $this->debugLog("[CRAWLER] RobotsChecker initialized");
        }
        
        if ((time() - $this->startTime) > $this->maxExecutionTime) {
            error_log("[CRAWLER] Max execution time reached");
            return;
        }
        if ($depth > $this->maxDepth || $this->pageCount >= $this->maxPages) {
            error_log("[CRAWLER] Depth or page limit reached: depth=$depth, pageCount={$this->pageCount}");
            return;
        }
        
        $url = $this->normalizeUrl($url);
        error_log("[CRAWLER] Normalized URL: $url");
        
        // Check if already visited (only in current session, not database)
        // This allows re-crawling URLs to update files
        if (in_array($url, $this->visitedUrls)) {
            error_log("[CRAWLER] URL already visited in this session: $url");
            return;
        }
        
        if (count($this->visitedUrls) > $this->maxPages * 2) {
            error_log("[CRAWLER] Visited URLs limit exceeded");
            return;
        }
        
        // Check Robots.txt (only if enabled)
        if ($this->respectRobots && $this->robotsChecker && !$this->robotsChecker->isAllowed($url)) {
            $this->debugLog("[CRAWLER] ❌ Blocked by robots.txt: $url");
            error_log("[CRAWLER] ❌ Blocked by robots.txt: $url");
            return;
        }
        
        // Mark as visited
        if ($this->useDatabase && $this->database) {
            $this->database->markVisited($url, $this->baseUrl, $depth);
        }
        $this->visitedUrls[] = $url;
        $this->pageCount++;
        
        if ($this->pageCount % 3 == 0 || $this->pageCount == 1) {
            try {
                // FIX #7: Write progress to file instead of session (no lock!)
                $progressFile = sys_get_temp_dir() . '/crawler_progress_' . session_id() . '.json';
                $progress = [
                    'current' => $this->pageCount,
                    'total' => $this->maxPages,
                    'status' => 'crawling',
                    'time_elapsed' => time() - $this->startTime
                ];
                
                file_put_contents($progressFile, json_encode($progress), LOCK_EX);
                
                // Update database session if enabled
                if ($this->useDatabase && $this->database && $this->sessionId) {
                    $this->database->updateSession($this->sessionId, $this->pageCount, count($this->foundFiles));
                }
                
                if (ob_get_level() > 0) @ob_flush();
            } catch (Exception $e) {
            }
        }

        $html = $this->fetchUrl($url);
        if (!$html) {
            $this->errors[] = "Failed to fetch: $url";
            $this->debugLog("[CRAWLER] ❌ Failed to fetch: $url");
            error_log("[CRAWLER] Failed to fetch: $url");
            return;
        }
        
        $this->debugLog("[CRAWLER] ✅ Fetched $url - HTML length: " . strlen($html));
        error_log("[CRAWLER] Fetched $url - HTML length: " . strlen($html));
        
        // Log HTML snippet for debugging (first 500 chars)
        if (stripos($url, 'zingmp3') !== false || stripos($url, 'zing.vn') !== false) {
            $snippet = substr($html, 0, 500);
            $this->debugLog("[CRAWLER] HTML snippet from ZingMP3: " . $snippet);
        }
        
        $this->debugLog("[CRAWLER] Extracting links from $url...");
        $extracted = $this->extractLinks($html, $url);
        
        $this->debugLog("[CRAWLER] Extracted " . count($extracted['files']) . " files and " . count($extracted['links']) . " links");
        error_log("[CRAWLER] Extracted " . count($extracted['files']) . " files and " . count($extracted['links']) . " links from $url");
        
        // Log sample of extracted files for debugging
        if (count($extracted['files']) > 0) {
            $sampleFiles = array_slice($extracted['files'], 0, 5);
            $this->debugLog("[CRAWLER] Sample files: " . json_encode($sampleFiles));
        }
        
        // Apply smart filters to files
        if (count($extracted['files']) > 0) {
            $beforeFilter = count($extracted['files']);
            $fileType = $this->detectFileType($extracted['files'][0] ?? '');
            $extracted['files'] = SmartFilters::applyAllFilters($extracted['files'], [
                'type' => $fileType,
                'sort_by_quality' => true
            ]);
            $afterFilter = count($extracted['files']);
            $this->debugLog("[CRAWLER] Smart filters: $beforeFilter → $afterFilter files (removed " . ($beforeFilter - $afterFilter) . ")");
            error_log("[CRAWLER] After filtering: $afterFilter files (removed " . ($beforeFilter - $afterFilter) . " files)");
        }
        
        foreach ($extracted['files'] as $fileUrl) {
            $this->addFile($fileUrl);
        }
        
        // Parallel fetch next pages if enabled
        if ($this->useParallelFetch && $this->multiCurl && count($extracted['links']) > 5) {
            error_log("[CRAWLER] Using parallel fetch for " . count($extracted['links']) . " links");
            $this->crawlParallel($extracted['links'], $depth);
        } else {
            // Sequential crawl
            $processedLinks = 0;
            $maxLinksPerPage = 50;
            
            foreach ($extracted['links'] as $link) {
                if ($this->pageCount >= $this->maxPages) break;
                if ($processedLinks >= $maxLinksPerPage) break;
                
                $baseHost = str_replace('www.', '', parse_url($this->baseUrl, PHP_URL_HOST));
                $linkHost = str_replace('www.', '', parse_url($link, PHP_URL_HOST));
                
                if ($baseHost === $linkHost && !in_array($this->normalizeUrl($link), $this->visitedUrls)) {
                    $this->crawl($link, $depth + 1);
                    $processedLinks++;
                }
            }
        }
    }
    
    /**
     * Crawl multiple URLs in parallel
     */
    private function crawlParallel($links, $depth) {
        $validLinks = [];
        $baseHost = str_replace('www.', '', parse_url($this->baseUrl, PHP_URL_HOST));
        
        foreach ($links as $link) {
            if ($this->pageCount >= $this->maxPages) break;
            
            $linkHost = str_replace('www.', '', parse_url($link, PHP_URL_HOST));
            $normalized = $this->normalizeUrl($link);
            
            if ($baseHost === $linkHost && !in_array($normalized, $this->visitedUrls)) {
                $validLinks[] = $link;
            }
            
            if (count($validLinks) >= 10) break; // Limit batch size
        }
        
        if (empty($validLinks)) return;
        
        // Fetch all URLs in parallel
        $results = $this->multiCurl->fetchAll($validLinks, function($url, $result) use ($depth) {
            if ($result['success']) {
                $this->visitedUrls[] = $this->normalizeUrl($url);
                $this->pageCount++;
                
                // Extract and process files from this page
                $extracted = $this->extractLinks($result['content'], $url);
                
                foreach ($extracted['files'] as $fileUrl) {
                    $this->addFile($fileUrl);
                }
                
                error_log("[CRAWLER] Parallel fetched: $url (" . strlen($result['content']) . " bytes, " . count($extracted['files']) . " files)");
            }
        });
    }
    
    /**
     * Detect file type from URL
     */
    private function detectFileType($url) {
        if (preg_match('/\.(jpg|jpeg|png|gif|svg|webp|bmp|tiff)$/i', $url)) return 'image';
        if (preg_match('/\.(pdf|doc|docx|txt|csv)$/i', $url)) return 'document';
        if (preg_match('/\.(mp4|avi|mkv|webm)$/i', $url)) return 'video';
        if (preg_match('/\.(mp3|m4a|aac|wav|flac|ogg|opus|wma|mid|oga|ape|alac)$/i', $url)) return 'audio';
        return 'other';
    }

    private function extractLinks($html, $currentUrl) {
        $links = [];
        $fileUrls = [];

        // Always attempt to extract Puppeteer-injected media links via regex first.
        // This keeps media extraction working even when DOMDocument fails to parse complex pages.
        if (is_string($html) && $html !== '') {
            if (preg_match_all('/<a\s+[^>]*data-source="puppeteer-(?:direct|stream)"[^>]*href="([^"]+)"/i', $html, $m)) {
                foreach ($m[1] as $href) {
                    $abs = $this->getAbsoluteUrl(htmlspecialchars_decode($href, ENT_QUOTES), $currentUrl);
                    if ($abs) {
                        // Only keep if it matches the selected extensions, or looks like extensionless direct media.
                        if ($this->isFileUrl($abs) || $this->isLikelyDirectMediaUrl($abs) || $this->isManifestUrl($abs)) {
                            $fileUrls[] = $abs;
                        }
                    }
                }
            }
        }
        
        if (strlen($html) > 5 * 1024 * 1024) {
            $html = substr($html, 0, 5 * 1024 * 1024);
        }
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        set_time_limit(30);
        
        try {
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        } catch (Exception $e) {
            libxml_clear_errors();
            // Return any Puppeteer-injected media we managed to extract
            $fileUrls = array_values(array_unique($fileUrls));
            return ['links' => [], 'files' => $fileUrls];
        }
        
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        // Special: pick up media URLs injected by Puppeteer (may not have file extensions)
        $puppeteerPreferred = [];
        $puppeteerFallback = [];
        foreach ($xpath->query('//a[@data-source="puppeteer-direct" or @data-source="puppeteer-stream"]') as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;
            $abs = $this->getAbsoluteUrl($href, $currentUrl);
            if ($abs) {
                // Only keep if it matches the selected extensions, or looks like extensionless direct media.
                // This prevents showing TikTok/NCT links as generic .bin when the user didn't select mp4/mp3.
                if (!($this->isFileUrl($abs) || $this->isLikelyDirectMediaUrl($abs) || $this->isManifestUrl($abs))) {
                    continue;
                }
                $captureSource = strtolower((string)$a->getAttribute('data-capture-source'));
                if ($captureSource === 'zing-streaming') {
                    $puppeteerPreferred[] = $abs;
                } else {
                    $puppeteerFallback[] = $abs;
                }
                $src = $a->getAttribute('data-source');
                $t = $a->getAttribute('data-type');
                error_log("[CRAWLER] Added Puppeteer media link ($src/$t): $abs");
            }
        }

        // In strict mode: only use Puppeteer-injected media and do not crawl page links.
        if ($this->strictMediaOrigin) {
            $only = !empty($puppeteerPreferred) ? $puppeteerPreferred : $puppeteerFallback;
            // De-dupe while preserving order
            $seen = [];
            $deduped = [];
            foreach ($only as $u) {
                if (isset($seen[$u])) continue;
                $seen[$u] = true;
                $deduped[] = $u;
            }
            return ['links' => [], 'files' => $deduped];
        }

        // Non-strict: keep Puppeteer captures alongside normal extraction
        $fileUrls = array_merge($fileUrls, $puppeteerPreferred, $puppeteerFallback);
        
        // Extract from <a> tags
        foreach ($xpath->query('//a/@href') as $node) {
            $href = $this->getAbsoluteUrl($node->nodeValue, $currentUrl);
            if ($this->isFileUrl($href)) $fileUrls[] = $href;
            else $links[] = $href;
        }
        
        // Extract from <img> tags
        $imgAttrs = ['src', 'data-src', 'data-original', 'data-url'];
        foreach ($xpath->query('//img') as $node) {
            foreach ($imgAttrs as $attr) {
                $val = $node->getAttribute($attr);
                if ($val) {
                    $abs = $this->getAbsoluteUrl($val, $currentUrl);
                    if ($this->isFileUrl($abs)) $fileUrls[] = $abs;
                }
            }
        }
        
        // Extract from <video> and <source> tags
        foreach ($xpath->query('//video/@src | //video//source/@src') as $node) {
            $src = $this->getAbsoluteUrl($node->nodeValue, $currentUrl);
            if ($src && ($this->isFileUrl($src) || $this->isLikelyDirectMediaUrl($src))) {
                $fileUrls[] = $src;
                error_log("[CRAWLER] Found video from <video>: $src");
            }
        }
        
        // Extract from <audio> and <source> tags
        foreach ($xpath->query('//audio/@src | //audio//source/@src') as $node) {
            $src = $this->getAbsoluteUrl($node->nodeValue, $currentUrl);
            if ($src && ($this->isFileUrl($src) || $this->isLikelyDirectMediaUrl($src))) {
                $fileUrls[] = $src;
                error_log("[CRAWLER] Found audio from <audio>: $src");
            }
        }
        
        // Extract from <iframe> tags (e.g., tryit.asp examples)
        foreach ($xpath->query('//iframe/@src') as $node) {
            $src = $this->getAbsoluteUrl($node->nodeValue, $currentUrl);
            if ($src) {
                $links[] = $src;
                error_log("[CRAWLER] Found iframe link: $src");
            }
        }
        
        $extsRegex = implode('|', array_map(function($e) { return preg_quote(ltrim($e, '.'), '/'); }, $this->allowedExtensions));
        if ($extsRegex) {
            error_log("[CRAWLER] Regex pattern for extensions: " . $extsRegex);
            preg_match_all('/https?:\/\/[^\s"\'<>]{1,500}\.(' . $extsRegex . ')(?=[\s"\'<>]|$)/i', $html, $matches);
            if (!empty($matches[0])) {
                error_log("[CRAWLER] Regex found " . count($matches[0]) . " potential files");
                foreach ($matches[0] as $m) {
                    $m = preg_replace('/[\\\\"\)\]\}]+$/', '', $m);
                    if (filter_var($m, FILTER_VALIDATE_URL)) {
                        $fileUrls[] = $m;
                    }
                }
            } else {
                error_log("[CRAWLER] Regex found 0 files in HTML");
            }
        } else {
            error_log("[CRAWLER] WARNING: No extensions to match!");
        }
        
        // Special: Also catch .m3u8 (HLS streaming) and .mpd (DASH) if looking for audio/video
        $hasAudioVideo = !empty(array_intersect(['.mp3', '.mp4', '.m4a', '.wav', '.flac'], $this->allowedExtensions));
        if ($hasAudioVideo) {
            // Match .m3u8 URLs (HLS Streaming - ZingMP3, YouTube, etc.)
            preg_match_all('/https?:\/\/[^\s"\'<>]{1,1000}\.m3u8(?:\?[^\s"\'<>]*)?/i', $html, $m3u8Matches);
            if (!empty($m3u8Matches[0])) {
                foreach ($m3u8Matches[0] as $m3u8Url) {
                    // Skip ads
                    if (stripos($m3u8Url, 'advertisement') === false && stripos($m3u8Url, '/ads/') === false) {
                        $fileUrls[] = $m3u8Url;
                        error_log("[CRAWLER] Found HLS stream: $m3u8Url");
                    }
                }
            }
            
            // Match .mpd URLs (DASH Streaming)
            preg_match_all('/https?:\/\/[^\s"\'<>]{1,1000}\.mpd(?:\?[^\s"\'<>]*)?/i', $html, $mpdMatches);
            if (!empty($mpdMatches[0])) {
                foreach ($mpdMatches[0] as $mpdUrl) {
                    if (stripos($mpdUrl, 'advertisement') === false) {
                        $fileUrls[] = $mpdUrl;
                        error_log("[CRAWLER] Found DASH stream: $mpdUrl");
                    }
                }
            }
        }
        
        $uniqueLinks = array_unique(array_filter($links));
        $uniqueFiles = array_unique(array_filter($fileUrls));
        error_log("[CRAWLER] Total extracted: " . count($uniqueLinks) . " links, " . count($uniqueFiles) . " files");
        
        return ['links' => $uniqueLinks, 'files' => $uniqueFiles];
    }
    
    private function getAbsoluteUrl($rel, $base) {
        if (empty($rel) || strpos($rel, 'javascript:') === 0 || strpos($rel, '#') === 0 || strpos($rel, 'mailto:') === 0) return '';
        
        $hashPos = strpos($rel, '#');
        if ($hashPos !== false) $rel = substr($rel, 0, $hashPos);

        if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
        if (strpos($rel, '//') === 0) return 'https:' . $rel;
        
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '/';
        
        if ($rel[0] == '/') return $scheme . '://' . $host . $rel;
        return $scheme . '://' . $host . rtrim(dirname($path), '/') . '/' . $rel;
    }
    
    private function isFileUrl($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return false;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $fullExt = '.' . $ext;
        $isMatch = in_array($fullExt, $this->allowedExtensions);
        if ($isMatch) {
            error_log("[CRAWLER] Matched file: $url (ext: $fullExt)");
        }
        return $isMatch;
    }

    private function isManifestUrl($url) {
        if (!is_string($url) || $url === '') return false;
        return (bool)preg_match('/\.m3u8(\?|$)/i', $url) || (bool)preg_match('/\.mpd(\?|$)/i', $url);
    }

    private function isLikelyDirectMediaUrl($url) {
        // Allow picking up extensionless direct media URLs embedded in <audio>/<video>
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        if ($host === '') return false;

        // ZingMP3 CDN often serves direct MP3 without a .mp3 suffix in the path
        if (in_array('.mp3', $this->allowedExtensions, true) && str_contains($host, 'zmdcdn.me')) {
            return true;
        }

        // TikTok CDNs often serve direct MP4 without a file extension
        if (in_array('.mp4', $this->allowedExtensions, true) && (
            str_contains($host, 'tiktokcdn') ||
            str_contains($host, 'tiktokv') ||
            str_contains($host, 'byteoversea') ||
            str_contains($host, 'ibytedtos') ||
            str_contains($host, 'tiktok.com')
        )) {
            return true;
        }

        return false;
    }
    
    private function addFile($url) {
        foreach ($this->foundFiles as $f) {
            if ($f['url'] === $url) return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $rawFilename = $path ? basename($path) : '';
        if (!$rawFilename || $rawFilename === '/' || $rawFilename === '.') {
            $rawFilename = 'file_' . substr(md5($url), 0, 12);
        }

        $ext = strtolower(pathinfo($rawFilename, PATHINFO_EXTENSION));
        $fullExt = $ext ? ('.' . $ext) : '';

        // Heuristic: some direct media URLs (e.g. ZingMP3 CDN) have no extension
        if ($fullExt === '' || $fullExt === '.') {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

            if (in_array('.mp3', $this->allowedExtensions, true) && (str_contains($host, 'zmdcdn.me'))) {
                $fullExt = '.mp3';
            } elseif (in_array('.mp4', $this->allowedExtensions, true) && (
                str_contains($host, 'tiktokcdn') ||
                str_contains($host, 'tiktokv') ||
                str_contains($host, 'byteoversea') ||
                str_contains($host, 'ibytedtos') ||
                str_contains($host, 'tiktok.com')
            )) {
                $fullExt = '.mp4';
            } else {
                $fullExt = '.bin';
            }
        }

        $filename = $rawFilename;
        if ($fullExt && !str_ends_with(strtolower($filename), $fullExt)) {
            $filename .= $fullExt;
        }

        $category = $this->getFileCategory($fullExt);
        
        $fileData = [
            'url' => $url,
            'filename' => urldecode($filename),
            'extension' => $fullExt,
            'size' => 'Unknown',
            'category' => $category
        ];
        
        $this->foundFiles[] = $fileData;
        
        error_log("[CRAWLER] Added file: $url (category: $category)");
        
        // Save to database if enabled (size will be updated later)
        if ($this->useDatabase && $this->database) {
            $this->database->addFile($url, $filename, $category, $this->baseUrl, null);
        }
    }
    
    private function getFileCategory($ext) {
        $cats = [
            'documents' => ['.pdf','.doc','.docx','.xls','.xlsx','.ppt','.pptx','.txt','.csv','.rtf','.odt'],
            'images' => ['.jpg','.jpeg','.png','.gif','.svg','.webp','.ico','.bmp','.tiff'],
            'videos' => ['.mp4','.mov','.avi','.mkv','.webm','.wmv','.flv','.m4v'],
            'audio' => ['.mp3','.wav','.flac','.m4a','.ogg','.aac','.wma','.mid'],
            'archives' => ['.zip','.rar','.7z','.tar','.gz','.iso','.xz','.bz2'],
            'code' => ['.html','.css','.js','.php','.json','.py','.sql','.xml']
        ];
        foreach ($cats as $name => $exts) {
            if (in_array($ext, $exts)) return $name;
        }
        return 'other';
    }
    
    public function getFoundFiles() { return $this->foundFiles; }
    public function getStats() {
        return [
            'visited_urls' => count($this->visitedUrls),
            'found_files' => count($this->foundFiles),
            'headless' => [
                'enabled' => (bool)$this->useHeadless,
                'mode' => $this->headlessBrowser ? $this->headlessBrowser->getMode() : 'none'
            ]
        ];
    }
    public function getErrors() { return $this->errors; }
}

class RobotsChecker {
    private $disallowed = [];
    private $userAgent = '*';
    private $robotsParsed = false;
    
    public function __construct($baseUrl) {
        $robotsUrl = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST) . '/robots.txt';
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]); 
        $content = @file_get_contents($robotsUrl, false, $ctx);
        if ($content) {
            $this->parse($content);
            $this->robotsParsed = true;
        }
    }
    
    private function parse($content) {
        $lines = explode("\n", $content);
        $agent = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] == '#') continue;
            if (stripos($line, 'User-agent:') === 0) $agent = trim(substr($line, 11));
            if (($agent == '*' || $agent == $this->userAgent) && stripos($line, 'Disallow:') === 0) {
                $this->disallowed[] = trim(substr($line, 9));
            }
        }
    }
    
    public function isAllowed($url) {
        if (!$this->robotsParsed) return true;
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        foreach ($this->disallowed as $rule) {
            if ($rule == '/') return false;
            $ruleRegex = str_replace(['*', '?'], ['.*', '.'], $rule);
            if (preg_match('#^' . $ruleRegex . '#', $path)) return false;
        }
        return true;
    }
}
?>