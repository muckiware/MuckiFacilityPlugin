<?php declare(strict_types=1);
$loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
$loader->addPsr4('MuckiFacilityPlugin\\tests\\', __DIR__);
return $loader;
