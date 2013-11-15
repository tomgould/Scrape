<?php

/**
 * @file scrape.php
 * Downloads the files specified from the server specified in the
 * $vars array
 *
 * Use the following search string to find open servers
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” TERM EXT
 * eg
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” animated gif
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” ascii txt
 *
 * If you are on Windows OS you'll need WAMP or similar to set this up else
 * just LAMP or equivelant. You'll also need the CURL extension enabled.
 *
 * To use just update the constants to match your config anf create the entries
 * you want to scrape in the $vars array and execute the script on the command line.
 */

set_time_limit(0);
ini_set('memory_limit', '3000M');
ini_set('open_basedir', FALSE);

// Must be writable by webserver, root folder to save to
define('DESTINATION_ROOT', '/path/to/destination/');

// Must be writable by webserver, location where temporary files will be created
define('CACHE_PATH',        '/path/to/cache-directory/');

$test_mode = FALSE;

$vars = array();
$vars[] = array(
    'scrape_url'  => 'http://www.candoo.com/ulsternorrie/images/animated%20gifs/animated%20letters/',
    'destination_sub_dir' => 'Images/',
    'mime_types_i_want' => array('gif', 'jpg', 'jpeg'),
);
$vars[] = array(
    'scrape_url'  => 'http://candybox2.net/ascii/eqItems/',
    'destination_sub_dir' => 'Text files/',
    'mime_types_i_want' => array('txt'),
);


//
// get the links from the server
$links = scrape($vars);

if (!empty($links)) {
  for ($i = 0; $i < count($links); $i++) {

    // skip files that have crazy long names
    if (mb_strlen($links[$i]['save_path']) > 255) {
      continue;
    }

    // get the headers of this file
    $file_header = get_headers($links[$i]['link'], 1);

    // if this is a redirect them reset the headers and link location
    while (!empty($file_header['Location']) && $file_header['Location'] != $links[$i]['link']) {
      $links[$i]['link'] = $file_header['Location'];
      $file_header = get_headers($links[$i]['link'], 1);
    }

    // add the content length to the link array
    $file_header = array_change_key_case($file_header, CASE_LOWER);
    $links[$i]['content-length'] = $file_header['content-length'];

    // does the file already exist?
    // if it does then get it's length
    // or set length to 0
    if (file_exists($links[$i]['save_path'])) {
      $links[$i]['file_size'] = filesize($links[$i]['save_path']);
    }
    else {
      $links[$i]['file_size'] = 0;
    }

    if ($test_mode == FALSE) {
      echo 'Downloading: ' . str_replace(DESTINATION_ROOT, '/', $links[$i]['save_path']) . "\n";
      while ($links[$i]['file_size'] < $links[$i]['content-length']) {

        // do the curl request
        $temp = curl_get(
          $links[$i]['link'],
          array(
            'tmp'   => CACHE_PATH . $links[$i]['file_name'] . '.tmp',
            'range' => $links[$i]['file_size'] . '-' . $links[$i]['content-length'],
          )
        );

        if ($links[$i]['file_size'] <= 0) {
          try {
            rename($temp, $links[$i]['save_path']);
          }
          catch (Exception $e) {
           throw new Exception( 'Couldn\'t rename the file', 0, $e);
           continue;
          }
        }
        else {
          try {
            $data = file_get_contents($temp);
            $fp = fopen($links[$i]['save_path'], "a");
            fwrite($fp, $data);
            fclose($fp);
            unlink($temp);
          }
          catch (Exception $e) {
           throw new Exception( 'Couldn\'t write the file', 0, $e);
           continue;
          }
        }

        // reset the new file size having finished the curl request
        try {
          $size = filesize($links[$i]['save_path']);
          $links[$i]['file_size'] = $size;
        }
        catch (Exception $e) {
          $links[$i]['file_size'] = 0;
          throw new Exception( 'Couldn\'t read file size', 0, $e);
          continue;
        }
      }
    }
    else {
      echo 'Writing : ' . str_replace(DESTINATION_ROOT, '/', $links[$i]['save_path']) . "\n";
      try {
        $fp = fopen($links[$i]['save_path'] . '.txt', "a");
        fclose($fp);
      }
      catch (Exception $e) {
        throw new Exception( 'Couldn\'t open a file', 0, $e);
        continue;
      }
    }
  }
}

