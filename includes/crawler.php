<?php
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
error_reporting(E_ALL);

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
    private $proxies = [];
    private $cookieFile;
    private $robotsChecker = null;
    private $startTime;
    private $maxExecutionTime = 300;
    
    public function __construct($baseUrl, $allowedExtensions, $maxDepth = 2, $maxPages = 50, $options = []) {
        $this->baseUrl = $this->normalizeUrl($baseUrl);
        $this->allowedExtensions = $allowedExtensions;
        $this->maxDepth = $maxDepth;
        $this->maxPages = $maxPages;
        $this->startTime = time();
        
        error_log("[CRAWLER] Initialized with extensions: " . json_encode($allowedExtensions));
        
        if (isset($options['proxies']) && is_array($options['proxies'])) {
            $this->proxies = $options['proxies'];
        }
        
        $this->cookieFile = sys_get_temp_dir() . '/crawler_cookies_' . md5($this->baseUrl) . '.txt';

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
        
        if (!empty($this->proxies)) {
            $proxy = $this->proxies[array_rand($this->proxies)];
            curl_setopt($ch, CURLOPT_PROXY, $proxy['host']);
            if (isset($proxy['user'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['user'] . ':' . $proxy['pass']);
            }
        }
        
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
        if (in_array($url, $this->visitedUrls)) return;
        
        if (count($this->visitedUrls) > $this->maxPages * 2) return;
        
        // Check Robots.txt
        if ($this->robotsChecker && !$this->robotsChecker->isAllowed($url)) return;
        
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
        
        foreach ($extracted['files'] as $fileUrl) {
            $this->addFile($fileUrl);
        }
        
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
            preg_match_all('/https?:\/\/[^\s"\'<>]{1,500}\.(' . $extsRegex . ')(?=[\s"\'<>]|$)/i', $html, $matches);
            if (!empty($matches[0])) {
                foreach ($matches[0] as $m) {
                    $m = preg_replace('/[\\\\"\)\]\}]+$/', '', $m);
                    if (filter_var($m, FILTER_VALIDATE_URL)) {
                        $fileUrls[] = $m;
                    }
                }
            }
        }
        
        return ['links' => array_unique(array_filter($links)), 'files' => array_unique(array_filter($fileUrls))];
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
        
        $this->foundFiles[] = [
            'url' => $url,
            'filename' => urldecode($filename),
            'extension' => $ext,
            'size' => 'Unknown',
            'category' => $category
        ];
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