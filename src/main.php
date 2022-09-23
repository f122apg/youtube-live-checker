<?php
namespace F122apg\YoutubeLiveChecker;

require_once 'vendor/autoload.php';

use F122apg\YoutubeLiveChecker\App;

App::liveCheck($argv[1]);