<?php

/**
 * @file
 * Demonstration of the sreaper class
 */
include 'scraper.class.inc';

$scraper = new scraper();
$scraper->addLocation(
  'http://dl.best-music.us/Foreign/Arabic/Album/', NULL, array('mp3')
);
$scraper->setCachePath('/tmp');
$scraper->setDestinationRoot('/tmp/Scraper-Demo');
$scraper->excludeInPath('_vti_cnf');
$scraper->excludeInFilename(array('WTE', 'button', 'hot', 'new', 'arrow', 'smile', 'norr'));
$scraper->search('nancy');
$scraper->setMode('search');

var_export($scraper->getSearch());

$scraper->scrape();
