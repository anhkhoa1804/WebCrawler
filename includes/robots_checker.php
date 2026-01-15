<?php
class RobotsChecker {
    private $robotsContent = '';
    private $disallowedPaths = [];
    private $userAgent = '*';
    
    public function __construct($baseUrl) {
        $this->loadRobotsTxt($baseUrl);
    }
    
    private function loadRobotsTxt($baseUrl) {
        $parsedUrl = parse_url($baseUrl);
        $robotsUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/robots.txt';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $robotsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $content) {
            $this->robotsContent = $content;
            $this->parseRobotsTxt();
        }
    }
    
    private function parseRobotsTxt() {
        $lines = explode("\n", $this->robotsContent);
        $currentUserAgent = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            if (stripos($line, 'User-agent:') === 0) {
                $currentUserAgent = trim(substr($line, 11));
            }
            
            if (($currentUserAgent === '*' || $currentUserAgent === $this->userAgent) &&
                stripos($line, 'Disallow:') === 0) {
                $path = trim(substr($line, 9));
                if (!empty($path)) {
                    $this->disallowedPaths[] = $path;
                }
            }
        }
    }
    
    public function isAllowed($url) {
        if (empty($this->disallowedPaths)) {
            return true;
        }
        
        $path = parse_url($url, PHP_URL_PATH);
        
        if (!$path) {
            return true;
        }
        
        foreach ($this->disallowedPaths as $disallowedPath) {
            if ($disallowedPath === '/') {
                return false;
            }
            
            if (strpos($path, $disallowedPath) === 0) {
                return false;
            }
            
            if (strpos($disallowedPath, '*') !== false) {
                $pattern = str_replace(['*', '/'], ['.*', '\/'], $disallowedPath);
                if (preg_match('/^' . $pattern . '/', $path)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    public function getDisallowedPaths() {
        return $this->disallowedPaths;
    }
}
?>
