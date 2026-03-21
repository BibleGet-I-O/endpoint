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
use Dotenv\Dotenv;

try {
    $dotenv = Dotenv::createImmutable($projectFolder, ['.env', '.env.local', '.env.development', '.env.test', '.env.staging', '.env.production'], false);

    if (Router::isLocalhost()) {
        // In development environment if no .env file is present we don't want to throw an error
        $dotenv->safeLoad();
    } else {
        // In production environment we want to throw an error if no .env file is present
        $dotenv->load();
        // In production environment these variables are required, in development they will be inferred if not set
        $dotenv->required(['API_BASE_PATH', 'APP_ENV']);
    }

    $dotenv->ifPresent(['APP_ENV'])->notEmpty()->allowedValues(['development', 'test', 'staging', 'production']);

    $router = new Router();
    $router->route();
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode([
        'type'   => 'https://datatracker.ietf.org/doc/html/rfc9110#name-500-internal-server-error',
        'title'  => 'Internal Server Error',
        'status' => 500,
        'detail' => Router::isLocalhost() ? $e->getMessage() : 'A configuration error prevented the API from starting.',
    ]);
    exit;
}
