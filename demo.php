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
  ->scrape();
