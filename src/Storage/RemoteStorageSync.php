<?php

declare(strict_types=1);

namespace Composer\Satis\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Output\OutputInterface;

class RemoteStorageSync
{
    public static function syncToLocal(FilesystemOperator $storage, string $outputDir, OutputInterface $output): void
    {
        $filesToSync = ['packages.json'];

        foreach ($filesToSync as $file) {
            try {
                if (!$storage->fileExists($file)) {
                    continue;
                }

                $contents = $storage->read($file);
                self::writeLocal($outputDir . '/' . $file, $contents);
                $output->writeln(sprintf('<info>Synced %s from remote storage</info>', $file));

                $decoded = json_decode($contents, true);
                if (is_array($decoded) && isset($decoded['includes'])) {
                    foreach (array_keys($decoded['includes']) as $includePath) {
                        if ($storage->fileExists($includePath)) {
                            $includeContents = $storage->read($includePath);
                            self::writeLocal($outputDir . '/' . $includePath, $includeContents);
                            $output->writeln(sprintf('<info>Synced %s from remote storage</info>', $includePath));
                        }
                    }
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf('<warning>Could not sync %s from remote: %s</warning>', $file, $e->getMessage()));
            }
        }
    }

    private static function writeLocal(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
