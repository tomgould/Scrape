<?php

/**
 * Demo file showing how to use the improved Scraper class
 */

require_once 'scraper_improved.class.php';

// Example 1: Basic usage with parallel downloads
echo "=== Example 1: Basic Parallel Download ===\n\n";

$scraper = new ScraperImproved();
$scraper->setDestinationRoot('./downloads/')
    ->setCachePath('./cache/')
    ->setMode('download')
    ->setMaxConcurrentDownloads(15)  // Download 15 files at once
    ->addLocation('http://example.com/files/', 'example', ['pdf', 'jpg', 'png'])
    ->run();

// Example 2: With logging and progress callback
echo "\n\n=== Example 2: With Logging and Progress ===\n\n";

$scraper2 = new ScraperImproved();
$scraper2->setDestinationRoot('./downloads/')
    ->setMaxConcurrentDownloads(20)
    ->enableLogging('./scraper.log')
    ->setProgressCallback(function($progress) {
        // Custom progress display
        echo sprintf(
            "\rDownloading: %d/%d (%.1f%%) - %.2f MB/s",
            $progress['current'],
            $progress['total'],
            $progress['percent'],
            isset($progress['data']['speed']) ? $progress['data']['speed'] / 1048576 : 0
        );
    })
    ->addLocation('http://example.com/documents/', 'docs', ['pdf', 'docx'])
    ->run();

// Example 3: Search mode (find files without downloading)
echo "\n\n=== Example 3: Search Mode ===\n\n";

$scraper3 = new ScraperImproved();
$scraper3->setMode('search')
    ->addLocation('http://example.com/archive/', 'archive')
    ->search(['important', 'report', '2024'])  // Only files with these terms
    ->excludeInFilename(['draft', 'backup'])   // Exclude these
    ->excludeInPath('/old/');                  // Exclude this path

$foundFiles = $scraper3->run();
echo "Found " . count($foundFiles) . " matching files:\n";
foreach ($foundFiles as $file) {
    echo "  - {$file['file_name']} ({$file['link']})\n";
}

// Example 4: Advanced configuration with retry and bandwidth limiting
echo "\n\n=== Example 4: Advanced Configuration ===\n\n";

$scraper4 = new ScraperImproved();
$scraper4->setDestinationRoot('./downloads/')
    ->setMaxConcurrentDownloads(10)
    ->setMaxRetries(5)                    // Retry failed downloads up to 5 times
    ->setConnectionTimeout(60)            // 60 second connection timeout
    ->setTransferTimeout(600)             // 10 minute transfer timeout
    ->setMaxDownloadSpeed(1048576)        // Limit to 1 MB/s (optional)
    ->enableLogging('./scraper_detailed.log')
    ->addLocation('http://example.com/large-files/', 'large-files', ['zip', 'tar.gz'])
    ->setFileNameProcessor(function($filename) {
        // Custom filename processing
        return strtolower(str_replace(' ', '_', $filename));
    })
    ->run();

// Example 5: Download random subset
echo "\n\n=== Example 5: Random Subset ===\n\n";

$scraper5 = new ScraperImproved();
$scraper5->setDestinationRoot('./downloads/random/')
    ->setMaxConcurrentDownloads(10)
    ->setRandomLimit(50)  // Only download 50 random files
    ->addLocation('http://example.com/images/', 'random-images', ['jpg', 'png'])
    ->run();

// Example 6: Multiple locations
echo "\n\n=== Example 6: Multiple Sources ===\n\n";

$scraper6 = new ScraperImproved();
$scraper6->setDestinationRoot('./downloads/')
    ->setMaxConcurrentDownloads(15)
    ->enableLogging('./multi_source.log')
    ->addLocation('http://server1.com/files/', 'server1', ['pdf'])
    ->addLocation('http://server2.com/docs/', 'server2', ['docx', 'xlsx'])
    ->addLocation('http://server3.com/media/', 'server3', ['mp4', 'mp3'])
    ->run();

// Example 7: Get statistics after download
echo "\n\n=== Example 7: Statistics ===\n\n";

$scraper7 = new ScraperImproved();
$scraper7->setDestinationRoot('./downloads/')
    ->setMaxConcurrentDownloads(20)
    ->addLocation('http://example.com/files/', 'files')
    ->run();

$stats = $scraper7->getStats();
echo "Statistics:\n";
echo "  Total files: {$stats['total']}\n";
echo "  Successful: {$stats['success']}\n";
echo "  Failed: {$stats['failed']}\n";
echo "  Skipped: {$stats['skipped']}\n";
echo "  Bytes downloaded: " . round($stats['bytes_downloaded'] / 1048576, 2) . " MB\n";

// Example 8: Test mode (don't actually download)
echo "\n\n=== Example 8: Test Mode ===\n\n";

$scraperTest = new ScraperImproved();
$scraperTest->setDestinationRoot('./downloads/')
    ->setMode('test')  // Creates empty placeholder files
    ->addLocation('http://example.com/files/', 'test')
    ->run();

echo "\n\nTest mode creates directory structure and empty files without downloading.\n";

// Example 9: Real-world example - Download PDFs from a specific year
echo "\n\n=== Example 9: Real-world - Annual Reports ===\n\n";

$scraperReports = new ScraperImproved();
$scraperReports->setDestinationRoot('./annual_reports/')
    ->setMaxConcurrentDownloads(10)
    ->enableLogging('./reports_download.log')
    ->addLocation('http://company.com/investor-relations/reports/', 'reports', ['pdf'])
    ->search(['2024', 'annual', 'report'])
    ->excludeInFilename(['draft', 'preliminary'])
    ->setProgressCallback(function($progress) {
        if ($progress['current'] === $progress['total']) {
            echo "\nAll reports downloaded!\n";
        }
    })
    ->run();

// Example 10: Using all features together
echo "\n\n=== Example 10: Kitchen Sink ===\n\n";

$scraperComplete = new ScraperImproved();
$results = $scraperComplete
    // Configuration
    ->setDestinationRoot('./complete_download/')
    ->setCachePath('./cache/')
    ->setMode('download')
    
    // Performance tuning
    ->setMaxConcurrentDownloads(25)
    ->setMaxRetries(3)
    ->setConnectionTimeout(30)
    ->setTransferTimeout(300)
    
    // Logging and progress
    ->enableLogging('./complete_log.log')
    ->setProgressCallback(function($progress) {
        $percent = $progress['percent'];
        $current = $progress['current'];
        $total = $progress['total'];
        
        // Progress bar
        $barLength = 50;
        $completed = floor($barLength * $percent / 100);
        $bar = str_repeat('█', $completed) . str_repeat('░', $barLength - $completed);
        
        echo "\r[$bar] $percent% ($current/$total)";
    })
    
    // Sources
    ->addLocation('http://example1.com/files/', 'source1', ['pdf', 'docx'])
    ->addLocation('http://example2.com/media/', 'source2', ['jpg', 'png'])
    
    // Filters
    ->search(['important', 'final'])
    ->excludeInPath(['/archive/', '/old/', '/backup/'])
    ->excludeInFilename(['temp', 'tmp', 'draft'])
    
    // Limits
    ->setRandomLimit(100)
    
    // Custom filename processing
    ->setFileNameProcessor(function($filename) {
        // Remove special characters and convert to lowercase
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return strtolower($filename);
    })
    
    // Execute
    ->run();

// Print final statistics
echo "\n\nFinal Statistics:\n";
$stats = $scraperComplete->getStats();
print_r($stats);

echo "\n\n=== All Examples Complete ===\n";
