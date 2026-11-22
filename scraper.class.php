<?php

/**
 * @file scraper.class.php
 * Downloads files from web server directory listings ("Index of /" pages).
 *
 * Use the following search string to find open servers in Google:
 * -inurl:htm -inurl:html -intitle:"ftp" intitle:"index of /" TERM EXT
 *
 * Examples:
 * -inurl:htm -inurl:html -intitle:"ftp" intitle:"index of /" animated gif
 * -inurl:htm -inurl:html -intitle:"ftp" intitle:"index of /" ascii txt
 *
 * Requirements:
 * - PHP 7.4 or later with the cURL extension
 *
 * For usage examples, see demo.php or README.md
 */
class scraper
{
    // Settings
    private $destinationRoot = '/tmp/scraper/';
    private $cachePath = '/tmp/'; // Stored only, see setCachePath()
    private $mode = 'download';
    private $toScrape = array();
    private $excludePath = array();
    private $excludeFilename = array();
    private $search = array();
    private $FileNameProcessor = NULL;
    private $RandomLimit = 0;

    private $maxConcurrentDownloads = 10;
    private $maxRetries = 3;
    private $retryDelay = 2;
    private $connectionTimeout = 30;
    private $transferTimeout = 300;
    private $maxDownloadSpeed = 0; // 0 = unlimited
    private $logFile = null;
    private $logHandle = null;
    private $progressCallback = null;
    private $curlShareHandle = null;

    // Statistics tracking
    private $stats = array(
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'bytes_downloaded' => 0,
        'start_time' => 0,
        'end_time' => 0
    );

    /**
     * Constructor - initialize statistics
     */
    public function __construct()
    {
        $this->stats['start_time'] = microtime(true);
    }

    /**
     * Destructor - close log file if open
     */
    public function __destruct()
    {
        if ($this->logHandle) {
            fclose($this->logHandle);
        }
    }

    /**
     * Sets the destination root where downloaded files will be stored
     *
     * @param string $param Path to destination directory
     * @return $this
     */
    public function setDestinationRoot($param)
    {
        $this->destinationRoot = $param;
        if ($this->right($this->destinationRoot, 1) !== '/') {
            $this->destinationRoot .= '/';
        }
        return $this;
    }

    /**
     * Sets the cache path
     *
     * This setting has no effect. It is kept so that existing code calling
     * it still works. Partial downloads are written next to their final
     * path as "<name>.part" instead.
     *
     * @param string $param Path to cache directory
     * @return $this
     */
    public function setCachePath($param)
    {
        $this->cachePath = $param;
        if ($this->right($this->cachePath, 1) !== '/') {
            $this->cachePath .= '/';
        }
        return $this;
    }

    /**
     * Sets the mode the scraper works in
     *
     * Options:
     * - test: Creates directories and empty files without downloading
     * - search: Searches locations and returns matching results without downloading
     * - download: Downloads files and writes them to disk
     *
     * @param string $param Mode (test|search|download)
     * @return $this
     */
    public function setMode($param)
    {
        $this->mode = $param;
        return $this;
    }

    /**
     * Sets an optional file name processor to be called after the file name
     * has been determined from the HTML
     *
     * @param callable $param Function to process filenames
     * @return $this
     */
    public function setFileNameProcessor($param)
    {
        $this->FileNameProcessor = $param;
        return $this;
    }

    /**
     * Allows you to download a random selection of files
     *
     * @param int $param Total number of files to get
     * @return $this
     */
    public function setRandomLimit($param)
    {
        $this->RandomLimit = $param;
        return $this;
    }

    /**
     * Adds a location to the toScrape array
     *
     * @param string $url The URL of the location to scrape
     * @param string $location A subdirectory for items from this server
     * @param array $mimeTypes File types to download (leave empty for all)
     * @return $this
     */
    public function addLocation($url, $location = NULL, $mimeTypes = array())
    {
        if (NULL !== $location && $this->right($location, 1) !== '/') {
            $location .= '/';
        }

        $this->toScrape[] = array(
            'scrape_url' => $url,
            'destination_sub_dir' => $location,
            'mime_types_i_want' => $mimeTypes,
        );

        return $this;
    }

