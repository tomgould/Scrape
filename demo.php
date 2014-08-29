<?php

/**
 * @file
 *   Demonstration of the scraper
 *
 * To use this it is best to execute this file with PHP from the command line
 */
include 'scraper.class.php';

$scraper = new scraper();
$scraper->addLocation('http://www.candoo.com/ulsternorrie/images/animated%20gifs/animated%20letters/', 'test', array('gif'))
  ->setCachePath('/tmp')
  ->setDestinationRoot('/tmp/Scraper-Demo')
  ->excludeInPath('_vti_cnf')
  ->excludeInFilename(array('WTE', 'button', 'hot', 'new', 'arrow', 'smile', 'norr'))
  ->search('CLR')
  ->setMode('search')
  ->setFileNameProcessor('remove_date_string')
  ->scrape();

/**
 * Removes a 14 character date string appended to the beginning of file names
 * if it exists in the name
 *
 * eg a file name: "20130130121517-01 - cat.gif"
 * would become  : "01 - cat.gif"
 *
 * @param string $name
 *
 * @return string
 */
function remove_date_string($name) {
  if (is_numeric(mb_substr($name, 0, 14))) {
    return mb_substr($name, ((strlen($name) - 15) * -1));
  }
}
