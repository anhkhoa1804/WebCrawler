<?php
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
error_reporting(E_ALL);

// Load new lightweight modules
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/multi_curl.php';
require_once __DIR__ . '/login_handler.php';
require_once __DIR__ . '/smart_filters.php';

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
    private $startTime;
    private $maxExecutionTime = 300;
    
    // New modules
    private $database = null;
    private $multiCurl = null;
    private $loginHandler = null;
    private $useDatabase = false;
    private $useParallelFetch = false;
    private $sessionId = null;
    
    public function __construct($baseUrl, $allowedExtensions, $maxDepth = 2, $maxPages = 50, $options = []) {
        $this->baseUrl = $this->normalizeUrl($baseUrl);
        $this->allowedExtensions = $allowedExtensions;
        $this->maxDepth = $maxDepth;
        $this->maxPages = $maxPages;
        $this->startTime = time();
        
        error_log("[CRAWLER] Initialized with extensions: " . json_encode($allowedExtensions));
        error_log("[CRAWLER] Base URL: " . $this->baseUrl);
        error_log("[CRAWLER] Max Depth: " . $maxDepth . ", Max Pages: " . $maxPages);
        
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
        
        $this->robotsChecker = null;
    }
    
    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
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
                               "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
                    'timeout' => 30,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]));
            
            if ($content !== false) {
                error_log("[CRAWLER] file_get_contents succeeded for $url");
                return $content;
            }
            
            return false;
        }
        
        if ($httpCode >= 200 && $httpCode < 400 && $content) {
            return $content;
        }
        
        return false;
    }

    public function crawl($url = null, $depth = 0) {
        if ($url === null) $url = $this->baseUrl;
        
        if ($this->robotsChecker === null && class_exists('RobotsChecker')) {
            $this->robotsChecker = new RobotsChecker($this->baseUrl);
        }
        
        if ((time() - $this->startTime) > $this->maxExecutionTime) return;
        if ($depth > $this->maxDepth || $this->pageCount >= $this->maxPages) return;
        
        $url = $this->normalizeUrl($url);
        
        // Check if already visited (database or array)
        if ($this->useDatabase && $this->database) {
            if ($this->database->isVisited($url)) return;
        } else {
            if (in_array($url, $this->visitedUrls)) return;
        }
        
        if (count($this->visitedUrls) > $this->maxPages * 2) return;
        
        // Check Robots.txt
        if ($this->robotsChecker && !$this->robotsChecker->isAllowed($url)) return;
        
        // Mark as visited
        if ($this->useDatabase && $this->database) {
            $this->database->markVisited($url, $this->baseUrl, $depth);
        }
        $this->visitedUrls[] = $url;
        $this->pageCount++;
        
        if ($this->pageCount % 3 == 0 || $this->pageCount == 1) {
            try {
                if (session_status() == PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['crawl_progress'] = [
                    'current' => $this->pageCount,
                    'total' => $this->maxPages,
                    'status' => 'crawling',
                    'time_elapsed' => time() - $this->startTime
                ];
                
                // Update database session if enabled
                if ($this->useDatabase && $this->database && $this->sessionId) {
                    $this->database->updateSession($this->sessionId, $this->pageCount, count($this->foundFiles));
                }
                
                session_write_close();
                
                if (ob_get_level() > 0) @ob_flush();
            } catch (Exception $e) {
            }
        }

        $html = $this->fetchUrl($url);
        if (!$html) {
            $this->errors[] = "Failed to fetch: $url";
            error_log("[CRAWLER] Failed to fetch: $url");
            return;
        }
        
        error_log("[CRAWLER] Fetched $url - HTML length: " . strlen($html));
        
        $extracted = $this->extractLinks($html, $url);
        
        error_log("[CRAWLER] Extracted " . count($extracted['files']) . " files and " . count($extracted['links']) . " links from $url");
        
        // Apply smart filters to files
        if (count($extracted['files']) > 0) {
            $fileType = $this->detectFileType($extracted['files'][0] ?? '');
            $extracted['files'] = SmartFilters::applyAllFilters($extracted['files'], [
                'type' => $fileType,
                'sort_by_quality' => true
            ]);
            error_log("[CRAWLER] After filtering: " . count($extracted['files']) . " files");
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
        return 'other';
    }

    private function extractLinks($html, $currentUrl) {
        $links = [];
        $fileUrls = [];
        
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
            return ['links' => [], 'files' => []];
        }
        
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        foreach ($xpath->query('//a/@href') as $node) {
            $href = $this->getAbsoluteUrl($node->nodeValue, $currentUrl);
            if ($this->isFileUrl($href)) $fileUrls[] = $href;
            else $links[] = $href;
        }
        
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
    
    private function addFile($url) {
        foreach ($this->foundFiles as $f) {
            if ($f['url'] === $url) return;
        }

        $filename = basename(parse_url($url, PHP_URL_PATH));
        $ext = '.' . strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $category = $this->getFileCategory($ext);
        
        $fileData = [
            'url' => $url,
            'filename' => urldecode($filename),
            'extension' => $ext,
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
            'videos' => ['.mp4','.mov','.avi','.mkv','.webm','.wmv','.flv'],
            'audio' => ['.mp3','.wav','.flac','.m4a','.ogg','.aac'],
            'archives' => ['.zip','.rar','.7z','.tar','.gz','.iso'],
            'code' => ['.html','.css','.js','.php','.json','.py','.sql','.xml']
        ];
        foreach ($cats as $name => $exts) {
            if (in_array($ext, $exts)) return $name;
        }
        return 'other';
    }
    
    public function getFoundFiles() { return $this->foundFiles; }
    public function getStats() { return ['visited_urls' => count($this->visitedUrls), 'found_files' => count($this->foundFiles)]; }
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