    /**
     * Adds or merges values to the excludePath array
     *
     * @param mixed $param String or array of paths to exclude
     * @return $this
     */
    public function excludeInPath($param)
    {
        if (is_array($param)) {
            $this->excludePath = array_merge($this->excludePath, $param);
        } elseif (mb_strlen($param) > 0) {
            $this->excludePath[] = $param;
        }
        return $this;
    }

    /**
     * Adds or merges values to the excludeFilename array
     *
     * @param mixed $param String or array of filename patterns to exclude
     * @return $this
     */
    public function excludeInFilename($param)
    {
        if (is_array($param)) {
            $this->excludeFilename = array_merge($this->excludeFilename, $param);
        } elseif (mb_strlen($param) > 0) {
            $this->excludeFilename[] = $param;
        }
        return $this;
    }

    /**
     * Adds search terms to be matched against file paths (case insensitive)
     *
     * @param mixed $param String or array of search terms
     * @return $this
     */
    public function search($param)
    {
        $searchTerms = $this->prepareSearchTerms($param);
        $this->search = array_merge($this->search, $searchTerms);
        return $this;
    }

    /**
     * Gets the destination root
     * @return string
     */
    public function getDestinationRoot()
    {
        return $this->destinationRoot;
    }

    /**
     * Gets the cache path
     * @return string
     */
    public function getCachePath()
    {
        return $this->cachePath;
    }

    /**
     * Gets the mode
     * @return string
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * Gets locations to scrape
     * @return array
     */
    public function getLocations()
    {
        return $this->toScrape;
    }

    /**
     * Gets the excluded paths array
     * @return array
     */
    public function getExcludedPaths()
    {
        return $this->excludePath;
    }

    /**
     * Gets the excluded filename array
     * @return array
     */
    public function getExcludedFilenames()
    {
        return $this->excludeFilename;
    }

    /**
     * Gets the search terms
     * @return array
     */
    public function getSearch()
    {
        return $this->search;
    }

    /**
     * Gets the file name processor
     * @return callable|null
     */
    public function getFileNameProcessor()
    {
        return $this->FileNameProcessor;
    }

    /**
     * Returns the random limit
     * @return int
     */
    public function getRandomLimit()
    {
        return $this->RandomLimit;
    }

    /**
     * Set maximum number of concurrent downloads (1-50)
     *
     * @param int $count Number of concurrent downloads
     * @return $this
     */
    public function setMaxConcurrentDownloads($count)
    {
        $this->maxConcurrentDownloads = max(1, min(50, $count));
        return $this;
    }

    /**
     * Set how many times a failed download is retried
     *
     * A download that fails with a cURL error, HTTP 408, 429 or 5xx is tried
     * again up to this many times. 0 turns retries off.
     *
     * @param int $retries Number of retries
     * @return $this
     */
    public function setMaxRetries($retries)
    {
        $this->maxRetries = max(0, $retries);
        return $this;
    }

    /**
     * Set the delay before the first retry in seconds
     *
     * The delay doubles for each further retry of the same file: with 2,
     * the waits are 2, 4, 8 and so on. Other downloads continue meanwhile.
     *
     * @param int|float $seconds Delay in seconds (0 for no delay)
     * @return $this
     */
    public function setRetryDelay($seconds)
    {
        $this->retryDelay = max(0, $seconds);
        return $this;
    }

    /**
     * Set connection timeout in seconds
     *
     * @param int $seconds Timeout in seconds
     * @return $this
     */
    public function setConnectionTimeout($seconds)
    {
        $this->connectionTimeout = max(5, $seconds);
        return $this;
    }

