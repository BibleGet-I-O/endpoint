<?php

declare(strict_types=1);

$projectFolder  = dirname(__DIR__);
$autoloaderPath = $projectFolder . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!file_exists($autoloaderPath)) {
    die('Error: Unable to locate vendor/autoload.php. Please run `composer install` in the project root.');
}

require_once $autoloaderPath;

// Load .env.test for database credentials and other test configuration
// Use createMutable so .env.test values override any pre-existing DB_* env vars
$dotenv = Dotenv\Dotenv::createMutable($projectFolder, '.env.test');
$dotenv->load();

$dbName = (string) ( $_ENV['DB_NAME'] ?? '' );
if (!str_ends_with($dbName, '_test')) {
    throw new RuntimeException('Refusing to run tests against a non-test database. DB_NAME must end with "_test".');
}
