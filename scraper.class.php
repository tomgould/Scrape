<?php

/**
 * @file scraper.class.php
 * Downloads the files specified from the location specified from web servers
 * with directory indexing enabled
 *
 * Use the following search string to find open servers in Google
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” TERM EXT
 * eg
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” animated gif
 * -inurl:htm -inurl:html -intitle:”ftp” intitle:”index of /” ascii txt
 *
 * If you are on Windows OS you'll need WAMP or similar to set this up else
 * just PHP. You'll also need the CURL extension enabled.
 *
 * To use see the demo.php file, just envoke an object of type scraper and
 * use the methods provided to search, test or download as required.
 */
class scraper {

  private $destinationRoot = '/tmp/scraper/';
  private $cachePath       = '/tmp/';
  private $mode            = 'download';
  private $toScrape        = array();
  private $excludePath     = array();
  private $excludeFilename = array();
  private $search          = array();

  /**
   * Sets the destination root, the location where the downloaded files will
   * be stored
   *
   * @param string $param
   */
  public function setDestinationRoot($param) {
    $this->destinationRoot = $param;
    if ($this->right($this->destinationRoot, 1) !== '/') {
      $this->destinationRoot .= '/';
    }

    return $this;
  }

  /**
   * Sets the cache path, the location for temporary files to be written
   *
   * @param string $param
   */
  public function setCachePath($param) {
    $this->cachePath = $param;
    if ($this->right($this->cachePath, 1) !== '/') {
      $this->cachePath .= '/';
    }

    return $this;
  }

  /**
   * Sets the mode the scraper works in
   * options are:
   *   test, this creates the directories and writes empty files in place of
   *         the downloads
   *   search, this searches the locations and returns any matching results
   *           without writing or downloading any files
   *   download, downloads the files and writes them to disk
   *
   * @param string $param
   */
  public function setMode($param) {
    $this->mode = $param;

    return $this;
  }

  /**
   * Adds a location to the toScrape array
   *
   * @param string $url
   *   The URL of the location to scrape
   * @param string $location
   *   A subdirectory for all items form this server to be stored
   * @param array $mimeTypes
   *   An array of file types to download if found
   *   Leave empty to grab every file regardless of type.
   */
  public function addLocation($url, $location = NULL, $mimeTypes = array()) {
    if (NULL !== $location && $this->right($location, 1) !== '/') {
      $location .= '/';
    }

    $this->toScrape[] = array(
      'scrape_url'          => $url,
      'destination_sub_dir' => $location,
      'mime_types_i_want'   => $mimeTypes,
    );

    return $this;
  }

  /**
   * Adds or merges a new value or values to the excludePath array
   *
   * @param mixed $param
   */
  public function excludeInPath($param) {
    if (is_array($param)) {
      $this->excludePath = array_merge($this->excludePath, $param);
    }
    elseif (mb_strlen($param) > 0) {
      $this->excludePath[] = $param;
    }

    return $this;
  }

  /**
   * Adds or merges a new value or values to the excludeFilename array
   *
   * @param mixed $param
   */
  public function excludeInFilename($param) {
    if (is_array($param)) {
      $this->excludeFilename = array_merge($this->excludeFilename, $param);
    }
    elseif (mb_strlen($param) > 0) {
      $this->excludeFilename[] = $param;
    }

    return $this;
  }

  /**
   * Adds search terms to be matched against file paths
   * Add as many terms as you like, this is case insensitive
   *
   * @param mixed $param
   */
  public function search($param) {
    $searchTerms  = $this->prepareSearchTerms($param);
    $this->search = array_merge($this->search, $searchTerms);

    return $this;
  }

  /**
   * Gets the destination root
   */
  public function getDestinationRoot() {
    return $this->destinationRoot;
  }

  /**
   * Gets the cache path
   */
  public function getCachePath() {
    return $this->cachePath;
  }

  /**
   * Gets the mode
   */
  public function getMode() {
    return $this->mode;
  }

  /**
   * Gets locations to scrape
   */
  public function getLocations() {
    return $this->toScrape;
  }

  /**
   * Gets the excluded paths array
   */
  public function getExcludedPaths() {
    return $this->excludePath;
  }

  /**
   * Gets the excluded filename array
   */
  public function getExcludedFilenames() {
    return $this->excludeFilename;
  }