/**
 * Downloads files
 *
 * @param array $vars
 *   a keyed array
 * @param array $log
 * @param array $links
 *
 * @return array
 */
function scrape($vars, $log = array(), $links = array()) {
  foreach ($vars as $var) {
    $log[] = $var['scrape_url'];

    // make sure the destination folder exists
    if (!is_dir(urldecode(DESTINATION_ROOT . $var['destination_sub_dir']))) {
      mkdir(urldecode(DESTINATION_ROOT . $var['destination_sub_dir']), 0775, TRUE);
      echo 'Making: ' . $var['destination_sub_dir'] . "\n";
    }

    // get the web page in question
    $page = curl_get($var['scrape_url']);

    // create a dom object
    $doc = new DOMDocument();

    // make an array of the links
    @$doc->loadHTML($page);
    $page_links = $doc->getElementsByTagName('a');
    foreach ($page_links as $link) {

      // make a fully qualified link to the target
      $href = $link->getAttribute('href');

      // get the host
      $host = parse_url($var['scrape_url']);
      $host = $host['scheme'] . '://' . $host['host'];

      // if this is a link from the root
      if (left($href, 1) == '/') {
        $href = left($host, (strlen($host) - 1)) . $href;
      }
      else {
        $href = str_replace($var['scrape_url'], '', $href);
        $href = $var['scrape_url'] . $href;
      }

      // process the href for ../ etc
      $parts = parse_url($href);
      $pieces = explode('/', $parts['path']);
      for ($i = 0; $i < count($pieces); $i++) {
        if ($pieces[$i] == '..') {
          unset($pieces[$i - 1]);
          unset($pieces[$i]);
        }
      }
      $href = $parts['scheme'] . '://' . $parts['host'] . implode('/', $pieces);

      // skip if parent or higher
      if (strlen($href) < strlen($var['scrape_url'])) {
        continue;
      }

      // skip for sort links
      if (strpos($href, '?') !== FALSE) {
        continue;
      }

      // potential mime type
      $mime_array = explode('.', $href);
      $mime = end($mime_array);

      // is this a directory ??
      if (right($href, 1) == '/') {
        // this is a directory
        $dir_to_scrape = array(array(
          'scrape_url'  => $href,
          'host'       => $parts['scheme'] . '://' . $parts['host'],
          'destination_sub_dir' => $var['destination_sub_dir'] . trim(sanitize(urldecode($pieces[count($pieces) - 2]))) . '/',
          'mime_types_i_want' => $var['mime_types_i_want'],
        ));

        // launch the scraper
        if (!in_array($href, $log)) {
          $links = scrape($dir_to_scrape, $log, $links);
        }
      }

      // if this is a file we are interested in
      if (in_array($mime, $var['mime_types_i_want'])) {

        // test if we already have the file
        $local_file_path = urldecode(DESTINATION_ROOT . $var['destination_sub_dir']);
        $file_name = trim(sanitize(urldecode(end($pieces))));

        // if not then download it and add it to the output array
        if (!file_exists($local_file_path . $file_name)) {
          $links[] = array(
            'save_path' => $local_file_path . $file_name,
            'link'  => $href,
            'file_name' => $file_name,
          );
        }
      }
    }
  }

  return $links;
}

/**
 * Convert a string to the file/URL safe form (safe for Windows)
 *
 * @param string $string the string to clean
 *
 * @return string
 */
function sanitize($string = '') {
 // Replace all weird characters with spaces
 $string = preg_replace('/[^\w\-()\&\#\%\[\]\'\.]+/u', ' ', $string);

 // Only allow one space separator at a time
 return trim(preg_replace('/  +/u', ' ', $string));
}

