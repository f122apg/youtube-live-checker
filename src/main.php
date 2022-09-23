<?php
namespace F122apg\YoutubeLiveChecker;

require 'vendor/autoload.php';

use F122apg\YoutubeLiveChecker\App;

App::liveCheck($argv[1]);