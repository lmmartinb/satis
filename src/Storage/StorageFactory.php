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

namespace Composer\Satis\Storage;

use Aws\S3\S3Client;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;
use League\Flysystem\Local\LocalFilesystemAdapter;

class StorageFactory
{
    public static function create(StorageConfig $config, string $outputDir): FilesystemOperator
    {
        return match ($config->adapter()) {
            's3' => self::createS3($config),
            'gcs' => self::createGcs($config),
            default => self::createLocal($outputDir),
        };
    }

    private static function createLocal(string $outputDir): FilesystemOperator
    {
        return new Filesystem(new LocalFilesystemAdapter($outputDir));
    }

    private static function createS3(StorageConfig $config): FilesystemOperator
    {
        $clientConfig = [
            'region' => $config->region(),
            'version' => 'latest',
        ];

        $credentials = $config->credentials();
        if (isset($credentials['key'], $credentials['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $credentials['key'],
                'secret' => $credentials['secret'],
            ];
        }

        $client = new S3Client($clientConfig);

        return new Filesystem(
            new AwsS3V3Adapter($client, $config->bucket(), $config->prefix())
        );
    }

    private static function createGcs(StorageConfig $config): FilesystemOperator
    {
        $clientConfig = [];

        $keyFilePath = $config->keyFilePath();
        if ('' !== $keyFilePath) {
            $clientConfig['keyFilePath'] = $keyFilePath;
        }

        $storageClient = new StorageClient($clientConfig);
        $bucket = $storageClient->bucket($config->bucket());

        return new Filesystem(
            new GoogleCloudStorageAdapter($bucket, $config->prefix(), new UniformBucketLevelAccessVisibility())
        );
    }
}
