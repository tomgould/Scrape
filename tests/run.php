<?php

/**
 * Offline tests for scraper.class.php.
 *
 * Starts PHP's built-in web server on a free local port with tests/router.php,
 * runs the scraper against it and checks what ends up on disk.
 *
 * Usage: php tests/run.php
 */

require __DIR__ . '/../scraper.class.php';

$passed = 0;
$failed = 0;

function check($condition, $message)
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $message\n";
    } else {
        $failed++;
        echo "  FAIL $message\n";
    }
}

function removeTree($dir)
{
    // Only ever delete the temporary folder this script created
    if (strpos($dir, sys_get_temp_dir() . '/scrape-test-') !== 0 || !is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function listFiles($dir)
{
    $found = array();
    if (!is_dir($dir)) {
        return $found;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($items as $item) {
        if ($item->isFile()) {
            $found[] = substr($item->getPathname(), strlen($dir) + 1);
        }
    }
    sort($found);
    return $found;
}

/** Returns request times for a path, and resets nothing. */
function requests($state, $path)
{
    $times = array();
    $log = @file($state . '/requests.log', FILE_IGNORE_NEW_LINES) ?: array();
    foreach ($log as $line) {
        list($time, $requested) = explode(' ', $line, 2);
        if ($requested === $path) {
            $times[] = (float)$time;
        }
    }
    return $times;
}

function resetServerState($state)
{
    @unlink($state . '/requests.log');
    @unlink($state . '/flaky.count');
}

/** Runs a configured scraper with its console output captured. */
function runScraper(scraper $scraper)
{
    $scraper->setProgressCallback(function () {
    });
    ob_start();
    $result = $scraper->scrape();
    ob_end_clean();
    return $result;
}

// Temporary folder for downloads and server state
$tmp = sys_get_temp_dir() . '/scrape-test-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$state = $tmp . '/state';
mkdir($state);

// Free port
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);

$env = array_merge(getenv(), array('SCRAPE_TEST_STATE' => $state));
$server = proc_open(
    array(PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/router.php'),
    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $state . '/server.out', 'w'), 2 => array('file', $state . '/server.out', 'a')),
    $pipes,
    null,
    $env
);

register_shutdown_function(function () use ($server, $tmp) {
    proc_terminate($server);
    proc_close($server);
    removeTree($tmp);
});

$deadline = microtime(true) + 5;
while (!@fsockopen('127.0.0.1', $port) && microtime(true) < $deadline) {
    usleep(50000);
}

$base = "http://127.0.0.1:$port/files/";

echo "Download everything, with retries\n";
resetServerState($state);
$dest = $tmp . '/all';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)
    ->setMaxRetries(3)
    ->setRetryDelay(1)
    ->addLocation($base, 'site');
$results = runScraper($scraper);
$site = $dest . '/site';
check(listFiles($site) === array('a.txt', 'b.pdf', 'c d.txt', 'flaky.txt', 'sub/e.txt', 'sub/f.pdf'),
    'exactly the expected files are on disk: ' . implode(', ', listFiles($site)));
check(@file_get_contents("$site/a.txt") === "alpha\n", 'a.txt has the right content');
check(@file_get_contents("$site/c d.txt") === "space in name\n", 'URL-encoded name saved decoded');
check(@file_get_contents("$site/sub/e.txt") === "echo\n", 'file in a subdirectory downloaded');
check(!file_exists("$site/missing.txt"), '404 not saved');
check(!file_exists("$site/missing.txt.part"), 'no partial file left for the 404');
check(count(requests($state, '/files/missing.txt')) === 1, '404 not retried');
check(@file_get_contents("$site/flaky.txt") === "flaky ok\n", 'flaky file saved after retries');
$flaky = requests($state, '/files/flaky.txt');
check(count($flaky) === 3, 'flaky file requested 3 times (got ' . count($flaky) . ')');
if (count($flaky) === 3) {
    check($flaky[1] - $flaky[0] >= 0.9, 'first retry waited about 1s');
    check($flaky[2] - $flaky[1] >= 1.9, 'second retry waited about 2s');
}
$stats = $scraper->getStats();
check($stats['success'] === 6 && $stats['failed'] === 1, "stats: {$stats['success']} success, {$stats['failed']} failed");
$flakyResult = array_values(array_filter($results, function ($r) {
    return $r['link']['file_name'] === 'flaky.txt';
}));
check(isset($flakyResult[0]['attempts']) && $flakyResult[0]['attempts'] === 3, 'result records 3 attempts');

echo "Run again on the same folder\n";
resetServerState($state);
file_put_contents("$site/a.txt", "local copy\n");
$scraper = new scraper();
$scraper->setDestinationRoot($dest)->setMaxRetries(0)->addLocation($base, 'site');
runScraper($scraper);
check(@file_get_contents("$site/a.txt") === "local copy\n", 'existing file left as it is');
check(count(requests($state, '/files/a.txt')) === 0, 'existing file not requested');
check(count(requests($state, '/files/flaky.txt')) === 0, 'previously retried file not requested');
check(count(requests($state, '/files/missing.txt')) === 1, 'missing file tried again');

echo "Filter by file type\n";
resetServerState($state);
$dest = $tmp . '/pdf';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)->addLocation($base, 'site', array('pdf'));
runScraper($scraper);
check(listFiles("$dest/site") === array('b.pdf', 'sub/f.pdf'), 'only pdf files: ' . implode(', ', listFiles("$dest/site")));
check(count(requests($state, '/files/a.txt')) === 0, 'txt file not requested');

echo "Too few retries\n";
resetServerState($state);
$dest = $tmp . '/oneretry';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)->setMaxRetries(1)->setRetryDelay(0)->addLocation($base, 'site', array('txt'));
runScraper($scraper);
check(count(requests($state, '/files/flaky.txt')) === 2, 'flaky file tried twice with 1 retry');
check(!file_exists("$dest/site/flaky.txt"), 'failed file not saved');
check(!file_exists("$dest/site/flaky.txt.part"), 'no partial file left');

echo "Retries off\n";
resetServerState($state);
$dest = $tmp . '/noretry';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)->setMaxRetries(0)->addLocation($base, 'site', array('txt'));
runScraper($scraper);
check(count(requests($state, '/files/flaky.txt')) === 1, 'flaky file tried once');

echo "Exclusions and search mode\n";
resetServerState($state);
$dest = $tmp . '/search';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)
    ->setMode('search')
    ->excludeInPath('/sub/')
    ->excludeInFilename('flaky')
    ->search(array('A.TXT', 'c d'))
    ->addLocation($base, 'site');
$links = runScraper($scraper);
$names = array_column($links, 'file_name');
sort($names);
check($names === array('a.txt', 'c d.txt'), 'search and exclusions pick the right files: ' . implode(', ', $names));
check(!is_dir($dest), 'search mode writes nothing');

echo "Test mode\n";
resetServerState($state);
$dest = $tmp . '/testmode';
$scraper = new scraper();
$scraper->setDestinationRoot($dest)->setMode('test')->addLocation($base, 'site', array('pdf'));
runScraper($scraper);
check(listFiles("$dest/site") === array('b.pdf', 'sub/f.pdf'), 'test mode creates the files');
check(filesize("$dest/site/b.pdf") === 0, 'test mode files are empty');
check(count(requests($state, '/files/b.pdf')) === 0, 'test mode downloads nothing');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
