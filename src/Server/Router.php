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

if ('/webhook' === $uri && 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    $rawBody = file_get_contents('php://input');
    $secret = getenv('SATIS_WEBHOOK_SECRET');

    if (false !== $secret && '' !== $secret) {
        $gitlabToken = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
        $githubSig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $giteaSig = $_SERVER['HTTP_X_GITEA_SIGNATURE'] ?? '';
        $bitbucketSig = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';

        $valid = false;
        if ('' !== $gitlabToken) {
            $valid = hash_equals($secret, $gitlabToken);
        } elseif ('' !== $githubSig) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
            $valid = hash_equals($expected, $githubSig);
        } elseif ('' !== $giteaSig) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            $valid = hash_equals($expected, $giteaSig);
        } elseif ('' !== $bitbucketSig) {
            $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
            $valid = hash_equals($expected, $bitbucketSig);
        }

        if (!$valid) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid signature']);

            return true;
        }
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid JSON payload']);

        return true;
    }

    $repoUrl = $payload['repository']['clone_url']
        ?? $payload['repository']['git_http_url']
        ?? $payload['repository']['links']['html']['href']
        ?? null;

    if (null === $repoUrl || '' === $repoUrl) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Could not extract repository URL from payload']);

        return true;
    }

    $satisBin = dirname(__DIR__, 2).'/bin/satis';
    $cmd = sprintf(
        '%s %s build %s %s --repository-url=%s > /dev/null 2>&1 &',
        PHP_BINARY,
        escapeshellarg($satisBin),
        escapeshellarg($configFile),
        escapeshellarg($outputDir),
        escapeshellarg($repoUrl)
    );
    exec($cmd);

    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'building', 'repository' => $repoUrl]);

    return true;
}

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
