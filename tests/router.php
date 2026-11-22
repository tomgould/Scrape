<?php

/**
 * Router for PHP's built-in web server, used by tests/run.php.
 *
 * Serves a fake Apache-style "Index of /" listing under /files/, with a
 * subdirectory, a file that returns 404 and a file that fails twice with
 * HTTP 500 before it succeeds. Every request is appended to
 * $SCRAPE_TEST_STATE/requests.log so the tests can count them.
 */

$state = getenv('SCRAPE_TEST_STATE');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents($state . '/requests.log', microtime(true) . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);

function listing($title, array $entries)
{
    header('Content-Type: text/html');
    echo "<html><head><title>Index of $title</title></head><body>\n";
    echo "<h1>Index of $title</h1><pre>\n";
    echo '<a href="?C=N;O=D">Name</a> <a href="?C=M;O=A">Last modified</a>' . "\n";
    echo '<a href="' . dirname(rtrim($title, '/')) . '/">Parent Directory</a>' . "\n";
    foreach ($entries as $entry) {
        echo '<a href="' . $entry . '">' . $entry . "</a>\n";
    }
    echo "</pre></body></html>\n";
}

$files = array(
    '/files/a.txt' => "alpha\n",
    '/files/b.pdf' => "%PDF-fake\n",
    '/files/c d.txt' => "space in name\n",
    '/files/sub/e.txt' => "echo\n",
    '/files/sub/f.pdf' => "%PDF-sub\n",
);

if ($path === '/files/') {
    listing('/files/', array('a.txt', 'b.pdf', 'c%20d.txt', 'missing.txt', 'flaky.txt', 'sub/'));
    return true;
}

if ($path === '/files/sub/') {
    listing('/files/sub/', array('e.txt', 'f.pdf'));
    return true;
}

if (isset($files[$path])) {
    header('Content-Type: application/octet-stream');
    echo $files[$path];
    return true;
}

if ($path === '/files/flaky.txt') {
    $counter = $state . '/flaky.count';
    $count = (int)@file_get_contents($counter) + 1;
    file_put_contents($counter, (string)$count);
    if ($count <= 2) {
        http_response_code(500);
        echo "server error $count\n";
        return true;
    }
    echo "flaky ok\n";
    return true;
}

http_response_code(404);
echo "<html><body>Not Found</body></html>\n";
return true;
