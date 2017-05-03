<?php
/**
 * @author primipilus 03.05.2017
 */

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/TestDaemon.php';

$daemon = new \test\test\TestDaemon(['daemonize' => true, 'runtimeDir' => __DIR__]);

$daemon->start();