<?php

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/TestChildDaemon.php';

$daemon = new TestChildDaemon(['daemonize' => true, 'runtimeDir' => __DIR__]);

$daemon->stop();
