# Scrape

A PHP class that downloads files from web server directory listings (the "Index of /" pages a server shows when a folder has no index page). It reads the listing, follows the subdirectory links, and downloads the files it finds into a local folder, several at a time.

## Requirements

- PHP 7.4 or later
- The cURL and mbstring extensions

## Usage

```php
require_once 'scraper.class.php';

$scraper = new scraper();
$scraper->setDestinationRoot('./downloads/')
    ->addLocation('http://example.com/files/', 'example', array('pdf', 'jpg'))
    ->scrape();
```

This saves every `.pdf` and `.jpg` under `http://example.com/files/` and its subdirectories to `./downloads/example/`, keeping the folder structure. `demo.php` is a fuller example.

## What it does

- Reads each listing page and collects its links. A link ending in `/` is treated as a subdirectory and read in turn. Only links below the starting URL are followed, and the listing's sort links (`?C=N;O=D`) and parent directory link are ignored.
- Saves each file under the destination root, in the subfolder given to `addLocation()`, with the same relative path. Names are URL-decoded, and any character other than letters, digits, spaces and `- ( ) & # % [ ] ' .` becomes a space.
- Skips a file if a file with the same path is already on disk. It does not compare size or content, so a file that changed on the server is not fetched again. Paths longer than 255 characters are skipped too.
- Downloads up to 10 files at once (configurable) with cURL's multi interface.
- Writes each download to `<name>.part` and renames it to `<name>` only when the server answers with a 2xx status. A 404 or other error, or a broken transfer, leaves no file behind.
- Retries a download that fails with a cURL error (such as a timeout or dropped connection) or HTTP 408, 429 or 5xx, up to 3 times by default. The first retry waits 2 seconds, and the wait doubles for each further retry of the same file. Other downloads carry on while a file waits. Other 4xx responses, such as 404, are not retried.
- Aborts a transfer that runs slower than 10 KB/s for 60 seconds (this counts as a failure and is retried).
- Prints progress to standard output, or passes it to your callback instead.

Listing pages are fetched without TLS certificate checks; file downloads use cURL's normal checks.

## Modes

Set with `setMode()`:

- `download` (default): downloads the files.
- `test`: creates the folders and an empty file for each file found, without downloading anything.
- `search`: prints each file found and its URL, writes nothing, and returns the list from `scrape()`.

## Methods

Every setter returns the scraper, so calls can be chained.

### Locations and filters

```php
addLocation($url, $subdir = null, $types = array())
```
Adds a listing URL to scrape. `$subdir` is the folder under the destination root for its files. `$types` is a list of file extensions to keep, compared case-sensitively with the extension in the URL; leave it empty to keep every file.

```php
excludeInPath($text)      // string or array: skip URLs (files and folders) containing this text, case-sensitive
excludeInFilename($text)  // string or array: skip file names containing this text, case-sensitive
search($terms)            // string or array: keep only file names containing at least one term, case-insensitive
setRandomLimit($count)    // keep a random selection of this many files (0, the default, keeps all)
setFileNameProcessor($callable)  // function that receives each file name and returns the name to save as
```

### Where and how

```php
setDestinationRoot($path)          // default /tmp/scraper/
setMode($mode)                     // download, test or search
setMaxConcurrentDownloads($count)  // 1 to 50, default 10
setConnectionTimeout($seconds)     // minimum 5, default 30
setTransferTimeout($seconds)       // per file, minimum 10, default 300
setMaxDownloadSpeed($bytesPerSec)  // per file, 0 (the default) for no limit
setMaxRetries($count)              // default 3, 0 turns retries off
setRetryDelay($seconds)            // wait before the first retry, default 2, doubles each retry
```

### Output

```php
enableLogging($path)            // append a line per download, retry and failure to this file
setProgressCallback($callable)  // called after each file with array('current', 'total', 'percent', 'data')
getStats()                      // counts and timings, see below
scrape()                        // runs the scrape
```

`scrape()` returns the list of files found in search mode, and otherwise one result array per file with `success`, `link`, `http_code`, `curl_error`, `attempts`, `time`, `size` and `speed`. It also sets PHP's time limit to unlimited and the memory limit to 3000M.

`getStats()` returns `total`, `success`, `failed`, `skipped`, `bytes_downloaded`, `start_time`, `end_time` and `duration` (seconds since the scraper was created). Files skipped because they were already on disk before the scrape started are left out of the list altogether, so they do not appear in `total` or `skipped`.

### Example with the options

```php
$scraper = new scraper();
$scraper->setDestinationRoot('./downloads/')
    ->setMaxConcurrentDownloads(5)
    ->setMaxRetries(5)
    ->setRetryDelay(1)
    ->enableLogging('./scrape.log')
    ->setProgressCallback(function ($p) {
        echo "\r{$p['current']}/{$p['total']} ({$p['percent']}%)";
    })
    ->addLocation('http://example.com/archive/', 'archive', array('pdf'))
    ->excludeInPath('/old/')
    ->excludeInFilename('draft')
    ->search('report')
    ->scrape();

print_r($scraper->getStats());
```

## Finding listings

Search engines index many open listings. A query such as this finds them:

```
-inurl:htm -inurl:html -intitle:"ftp" intitle:"index of /" TERM EXT
```

## Tests

```
php tests/run.php
```

The tests need no network access. They start PHP's built-in web server on a free local port with `tests/router.php`, which serves a fake listing with a subdirectory, a file that returns 404 and a file that fails twice before it succeeds. They check the downloaded files and their content, that the 404 is not saved, that the failing file is retried with the right delays and then saved, that files already on disk are not requested again, and the type filter, exclusions, search mode and test mode. The run takes about 3 seconds.

## Other files

`howmanythings.php` is a separate small script that counts files by extension under two folders named in the script. It does not use the scraper.

## Licence

MIT. See `LICENSE`.