/**
 * The cURL wrapper
 *
 * @param $url
 *   The URL to get
 *
 * @param array $opts
 *   A keyed array containing any parameters that I may need
 *   - tmp             - (optional) The location of the temporary file to create to hold the response
 *   - cookie_jar      - (optional) The location of the cookies folder
 *   - headers         - (optional) The headers to send for the request
 *   - user_agent      - (optional) The user agent used for the request
 *   - encoding        - (optional) The encoding
 *   - time_out        - (optional) The connection time out limit in seconds
 *   - range           - (optional) The Byte range to get
 *   - low_speed_limit - (optional) See description in function
 *   - low_speed_time  - (optional) See description in function
 *
 * @return string
 *   Either the data or the location of the temporary file that is holding the data
 */
function curl_get($url, $opts = array()) {

  // a new cURL instance
  $ch = curl_init();

  // set the URL to the cURL options
  curl_setopt($ch, CURLOPT_URL, $url);

  // The cookie jar
  if (!empty($opts['cookie_jar'])) {
    $cookie = tempnam($opts['cookie_jar'], "cookie");
  }
  else {
    $cookie = tempnam(sys_get_temp_dir(),  "cookie");
  }
  curl_setopt($ch, CURLOPT_COOKIEFILE,  $cookie);
  curl_setopt($ch, CURLOPT_COOKIEJAR,  $cookie);

  // the headers
  if (!empty($opts['headers']) && is_array($opts['headers'])) {
    $headers = $opts['headers'];
  }
  else {
    $headers = array();
    $headers[] = "Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
    $headers[] = "Connection: keep-alive";
    $headers[] = "Keep-Alive: 115";
    $headers[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $headers[] = "Accept-Language: en-us,en;q=0.5";
    $headers[] = "Pragma: ";
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  // The user agent
  if (!empty($opts['user_agent'])) {
    $user_agent = $opts['user_agent'];
  }
  else {
    $user_agent = 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/535.19 (KHTML, like Gecko) Chrome/18.0.1025.162 Safari/535.19';
  }
  curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

  // The encoding
  if (!empty($opts['encoding'])) {
    $encoding = $opts['encoding'];
  }
  else {
    $encoding = 'gzip,deflate';
  }
  curl_setopt($ch, CURLOPT_ENCODING, $encoding);

  // The Connection time out in seconds
  if (!empty($opts['time_out'])) {
    $time_out = $opts['time_out'];
  }
  else {
    $time_out= 10;
  }
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $time_out);

  // Used with CURLOPT_LOW_SPEED_TIME. If the transfer speed falls below
  // this value in bytes per second for longer than CURLOPT_LOW_SPEED_TIME,
  // the transfer is aborted.
  if (!empty($opts['low_speed_limit'])) {
    $low_speed_limit = $opts['low_speed_limit'];
  }
  else {
    $low_speed_limit = 10240;
  }
  curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, $low_speed_limit);

  // Used with CURLOPT_LOW_SPEED_LIMIT. If the transfer speed falls below the
  // value given with the CURLOPT_LOW_SPEED_LIMIT option for longer than the
  // number of seconds given with CURLOPT_LOW_SPEED_TIME, the transfer is aborted.
  if (!empty($opts['low_speed_time'])) {
    $low_speed_time = $opts['low_speed_time'];
  }
  else {
    $low_speed_time = 10;
  }
  curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, $low_speed_time);

  // The range of bytes to get from the target
  if (!empty($opts['range'])) {
    curl_setopt($ch, CURLOPT_RANGE, $opts['range']);
  }

  // these are always the same for my purpose
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

  // if we are writing this to a temporary file then return the file location
  if (!empty($opts['tmp'])) {
    // open the temp file if necessary
    $fp = fopen($opts['tmp'], 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    $data = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $opts['tmp'];
  }
  else {
    // or just return the data
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
  }
}

/**
 * Returns right n chars from input
 *
 * @param string $str
 *   the string to cut
 * @param int $count
 *   the length to cut
 *
 * @return string
 */
function right($str, $count) {
  return mb_substr($str, ($count * -1));
}


/**
 * Returns left n chars from input
 *
 * @param string $str
 *   the string to cut
 * @param int $count
 *   the length to cut
 *
 * @return string
 */
function left($str, $count) {
  return mb_substr($str, 0, $count);
}

