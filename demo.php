<?php

/**
 * @file
 * Demonstration of the sreaper class
 */
include 'scraper.class.inc';

$scraper = new scraper();
$scraper->addLocation(
  'http://www.candoo.com/ulsternorrie/images/animated%20gifs/animated%20letters/', 'test-1', array('gif')
);
$scraper->setCachePath('/tmp');
$scraper->setDestinationRoot('/tmp/Scraper-Demo');
$scraper->excludeInPath('_vti_cnf');
$scraper->excludeInFilename(array('WTE', 'button', 'hot', 'new', 'arrow', 'smile', 'norr'));
$scraper->scrape();
