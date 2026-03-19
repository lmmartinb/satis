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

class StorageConfig
{
    private string $adapter;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->adapter = (string) ($config['adapter'] ?? 'local');
        $this->raw = $config;
    }

    public function adapter(): string
    {
        return $this->adapter;
    }

    public function isRemote(): bool
    {
        return in_array($this->adapter, ['s3', 'gcs'], true);
    }

    public function bucket(): string
    {
        return (string) ($this->raw['bucket'] ?? '');
    }

    public function prefix(): string
    {
        return (string) ($this->raw['prefix'] ?? '');
    }

    public function region(): string
    {
        return (string) ($this->raw['region'] ?? 'us-east-1');
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        return (array) ($this->raw['credentials'] ?? []);
    }

    public function keyFilePath(): string
    {
        return (string) ($this->raw['keyFilePath'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
