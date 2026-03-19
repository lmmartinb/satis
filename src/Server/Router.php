<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

$configFile = getenv('SATIS_CONFIG');
if (false === $configFile || '' === $configFile) {
    http_response_code(500);
    echo 'SATIS_CONFIG environment variable is not set';
    exit(1);
}

$autoloadPaths = [
    dirname(__DIR__).'/../vendor/autoload.php',
    dirname(__DIR__).'/../../../autoload.php',
];

$loader = null;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        $loader = require $autoloadPath;
        break;
    }
}

if (null === $loader) {
    http_response_code(500);
    echo 'Autoloader not found';
    exit(1);
}

use Composer\Json\JsonFile;
use Composer\Satis\Storage\StorageConfig;
use Composer\Satis\Storage\StorageFactory;

$file = new JsonFile($configFile);
if (!$file->exists()) {
    http_response_code(500);
    echo 'Config file not found: '.$configFile;
    exit(1);
}

$config = $file->read();
$storageConfig = new StorageConfig($config['storage'] ?? []);
$outputDir = $config['output-dir'] ?? '/build/output';
$storage = StorageFactory::create($storageConfig, $outputDir);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

if ('/' === $uri) {
    $uri = '/index.html';
}

$path = ltrim($uri, '/');

try {
    if (!$storage->fileExists($path)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Not found: '.$path;

        return true;
    }

    $content = $storage->read($path);

    $mimeTypes = [
        'json' => 'application/json',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'txt' => 'text/plain',
        'xml' => 'application/xml',
    ];

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $contentType = $mimeTypes[$extension] ?? 'application/octet-stream';

    header('Content-Type: '.$contentType);
    header('Content-Length: '.strlen($content));
    header('Cache-Control: no-cache, must-revalidate');
    echo $content;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error reading file: '.$e->getMessage();
}

return true;
