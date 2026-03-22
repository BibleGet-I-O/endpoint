<?php

declare(strict_types=1);

$projectFolder  = dirname(__DIR__);
$autoloaderPath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!file_exists($autoloaderPath)) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;
