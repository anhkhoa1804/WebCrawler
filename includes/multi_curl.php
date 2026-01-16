<?php
/**
 * cURL Multi-Exec Module - Parallel HTTP Requests
 * Fetch multiple URLs simultaneously without complex threading
 */

class MultiCurl {
    private $maxConnections = 10;
    private $timeout = 15;
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    private $cookieFile = null;
    
    public function __construct($options = []) {
        $this->maxConnections = $options['max_connections'] ?? 10;
        $this->timeout = $options['timeout'] ?? 15;
        $this->userAgent = $options['user_agent'] ?? $this->userAgent;
        $this->cookieFile = $options['cookie_file'] ?? null;
    }
    
    /**
     * Fetch multiple URLs in parallel
     * @param array $urls - Array of URLs to fetch
     * @param callable $callback - Optional callback for each completed request
     * @return array - Results ['url' => ['success' => true, 'content' => '...', 'http_code' => 200]]
     */
    public function fetchAll($urls, $callback = null) {
        $results = [];
        $chunks = array_chunk($urls, $this->maxConnections);
        
        foreach ($chunks as $chunk) {
            $chunkResults = $this->fetchChunk($chunk, $callback);
            $results = array_merge($results, $chunkResults);
        }
        
        return $results;
    }
    
    /**
     * Fetch a chunk of URLs
     */
    private function fetchChunk($urls, $callback = null) {
        $multiHandle = curl_multi_init();
        $curlHandles = [];
        $results = [];
        
        // Add all URLs to multi handle
        foreach ($urls as $url) {
            $ch = $this->createCurlHandle($url);
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$url] = $ch;
        }
        
        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);
        
        // Collect results
        foreach ($curlHandles as $url => $ch) {
            $content = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            $result = [
                'success' => !$error && $httpCode >= 200 && $httpCode < 400,
                'content' => $content,
                'http_code' => $httpCode,
                'error' => $error,
                'size' => strlen($content),
                'url' => $url
            ];
            
            $results[$url] = $result;
            
            // Call callback if provided
            if ($callback && is_callable($callback)) {
                $callback($url, $result);
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multiHandle);
        
        return $results;
    }
    
    /**
     * Create single cURL handle
     */
    private function createCurlHandle($url) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        // Headers
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate',
            'Cache-Control: max-age=0',
            'Connection: keep-alive'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Cookie support
        if ($this->cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        }
        
        return $ch;
    }
    
    /**
     * Fetch URLs and return only successful ones
     */
    public function fetchSuccessful($urls) {
        $results = $this->fetchAll($urls);
        return array_filter($results, function($result) {
            return $result['success'];
        });
    }
    
    /**
     * Download files to directory
     */
    public function downloadFiles($urls, $directory, $callback = null) {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $downloaded = [];
        
        foreach ($urls as $url) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $filepath = $directory . '/' . $filename;
            
            $ch = $this->createCurlHandle($url);
            $fp = fopen($filepath, 'w');
            
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_exec($ch);
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $success = !curl_error($ch) && $httpCode >= 200 && $httpCode < 400;
            
            fclose($fp);
            curl_close($ch);
            
            if ($success) {
                $downloaded[] = $filepath;
                
                if ($callback) {
                    $callback($url, $filepath, filesize($filepath));
                }
            } else {
                @unlink($filepath);
            }
        }
        
        return $downloaded;
    }
}