    /**
     * Set transfer timeout in seconds
     *
     * @param int $seconds Timeout in seconds
     * @return $this
     */
    public function setTransferTimeout($seconds)
    {
        $this->transferTimeout = max(10, $seconds);
        return $this;
    }

    /**
     * Set maximum download speed in bytes per second (0 = unlimited)
     *
     * @param int $bytesPerSecond Speed limit in bytes/sec
     * @return $this
     */
    public function setMaxDownloadSpeed($bytesPerSecond)
    {
        $this->maxDownloadSpeed = max(0, $bytesPerSecond);
        return $this;
    }

    /**
     * Enable logging to a file
     *
     * @param string $logFilePath Path to log file
     * @return $this
     */
    public function enableLogging($logFilePath)
    {
        $this->logFile = $logFilePath;
        $this->logHandle = fopen($logFilePath, 'a');
        $this->log("=== Scraper session started ===");
        return $this;
    }

    /**
     * Set a custom progress callback function
     *
     * @param callable $callback Function to call with progress updates
     * @return $this
     */
    public function setProgressCallback($callback)
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Get download statistics
     *
     * @return array Statistics array
     */
    public function getStats()
    {
        $this->stats['end_time'] = microtime(true);
        $this->stats['duration'] = $this->stats['end_time'] - $this->stats['start_time'];
        return $this->stats;
    }

    /**
     * Start the scraper on the locations to scrape
     *
     * @throws Exception
     * @return array|void Returns links in search mode, void otherwise
     */
    public function scrape()
    {
        // Set PHP configuration for long-running operations
        set_time_limit(0);
        ini_set('memory_limit', '3000M');
        ini_set('open_basedir', FALSE);

        $this->log("Starting scrape of " . count($this->getLocations()) . " location(s)");

        // Get the links from the server
        $links = $this->getLinks($this->getLocations());

        $this->stats['total'] = count($links);
        $this->log("Found {$this->stats['total']} files");

        // Apply random limit if set
        if ($this->getRandomLimit() > 0 && count($links) > $this->getRandomLimit()) {
            shuffle($links);
            $links = array_slice($links, 0, $this->getRandomLimit());
            $this->stats['total'] = count($links);
            $this->log("Limited to {$this->stats['total']} random files");
        }

        echo "Found " . count($links) . " files to process\n";

        // If search mode, just return the links
        if ($this->getMode() === 'search') {
            foreach ($links as $link) {
                echo 'Found : ' . str_replace($this->getDestinationRoot(), '/', $link['save_path'])
                    . "\n" . "File Location: " . $link['link'] . "\n";
            }
            return $links;
        }

        // Download or test mode - use parallel processing
        $results = $this->downloadInParallel($links);

        // Print summary
        echo "\nProcessing complete!\n";
        echo "Successful: {$this->stats['success']}\n";
        echo "Failed: {$this->stats['failed']}\n";
        echo "Skipped: {$this->stats['skipped']}\n";

        $this->log("Processing complete. Success: {$this->stats['success']}, Failed: {$this->stats['failed']}, Skipped: {$this->stats['skipped']}");

        return $results;
    }

    /**
     * Download files in parallel using a cURL multi handle.
     *
     * Each file is written to "<save path>.part" and renamed into place only
     * when the transfer finishes with a 2xx status, so an error page or a
     * broken transfer never ends up under the real file name.
     *
     * A failed transfer (a cURL error, HTTP 408, 429 or any 5xx status) is
     * retried up to maxRetries times. Before retry n it waits
     * retryDelay * 2^(n-1) seconds; other transfers keep running while it
     * waits. Other 4xx statuses, such as 404, are not retried.
     *
     * @param array $links Array of link information
     * @return array Results of download operations
     */
    private function downloadInParallel($links)
    {
        $queue = $links;
        $waiting = array(); // Failed transfers waiting for their retry time
        $active = array();
        $results = array();
        $mh = curl_multi_init();

        // Set max concurrent connections
        if (function_exists('curl_multi_setopt')) {
            curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->maxConcurrentDownloads);
        }

