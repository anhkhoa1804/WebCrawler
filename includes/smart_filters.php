<?php
/**
 * Smart Filters Module - Heuristic-based File Filtering
 * Filter files by size, dimensions, quality without AI
 */

class SmartFilters {
    
    /**
     * Filter images by size (remove tiny icons, huge raw images)
     */
    public static function filterImagesBySize($imageUrls, $minSize = 5000, $maxSize = 10485760) {
        $filtered = [];
        
        foreach ($imageUrls as $url) {
            // Quick check: estimate from URL
            if (self::isLikelyIcon($url)) {
                continue; // Skip icons
            }
            
            // HEAD request to get size without downloading
            $size = self::getFileSize($url);
            
            if ($size === false) {
                // If can't get size, keep it
                $filtered[] = $url;
                continue;
            }
            
            // Filter by size range
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Check if URL looks like an icon/logo
     */
    private static function isLikelyIcon($url) {
        $iconPatterns = [
            '/icon/i',
            '/logo/i',
            '/favicon/i',
            '/sprite/i',
            '/thumb/i',
            '/avatar/i',
            '/emoji/i',
            '/badge/i',
            '/button/i',
            '/-16x16\./i',
            '/-32x32\./i',
            '/-48x48\./i'
        ];
        
        foreach ($iconPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Filter documents by size (remove empty or corrupt files)
     */
    public static function filterDocumentsBySize($docUrls, $minSize = 1000, $maxSize = 104857600) {
        $filtered = [];
        
        foreach ($docUrls as $url) {
            $size = self::getFileSize($url);
            
            if ($size === false) {
                $filtered[] = $url;
                continue;
            }
            
            // PDFs < 1KB are usually corrupted
            // Documents > 100MB are usually backups/archives
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Filter videos by size (remove previews, keep actual videos)
     */
    public static function filterVideosBySize($videoUrls, $minSize = 100000, $maxSize = 2147483648) {
        $filtered = [];
        
        foreach ($videoUrls as $url) {
            // Skip preview/thumbnail videos
            if (preg_match('/preview|thumb|sample/i', $url)) {
                continue;
            }
            
            $size = self::getFileSize($url);
            
            if ($size === false) {
                $filtered[] = $url;
                continue;
            }
            
            // Videos < 100KB are previews
            // Videos > 2GB might timeout
            if ($size >= $minSize && $size <= $maxSize) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Get file size using HEAD request (fast, doesn't download)
     */
    private static function getFileSize($url) {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        
        return $size > 0 ? (int)$size : false;
    }
    
    /**
     * Filter files by URL patterns (remove unwanted files)
     */
    public static function filterByUrlPattern($urls, $excludePatterns = []) {
        $defaultExcludes = [
            '/ads?[\\/_-]/i',           // Ads (escaped slash)
            '/banner/i',                // Banners
            '/tracking/i',              // Tracking pixels
            '/analytics/i',             // Analytics
            '/social[\\-_]?media/i',    // Social media buttons (escaped dash)
            '/share[\\-_]?button/i',    // Share buttons (escaped dash)
            '/pixel/i',                 // Tracking pixels
            '/sprite/i',                // CSS sprites
            '/widget/i',                // Widgets
            '/placeholder/i',           // Placeholders
            '/\.tmp\./i',               // Temp files
            '/cache/i'                  // Cache files
        ];
        
        $patterns = array_merge($defaultExcludes, $excludePatterns);
        $filtered = [];
        
        foreach ($urls as $url) {
            $excluded = false;
            
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $url)) {
                    $excluded = true;
                    break;
                }
            }
            
            if (!$excluded) {
                $filtered[] = $url;
            }
        }
        
        return $filtered;
    }
    
    /**
     * Deduplicate URLs (remove similar/duplicate files)
     */
    public static function deduplicateUrls($urls) {
        $unique = [];
        $seen = [];
        
        foreach ($urls as $url) {
            // Normalize URL
            $normalized = strtolower(trim($url));
            $normalized = preg_replace('/[?#].*$/', '', $normalized); // Remove query/fragment
            
            // Extract filename
            $filename = basename(parse_url($normalized, PHP_URL_PATH));
            
            // Check if similar file already seen
            if (!isset($seen[$filename])) {
                $unique[] = $url;
                $seen[$filename] = true;
            }
        }
        
        return $unique;
    }
    
    /**
     * Sort files by quality score (prioritize high-quality files)
     */
    public static function sortByQuality($files) {
        usort($files, function($a, $b) {
            $scoreA = self::calculateQualityScore($a);
            $scoreB = self::calculateQualityScore($b);
            return $scoreB - $scoreA; // Descending order
        });
        
        return $files;
    }
    
    /**
     * Calculate quality score for a file
     */
    private static function calculateQualityScore($url) {
        $score = 100;
        
        // Bonus for high-res images
        if (preg_match('/\d{3,4}x\d{3,4}|hd|4k|high[_-]?res/i', $url)) {
            $score += 50;
        }
        
        // Penalty for low-res images
        if (preg_match('/small|tiny|mini|thumb/i', $url)) {
            $score -= 50;
        }
        
        // Bonus for specific keywords
        if (preg_match('/original|master|full|download/i', $url)) {
            $score += 30;
        }
        
        // Penalty for thumbnails/previews
        if (preg_match('/thumb|preview|sample|demo/i', $url)) {
            $score -= 40;
        }
        
        // Bonus for common high-quality formats
        if (preg_match('/\.(png|tiff|wav|flac|pdf)$/i', $url)) {
            $score += 20;
        }
        
        return $score;
    }
    
    /**
     * Apply all filters in one pass
     */
    public static function applyAllFilters($files, $options = []) {
        $type = $options['type'] ?? 'image';
        
        // Step 1: Filter by URL patterns
        $files = self::filterByUrlPattern($files);
        
        // Step 2: Deduplicate
        $files = self::deduplicateUrls($files);
        
        // Step 3: Filter by size based on type
        switch ($type) {
            case 'image':
                $files = self::filterImagesBySize($files, 
                    $options['min_size'] ?? 5000, 
                    $options['max_size'] ?? 10485760
                );
                break;
            case 'document':
                $files = self::filterDocumentsBySize($files,
                    $options['min_size'] ?? 1000,
                    $options['max_size'] ?? 104857600
                );
                break;
            case 'video':
                $files = self::filterVideosBySize($files,
                    $options['min_size'] ?? 100000,
                    $options['max_size'] ?? 2147483648
                );
                break;
        }
        
        // Step 4: Sort by quality
        if ($options['sort_by_quality'] ?? true) {
            $files = self::sortByQuality($files);
        }
        
        return $files;
    }
}