  /**
   * Gets the search terms
   */
  public function getSearch() {
    return $this->search;
  }

  /**
   * Prepares the search term from the manual input
   *
   * @param mixed $param
   *
   * @return array
   */
  private function prepareSearchTerms($param) {
    $return = array();
    if (is_array($param)) {
      foreach ($param as $value) {
        $value                                 = strtolower($value);
        $return[$value]                        = $value;
        $return[str_replace(' ', '.', $value)] = str_replace(' ', '.', $value);
      }
    }
    elseif (mb_strlen($param) > 0) {
      $param                                 = strtolower($param);
      $return[$param]                        = $param;
      $return[str_replace(' ', '.', $param)] = str_replace(' ', '.', $param);
    }

    return $return;
  }

  /**
   * Start the scraper on the locations to scrape
   *
   * @throws Exception
   */
  public function scrape() {
    // Overrides some server varables so the script wont time out or fail
    set_time_limit(0);
    ini_set('memory_limit', '3000M');
    ini_set('open_basedir', FALSE);

    // get the links from the server
    $links = $this->getLinks($this->getLocations());

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
          $file_header       = get_headers($links[$i]['link'], 1);
        }

        // add the content length to the link array
        $file_header                 = array_change_key_case($file_header, CASE_LOWER);
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

