<?php
/**
 * How many things do I have?
 */

// The locations of the music 
$home = parse_dir('/home/tgould/www');
$var  = parse_dir('/var/www');

// Shows the results
$list   = array_merge($home, $var);
$result = process_list($list);
var_export($result);

function parse_dir($dir, &$results = array()) {
  $files = scandir($dir);
  foreach ($files as $key => $value) {
    $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
    if (!is_dir($path)) {
      $results[] = $path;
    } else if (is_dir($path) && $value != "." && $value != "..") {
      parse_dir($path, $results);
      $results[] = $path;
    }
  }
  
  return $results;
}

function process_list($list) {
  $storage = array();
  $total   = 0;
  $types   = get_types();
  foreach ($list as $item) {
    $parts = explode('.', $item);
    $mime  = end($parts);    
    if (array_search($mime, $types)) {
      if (empty($storage[$mime])) {
        $storage[$mime] = 0;
      }
      $storage[$mime]++;
      $total++;
    }
  }
  
  return array(
    'Total Files' => $total,
    'File Info' => $storage
  );
}

function get_types() {
  return array(
    'php',
    'inc',
    'module',
    'js',
    'jpg',
    'png',
    'gif',
    'sql',
  );
}
