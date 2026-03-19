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

namespace Composer\Satis\Builder;

use Composer\Json\JsonFile;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Semver\VersionParser;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Output\OutputInterface;

class PackagesBuilder extends Builder
{
    public const MINIFY_ALGORITHM_V2 = 'composer/2.0';

    /** packages.json file name. */
    private string $filename;
    /** included json filename template */
    private string $includeFileName;
    /** @var list<mixed> */
    private array $writtenIncludeJsons = [];
    private bool $minify;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(OutputInterface $output, string $outputDir, array $config, bool $skipErrors, FilesystemOperator $storage, bool $minify = false)
    {
        parent::__construct($output, $outputDir, $config, $skipErrors, $storage);

        $this->filename = $this->outputDir . '/packages.json';
        $this->includeFileName = $config['include-filename'] ?? 'include/all$%hash%.json';
        $this->minify = $minify;
        $this->config['includes'] = $config['includes'] ?? true;
    }

    /**
     * @param PackageInterface[] $packages List of packages to dump
     */
    public function dump(array $packages): void
    {
        $packagesByName = [];
        $dumper = new ArrayDumper();
        foreach ($packages as $package) {
            $packagesByName[$package->getName()][$package->getPrettyVersion()] = $dumper->dump($package);
        }

        $repo = ['packages' => []];
        if (isset($this->config['providers']) && true === $this->config['providers']) {
            $providersUrl = 'p/%package%$%hash%.json';
            if (isset($this->config['homepage']) && is_string($this->config['homepage'])) {
                $repo['providers-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $providersUrl;
            } else {
                $repo['providers-url'] = $providersUrl;
            }
            $repo['providers'] = [];
            $i = 1;
            foreach ($packagesByName as $packageName => $versionPackages) {
                foreach ($versionPackages as $version => $versionPackage) {
                    $packagesByName[$packageName][$version]['uid'] = $i++;
                }
            }
            foreach ($packagesByName as $packageName => $versionPackages) {
                $dumpPackages = $this->findReplacements($packagesByName, $packageName);
                $dumpPackages[$packageName] = $versionPackages;
                $includes = $this->dumpPackageIncludeJson(
                    $dumpPackages,
                    str_replace('%package%', $packageName, $providersUrl),
                    'sha256'
                );
                $repo['providers'][$packageName] = current($includes);
            }
        }

        if (isset($this->config['includes']) && true === $this->config['includes']) {
            $repo['includes'] = $this->dumpPackageIncludeJson($packagesByName, $this->includeFileName);
        }

        $metadataUrl = 'p2/%package%.json';
        if (array_key_exists('homepage', $this->config) && false !== filter_var($this->config['homepage'], FILTER_VALIDATE_URL)) {
            $repo['metadata-url'] = parse_url(rtrim($this->config['homepage'], '/'), PHP_URL_PATH) . '/' . $metadataUrl;
        } else {
            $repo['metadata-url'] = $metadataUrl;
        }

        if (array_key_exists('available-package-patterns', $this->config) && count($this->config['available-package-patterns']) > 0) {
            $repo['available-package-patterns'] = $this->config['available-package-patterns'];
        } else {
            $repo['available-packages'] = array_keys($packagesByName);
        }

        $additionalMetaData = [];

        if ($this->minify) {
            $additionalMetaData['minified'] = self::MINIFY_ALGORITHM_V2;
        }

        foreach ($packagesByName as $packageName => $versionPackages) {
            $stableVersions = [];
            $devVersions = [];
            foreach ($versionPackages as $version => $versionConfig) {
                if ('dev' === VersionParser::parseStability($versionConfig['version'])) {
                    $devVersions[] = $versionConfig;
                } else {
                    $stableVersions[] = $versionConfig;
                }
            }

            $this->dumpPackageIncludeJson(
                [$packageName => $this->minify ? MetadataMinifier::minify($stableVersions) : $stableVersions],
                str_replace('%package%', $packageName, $metadataUrl),
                'sha1',
                $additionalMetaData
            );

            $this->dumpPackageIncludeJson(
                [$packageName => $this->minify ? MetadataMinifier::minify($devVersions) : $devVersions],
                str_replace('%package%', $packageName.'~dev', $metadataUrl),
                'sha1',
                $additionalMetaData
            );
        }

        $this->dumpPackagesJson($repo);

        $this->pruneIncludeDirectories();
    }

    /**
     * @param array<string, mixed> $packages
     *
     * @return array<string, mixed>
     */
    private function findReplacements(array $packages, string $replaced): array
    {
        $replacements = [];
        foreach ($packages as $packageName => $packageConfig) {
            foreach ($packageConfig as $versionConfig) {
                if (array_key_exists('replace', $versionConfig) && array_key_exists($replaced, $versionConfig['replace'])) {
                    $replacements[$packageName] = $packageConfig;
                    break;
                }
            }
        }

        return $replacements;
    }

    private function pruneIncludeDirectories(): void
    {
        $this->output->writeln('<info>Pruning include directories</info>');
        $paths = [];
        while ($this->writtenIncludeJsons) {
            list($hash, $includesUrl) = array_shift($this->writtenIncludeJsons);
            $relativePath = ltrim($includesUrl, '/');
            $dirname = dirname($relativePath);
            $basename = basename($relativePath);
            if (false !== strpos($dirname, '%hash%')) {
                throw new \RuntimeException('Refusing to prune when %hash% is in dirname');
            }
            $pattern = '#^' . str_replace('%hash%', '([0-9a-zA-Z]{' . strlen($hash) . '})', preg_quote($basename, '#')) . '$#';
            $paths[$dirname][] = [$pattern, $hash];
        }
        $pruneFiles = [];
        foreach ($paths as $dirname => $entries) {
            foreach ($this->storage->listContents($dirname, false) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $itemBasename = basename($item->path());
                foreach ($entries as $entry) {
                    list($pattern, $hash) = $entry;
                    if (1 === preg_match($pattern, $itemBasename, $matches) && $matches[1] !== $hash) {
                        $group = sprintf(
                            '%s/%s',
                            basename($dirname),
                            preg_replace('/\$.*$/', '', $itemBasename)
                        );
                        if (!array_key_exists($group, $pruneFiles)) {
                            $pruneFiles[$group] = [];
                        }
                        $pruneFiles[$group][] = [
                            'path' => $item->path(),
                            'lastModified' => $item->lastModified(),
                        ];
                    }
                }
            }
        }
        $offset = $this->config['providers-history-size'] ?? 0;
        foreach ($pruneFiles as $group => $files) {
            usort(
                $files,
                function (array $fileA, array $fileB) {
                    return $fileB['lastModified'] <=> $fileA['lastModified'];
                }
            );
            $files = array_splice($files, $offset);
            foreach ($files as $file) {
                $this->storage->delete($file['path']);
                $this->output->writeln(
                    sprintf(
                        '<comment>Deleted %s</comment>',
                        $file['path']
                    )
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $packages
     * @param array<string, string> $additionalMetaData
     *
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \Exception
     *
     * @return array<string, mixed>
     */
    private function dumpPackageIncludeJson(array $packages, string $includesUrl, string $hashAlgorithm = 'sha1', array $additionalMetaData = []): array
    {
        $filename = str_replace('%hash%', 'prep', $includesUrl);
        $relativePath = ltrim($filename, '/');

        $repoJson = new JsonFile($this->outputDir . '/' . $relativePath);
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $shouldPrettyPrint = isset($this->config['pretty-print']) ? (bool) $this->config['pretty-print'] : true;
        if ($shouldPrettyPrint) {
            $options |= JSON_PRETTY_PRINT;
        }

        $contents = $repoJson::encode(array_merge(['packages' => $packages], $additionalMetaData), $options) . "\n";
        $hash = hash($hashAlgorithm, $contents);

        if (false !== strpos($includesUrl, '%hash%')) {
            $this->writtenIncludeJsons[] = [$hash, $includesUrl];
            $filename = str_replace('%hash%', $hash, $includesUrl);
            $targetRelativePath = ltrim($filename, '/');
            if ($this->storage->fileExists($targetRelativePath)) {
                $targetRelativePath = null;
            }
        } else {
            $targetRelativePath = $relativePath;
        }

        if (is_string($targetRelativePath)) {
            $this->writeToStorage($targetRelativePath, $contents);
            $this->output->writeln("<info>Wrote packages to $targetRelativePath</info>");
        }

        return [
            $filename => [$hashAlgorithm => $hash],
        ];
    }

    /**
     * @throws \UnexpectedValueException
     * @throws \Exception
     */
    private function writeToStorage(string $relativePath, string $contents): void
    {
        if ($this->storage->fileExists($relativePath)) {
            $existingContents = $this->storage->read($relativePath);
            if (sha1($existingContents) === sha1($contents)) {
                return;
            }
        }

        $retries = 3;
        while ($retries--) {
            try {
                $this->storage->write($relativePath, $contents);
                break;
            } catch (\Exception $e) {
                if ($retries > 0) {
                    usleep(500000);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $repo Repository information
     */
    private function dumpPackagesJson(array $repo): void
    {
        if (isset($this->config['notify-batch'])) {
            $repo['notify-batch'] = $this->config['notify-batch'];
        }

        $this->output->writeln('<info>Writing packages.json</info>');
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
        $encoded = JsonFile::encode($repo, $options) . "\n";
        $this->storage->write('packages.json', $encoded);
    }
}