        if ($this->getMode() === 'download') {

          // Makes sure the directory existis
          $this->destinationDirectory($links[$i]['destination']);

          echo 'Downloading: ' . str_replace($this->getDestinationRoot(), '/', $links[$i]['save_path']) . "\n";

          while ($links[$i]['file_size'] < $links[$i]['content-length']) {

            // do the curl request
            $temp = $this->curlGet(
              $links[$i]['link'], array(
              'tmp'   => $this->getCachePath() . $links[$i]['file_name'] . '.tmp',
              'range' => $links[$i]['file_size'] . '-' . $links[$i]['content-length'],
              )
            );

            if ($links[$i]['file_size'] <= 0) {
              try {
                rename($temp, $links[$i]['save_path']);
              } catch (Exception $e) {
                throw new Exception('Couldn\'t rename the file', 0, $e);
                continue;
              }
            }
            else {
              try {
                $data = file_get_contents($temp);
                $fp   = fopen($links[$i]['save_path'], "a");
                fwrite($fp, $data);
                fclose($fp);
                unlink($temp);
              } catch (Exception $e) {
                throw new Exception('Couldn\'t write the file', 0, $e);
                continue;
              }
            }

            // reset the new file size having finished the curl request
            try {
              $size                   = filesize($links[$i]['save_path']);
              $links[$i]['file_size'] = $size;
            } catch (Exception $e) {
              $links[$i]['file_size'] = 0;
              throw new Exception('Couldn\'t read file size', 0, $e);
              continue;
            }
          }
        }
        elseif ($this->getMode() === 'test') {

          // Makes sure the directory existis
          $this->destinationDirectory($links[$i]['destination']);

          echo 'Writing empty file: ' . str_replace($this->getDestinationRoot(), '/', $links[$i]['save_path']) . "\n";

          try {
            $fp = fopen($links[$i]['save_path'], "a");
            fclose($fp);
          } catch (Exception $e) {
            throw new Exception('Couldn\'t open a file', 0, $e);
            continue;
          }
        }
        elseif ($this->getMode() === 'search') {
          echo 'Found : ' . str_replace($this->getDestinationRoot(), '/', $links[$i]['save_path'])
          . "\n" . "File Location: " . $links[$i]['link'] . "\n";
        }
      }
    }
  }

  /**
   * Downloads the files
   *
   * @param array $vars
   *   a keyed array
   * @param array $log
   * @param array $links
   *
   * @return array
   */
  private function getLinks($vars, $log = array(), $links = array()) {
    foreach ($vars as $var) {
      $log[] = $var['scrape_url'];

      // get the web page in question
      $page = $this->curlGet($var['scrape_url']);

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
        if ($this->left($href, 1) == '/') {
          $href = $host . $href;
        }
        else {
          $href = str_replace($var['scrape_url'], '', $href);
          $href = $var['scrape_url'] . $href;
        }

        // process the href for ../ etc
        $parts  = parse_url($href);
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
        $mime       = end($mime_array);

        // is this a directory ??
        if ($this->right($href, 1) == '/') {
          // this is a directory, check if it should be excluded and do nothing
          // if it should
          $exclude = FALSE;
          foreach ($this->getExcludedPaths() as $path) {
            if (mb_strpos($href, $path) !== FALSE) {
              $exclude = TRUE;
              continue;
            }
          }

          // If exclude is still false then go on and add this to the list
          if ($exclude === FALSE) {
            $dir_to_scrape = array(array(
                'scrape_url'          => $href,
                'host'                => $parts['scheme'] . '://' . $parts['host'],
                'destination_sub_dir' => $var['destination_sub_dir'] . trim($this->sanitize(urldecode($pieces[count($pieces) - 2]))) . '/',
                'mime_types_i_want'   => $var['mime_types_i_want'],
            ));

            // launch the scraper
            if (!in_array($href, $log)) {
              $links = $this->getLinks($dir_to_scrape, $log, $links);
            }
          }
        }

        // if this is a file we are interested in
        if (empty($var['mime_types_i_want']) || in_array($mime, $var['mime_types_i_want'])) {

          // test if we already have the file
          $local_file_path = urldecode($this->getDestinationRoot() . $var['destination_sub_dir']);
          $file_name       = trim($this->sanitize(urldecode(end($pieces))));

          // check if it should be excluded and do nothing if it should
          $exclude = FALSE;
          foreach ($this->getExcludedPaths() as $value) {
            if (mb_strpos($href, $value) !== FALSE) {
              $exclude = TRUE;
              continue;
            }
          }

          // Check exclusion on the file name parameter
          if ($exclude === FALSE) {
            foreach ($this->getExcludedFilenames() as $value) {
              if (mb_strpos($file_name, $value) !== FALSE) {
                $exclude = TRUE;
                continue;
              }
            }
          }

          // Check inclusion based on search parameters
          if ($exclude === FALSE && count($this->getSearch() > 0)) {
            $exclude = TRUE;
            foreach ($this->getSearch() as $value) {
              if (mb_strpos(strtolower($file_name), $value) !== FALSE) {
                $exclude = FALSE;
                continue;
              }
            }
          }

          // If exclude is still false then go on and add this to the list
          if ($exclude === FALSE) {
            // if not then download it and add it to the output array
            if (!file_exists($local_file_path . $file_name)) {
              $links[] = array(
                'save_path'   => $local_file_path . $file_name,
                'link'        => $href,
                'file_name'   => $file_name,
                'destination' => $local_file_path,
              );
            }
          }
        }
      }
    }

    return $links;
  }

  /**
   * Makes a directory if required to save the files in
   *
   * @param string $param
   */
  private function destinationDirectory($destination) {
    // make sure the destination folder exists
    if ($this->getMode() != 'search') {
      if (!is_dir($destination)) {
        mkdir($destination, 0775, TRUE);
        echo 'Making: ' . $destination . "\n";
      }
    }
  }

  /**
   * Convert a string to the file/URL safe form (safe for Windows)
   *
   * @param string $string the string to clean
   *
   * @return string
   */
  private function sanitize($string = '') {
    // Replace all weird characters with spaces
    $string = preg_replace('/[^\w\-()\&\#\%\[\]\'\.]+/u', ' ', $string);

    // Only allow one space separator at a time
    return trim(preg_replace('/  +/u', ' ', $string));
  }

  /**
   * The cURL wrapper
   *
   * @param $urll
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
   *   - low_speed_limit - (optional) See description in private function
   *   - low_speed_time  - (optional) See description in private function
   *
   * @return string
   *   Either the data or the location of the temporary file that is holding the data
   */
  private function curlGet($url, $opts = array()) {

    // a new cURL instance
    $ch = curl_init();

    // set the URL to the cURL options
    curl_setopt($ch, CURLOPT_URL, $url);

    // The cookie jar
    if (!empty($opts['cookie_jar'])) {
      $cookie = tempnam($opts['cookie_jar'], "cookie");
    }
    else {
      $cookie = tempnam(sys_get_temp_dir(), "cookie");
    }
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);

    // the headers
    if (!empty($opts['headers']) && is_array($opts['headers'])) {
      $headers = $opts['headers'];
    }
    else {
      $headers   = array();
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
      $time_out = 10;
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
      $fp   = fopen($opts['tmp'], 'w');
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
  private function right($str, $count) {
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
  private function left($str, $count) {
    return mb_substr($str, 0, $count);
  }

}