        $processed = 0;
        $total = count($links);

        while (!empty($queue) || !empty($waiting) || !empty($active)) {
            $now = microtime(true);

            // Fill the active pool, retries that are due first
            while (count($active) < $this->maxConcurrentDownloads) {
                $attempt = 1;
                $retryKey = $this->nextDueRetry($waiting, $now);
                if ($retryKey !== null) {
                    $link = $waiting[$retryKey]['link'];
                    $attempt = $waiting[$retryKey]['attempt'];
                    unset($waiting[$retryKey]);
                } elseif (!empty($queue)) {
                    $link = array_shift($queue);
                } else {
                    break;
                }

                if ($attempt === 1) {
                    // Skip files with very long names
                    if (mb_strlen($link['save_path']) > 255) {
                        $this->stats['skipped']++;
                        $processed++;
                        $this->reportProgress($processed, $total);
                        continue;
                    }

                    // Create destination directory
                    $this->destinationDirectory($link['destination']);

                    if ($this->getMode() === 'test') {
                        echo 'Writing empty file: ' . str_replace($this->getDestinationRoot(), '/', $link['save_path']) . "\n";
                        touch($link['save_path']);
                        $results[] = array('success' => true, 'link' => $link);
                        $this->stats['success']++;
                        $processed++;
                        $this->reportProgress($processed, $total);
                        continue;
                    }

                    if ($this->getMode() !== 'download') {
                        continue;
                    }

                    // Never overwrite a file that is already on disk
                    if (file_exists($link['save_path'])) {
                        $this->stats['skipped']++;
                        $processed++;
                        $this->reportProgress($processed, $total);
                        continue;
                    }

                    echo 'Downloading: ' . str_replace($this->getDestinationRoot(), '/', $link['save_path']) . "\n";
                } else {
                    echo 'Retrying (attempt ' . $attempt . '): ' . str_replace($this->getDestinationRoot(), '/', $link['save_path']) . "\n";
                }

                $tmpPath = $link['save_path'] . '.part';
                $fp = fopen($tmpPath, 'wb');
                if ($fp === false) {
                    $this->stats['failed']++;
                    $this->log("Failed: cannot write $tmpPath");
                    $results[] = array('success' => false, 'link' => $link, 'http_code' => 0,
                        'curl_error' => 'Cannot open ' . $tmpPath, 'attempts' => $attempt);
                    $processed++;
                    $this->reportProgress($processed, $total);
                    continue;
                }

                $ch = $this->createCurlHandle($link['link'], $fp);
                curl_multi_add_handle($mh, $ch);
                $active[$this->handleKey($ch)] = array(
                    'handle' => $ch,
                    'link' => $link,
                    'start_time' => microtime(true),
                    'fp' => $fp,
                    'tmp_path' => $tmpPath,
                    'attempt' => $attempt,
                );
            }

            if (empty($active)) {
                // Nothing running: sleep until the next retry is due
                if (!empty($waiting)) {
                    $sleep = $this->secondsToNextRetry($waiting, microtime(true));
                    if ($sleep > 0) {
                        usleep((int)ceil($sleep * 1000000));
                    }
                }
                continue;
            }

            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            // Wait for activity, but not past the next retry time
            if ($running > 0) {
                $timeout = 0.5;
                if (!empty($waiting)) {
                    $timeout = max(0.0, min($timeout, $this->secondsToNextRetry($waiting, microtime(true))));
                }
                curl_multi_select($mh, $timeout);
            }

            // Check for completed downloads
            while ($info = curl_multi_info_read($mh)) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                $ch = $info['handle'];
                $key = $this->handleKey($ch);
                if (!isset($active[$key])) {
                    continue;
                }

                $downloadInfo = $active[$key];
                unset($active[$key]);

                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $downloadTime = microtime(true) - $downloadInfo['start_time'];
                $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                $curlError = $info['result'] === CURLE_OK ? '' : curl_error($ch);

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                fclose($downloadInfo['fp']);

                $success = ($info['result'] === CURLE_OK && $httpCode >= 200 && $httpCode < 300);
                if ($success && !rename($downloadInfo['tmp_path'], $downloadInfo['link']['save_path'])) {
                    $success = false;
                    $curlError = 'Cannot rename ' . $downloadInfo['tmp_path'];
                }
                if (!$success && file_exists($downloadInfo['tmp_path'])) {
                    unlink($downloadInfo['tmp_path']);
                }

                $attempt = $downloadInfo['attempt'];
                $retryable = $info['result'] !== CURLE_OK
                    || $httpCode >= 500 || $httpCode === 408 || $httpCode === 429;

                if (!$success && $retryable && $attempt <= $this->maxRetries) {
                    $delay = $this->retryDelay * pow(2, $attempt - 1);
                    $this->log("Retry in {$delay}s: {$downloadInfo['link']['link']} - HTTP $httpCode - $curlError");
                    $waiting[] = array(
                        'link' => $downloadInfo['link'],
                        'attempt' => $attempt + 1,
                        'ready_at' => microtime(true) + $delay,
                    );
                    continue;
                }

                $result = array(
                    'success' => $success,
                    'link' => $downloadInfo['link'],
                    'http_code' => $httpCode,
                    'curl_error' => $curlError,
                    'attempts' => $attempt,
                    'time' => $downloadTime,
                    'size' => $fileSize,
                    'speed' => ($fileSize > 0 && $downloadTime > 0) ? $fileSize / $downloadTime : 0
                );

                if ($success) {
                    $this->stats['success']++;
                    $this->stats['bytes_downloaded'] += $fileSize;
                } else {
                    $this->stats['failed']++;
                    $this->log("Failed: {$downloadInfo['link']['link']} - HTTP $httpCode - $curlError");
                }

                $results[] = $result;
                $this->logDownload($result);

                $processed++;
                $this->reportProgress($processed, $total, array('speed' => $result['speed']));
            }
        }

        curl_multi_close($mh);
        return $results;
    }

    /**
     * Returns the key of a waiting retry that is due, or null if none is
     *
     * @param array $waiting Waiting retries
     * @param float $now Current time
     * @return int|null
     */
    private function nextDueRetry($waiting, $now)
    {
        foreach ($waiting as $key => $item) {
            if ($item['ready_at'] <= $now) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Returns the number of seconds until the earliest waiting retry is due
     *
     * @param array $waiting Waiting retries (not empty)
     * @param float $now Current time
     * @return float
     */
    private function secondsToNextRetry($waiting, $now)
    {
        $next = min(array_column($waiting, 'ready_at'));
        return max(0.0, $next - $now);
    }

    /**
     * Returns an array key for a cURL handle (a resource before PHP 8, an
     * object from PHP 8)
     *
     * @param resource|object $ch cURL handle
     * @return int
     */
    private function handleKey($ch)
    {
        return is_object($ch) ? spl_object_id($ch) : (int)$ch;
    }

    /**
     * Create a cURL handle that writes the response body to a file
     *
     * @param string $url URL to download
     * @param resource $fp Open file handle to write to
     * @return resource|object cURL handle
     */
    private function createCurlHandle($url, $fp)
    {
        $ch = curl_init($url);

        // Standard options
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->transferTimeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');

        // Abort transfers slower than 10 KB/s for 60 seconds
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 10240);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 60);

        // Bandwidth limiting if set
        if ($this->maxDownloadSpeed > 0) {
            curl_setopt($ch, CURLOPT_MAX_RECV_SPEED_LARGE, $this->maxDownloadSpeed);
        }

        // Share DNS cache, TLS sessions and connections between handles
        if ($this->curlShareHandle === null) {
            $this->curlShareHandle = curl_share_init();
            curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
            if (defined('CURL_LOCK_DATA_CONNECT')) {
                curl_share_setopt($this->curlShareHandle, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
            }
        }
        curl_setopt($ch, CURLOPT_SHARE, $this->curlShareHandle);

        return $ch;
    }

    /**
     * Report progress to callback or console
     *
     * @param int $current Current count
     * @param int $total Total count
     * @param array $additionalData Additional data to pass to callback
     */
    private function reportProgress($current, $total, $additionalData = array())
    {
        if ($this->progressCallback && is_callable($this->progressCallback)) {
            call_user_func($this->progressCallback, array(
                'current' => $current,
                'total' => $total,
                'percent' => round(($current / $total) * 100, 2),
                'data' => $additionalData
            ));
        } else {
            // Default console output
            $percent = round(($current / $total) * 100, 2);
            echo "\rProgress: $current/$total ($percent%)";
            if ($current === $total) {
                echo "\n";
            }
        }
    }

    /**
     * Log a message to the log file
     *
     * @param string $message Message to log
     */
    private function log($message)
    {
        if (!$this->logHandle) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        fwrite($this->logHandle, "[$timestamp] $message\n");
    }

    /**
     * Log download result
     *
     * @param array $result Download result information
     */
    private function logDownload($result)
    {
        if (!$this->logHandle) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        $url = $result['link']['link'];
        $size = isset($result['size']) ? round($result['size'] / 1024, 2) . ' KB' : 'N/A';
        $time = isset($result['time']) ? round($result['time'], 2) . 's' : 'N/A';
        $speed = isset($result['speed']) ? round($result['speed'] / 1024, 2) . ' KB/s' : 'N/A';

        $logLine = "[$timestamp] $status | $url | Size: $size | Time: $time | Speed: $speed\n";
        fwrite($this->logHandle, $logLine);
    }

    /**
     * Get file links from locations, following subdirectory links
     *
     * @param array $locations Locations to scrape
     * @param array $log Log of visited URLs
     * @param array $links Accumulated links
     * @return array Array of file links
     */
    private function getLinks($locations, $log = array(), $links = array())
    {
        foreach ($locations as $var) {
            $html = $this->curlGet($var['scrape_url']);
            $parts = parse_url($var['scrape_url']);

            preg_match_all('/<a\s[^>]*href\s*=\s*["\']?([^"\'>]+)["\']?[^>]*>/i', $html, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $href = $match[1];

                $href = html_entity_decode($href, ENT_QUOTES);

                // Skip parent directory, same directory, sort links (?C=N;O=D)
                // and page anchors
                if ($href === '' || $href === '.' || $href === '..' || $href === '../'
                    || $href[0] === '?' || $href[0] === '#') {
                    continue;
                }

                // Make absolute URL
                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
                    // Already absolute (http:, https:, mailto: and so on)
                } elseif (strpos($href, '//') === 0) {
                    $href = $parts['scheme'] . ':' . $href;
                } elseif ($href[0] === '/') {
                    $href = $parts['scheme'] . '://' . $parts['host']
                        . (isset($parts['port']) ? ':' . $parts['port'] : '') . $href;
                } else {
                    $href = rtrim($var['scrape_url'], '/') . '/' . $href;
                }

                // Only follow links below the location being scraped, which
                // also drops "Parent Directory" links written as absolute paths
                $base = rtrim($var['scrape_url'], '/') . '/';
                if (strpos($href, $base) !== 0 || $href === $base) {
                    continue;
                }

                $pieces = explode('/', rtrim($href, '/'));
                $mime = pathinfo(end($pieces), PATHINFO_EXTENSION);

                // If this is a directory
                if (substr($href, -1) === '/') {
                    // Check exclusions
                    $exclude = false;
                    foreach ($this->getExcludedPaths() as $value) {
                        if (mb_strpos($href, $value) !== false) {
                            $exclude = true;
                            break;
                        }
                    }

                    if (!$exclude && !in_array($href, $log)) {
                        $log[] = $href;
                        $dir_to_scrape = array(array(
                            'scrape_url' => $href,
                            'host' => $parts['scheme'] . '://' . $parts['host'],
                            'destination_sub_dir' => $var['destination_sub_dir'] .
                                trim($this->sanitize(urldecode($pieces[count($pieces) - 1]))) . '/',
                            'mime_types_i_want' => $var['mime_types_i_want'],
                        ));

                        // Recursive call
                        $links = $this->getLinks($dir_to_scrape, $log, $links);
                    }
                }
                // If this is a file
                elseif (empty($var['mime_types_i_want']) || in_array($mime, $var['mime_types_i_want'])) {
                    $local_file_path = urldecode($this->getDestinationRoot() . $var['destination_sub_dir']);
                    $file_name = trim($this->sanitize(urldecode(end($pieces))));

                    // Apply file name processor if set
                    if ($this->getFileNameProcessor() !== null && is_callable($this->getFileNameProcessor())) {
                        $file_name = call_user_func($this->getFileNameProcessor(), $file_name);
                    }

                    // Check exclusions and search criteria
                    if ($this->shouldIncludeFile($href, $file_name) && !file_exists($local_file_path . $file_name)) {
                        $links[] = array(
                            'save_path' => $local_file_path . $file_name,
                            'link' => $href,
                            'file_name' => $file_name,
                            'destination' => $local_file_path,
                        );
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Check if file should be included based on exclusions and search
     *
     * @param string $href Full URL
     * @param string $fileName File name
     * @return bool True if file should be included
     */
    private function shouldIncludeFile($href, $fileName)
    {
        if (empty($fileName)) {
            return false;
        }

        // Check path exclusions
        foreach ($this->getExcludedPaths() as $value) {
            if (mb_strpos($href, $value) !== false) {
                return false;
            }
        }

        // Check filename exclusions
        foreach ($this->getExcludedFilenames() as $value) {
            if (mb_strpos($fileName, $value) !== false) {
                return false;
            }
        }

        // Check search inclusion
        if (count($this->getSearch()) > 0) {
            $found = false;
            foreach ($this->getSearch() as $value) {
                if (mb_strpos(strtolower($fileName), $value) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create destination directory if it doesn't exist
     *
     * @param string $destination Directory path
     */
    private function destinationDirectory($destination)
    {
        if ($this->getMode() != 'search') {
            if (!is_dir($destination)) {
                mkdir($destination, 0775, true);
            }
        }
    }

    /**
     * Convert a string to file/URL safe form (safe for Windows)
     *
     * @param string $string String to sanitize
     * @return string Sanitized string
     */
    private function sanitize($string = '')
    {
        $string = preg_replace('/[^\w\-()\&\#\%\[\]\'\.]+/u', ' ', $string);
        return trim(preg_replace('/  +/u', ' ', $string));
    }

    /**
     * Prepare search terms from manual input
     *
     * @param mixed $param String or array of search terms
     * @return array Prepared search terms
     */
    private function prepareSearchTerms($param)
    {
        if (is_array($param)) {
            return array_map('strtolower', $param);
        }
        return array(strtolower($param));
    }

    /**
     * Simple cURL GET wrapper (for getting directory listings)
     *
     * @param string $url URL to fetch
     * @param array $opts Options array
     * @return string Response data
     */
    private function curlGet($url, $opts = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $data = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // An error page is not a listing
        if ($data === false || $httpCode >= 400) {
            $this->log("Cannot read listing: $url - HTTP $httpCode");
            return '';
        }

        return $data;
    }

    /**
     * Returns right n chars from input
     *
     * @param string $str String to cut
     * @param int $count Length to cut
     * @return string
     */
    private function right($str, $count)
    {
        return mb_substr($str, ($count * -1));
    }

    /**
     * Returns left n chars from input
     *
     * @param string $str String to cut
     * @param int $count Length to cut
     * @return string
     */
    private function left($str, $count)
    {
        return mb_substr($str, 0, $count);
    }
}

