<?php

require_once __DIR__.'/vendor/autoload.php';

$application = new \Symfony\Component\Console\Application();
$application->add(new \Ctors\Cron2ICalCommand);
$application->run();

