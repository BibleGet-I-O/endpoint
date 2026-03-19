<?php

/**
 * BibleGet I/O API - Front Controller
 *
 * All requests are routed through this single entry point.
 *
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://query.bibleget.io
 * @license Apache 2.0 License
 * @version 3.0
 */

declare(strict_types=1);

// Locate autoloader by walking up the directory tree
$projectFolder  = __DIR__;
$autoloaderPath = null;
$level          = 0;

while (true) {
    $candidatePath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($candidatePath)) {
        $autoloaderPath = $candidatePath;
        break;
    }

    if ($level > 4) {
        break;
    }

    $parentDir = dirname($projectFolder);
    if ($parentDir === $projectFolder) {
        break;
    }

    ++$level;
    $projectFolder = $parentDir;
}

if (null === $autoloaderPath) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;

use BibleGet\Api\Router;

$router = new Router();
$router->route();
