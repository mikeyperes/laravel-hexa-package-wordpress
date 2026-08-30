<?php

$root = dirname(__DIR__, 4);
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('Tests\\', $root . '/tests');
