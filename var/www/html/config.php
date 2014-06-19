<?php

$config = array();

$config['database'] = array();
$config['database']['vidblit'] = array('host' => '167.88.34.62', 
                                       'user' => 'Brun0', 
                                       'pwd' => '65UB3b3$',
                                       'db' => 'vidblit');

$config['rabbit'] = array();
$config['rabbit']['connection'] = array('host' => '107.170.154.102',
                                        'port' => 5672,
                                        'user' => 'guest',
                                        'pwd' => 'guest');

$config['rabbit']['q'] = array('http_proxy' => 'http_proxy',
                               'https_proxy'=> 'https_proxy',
                               'extractor' => 'extractor',
                               'downloadtranscoder' => 'dowloadtranscoder');

$config['cloudfront']['vidblit'] = array('keypairid' => 'APKAJ4OLBHAH22L5KMLQ',
                             'keypempath' => '/pk.pem');
$config['cloudfront']['basepath'] = array('extract' => 'http://d3oqotq78cv8kq.cloudfront.net/extracts/%s');


const PLAYLIST_EXPIRY_BUFFER = 600; #seconds
?>