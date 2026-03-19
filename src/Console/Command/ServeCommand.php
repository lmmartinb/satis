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

namespace Composer\Satis\Console\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Satis\Storage\StorageConfig;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ServeCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->getName() ?? $this->setName('serve');
        $this
            ->setDescription('Starts a web server that serves packages from storage')
            ->setDefinition([
                new InputArgument('file', InputArgument::OPTIONAL, 'Json file to use', './satis.json'),
                new InputOption('host', null, InputOption::VALUE_REQUIRED, 'Address to bind to', '0.0.0.0'),
                new InputOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8080'),
            ])
            ->setHelp(
                <<<'EOT'
                The <info>serve</info> command starts a PHP built-in web server
                that reads packages directly from the configured storage backend
                (local, S3, or GCS).

                This is useful for serving a private Composer repository without
                needing to sync files to a local directory first.

                    <info>php bin/satis serve satis.json</info>
                    <info>php bin/satis serve satis.json --port=9090</info>
                    <info>php bin/satis serve satis.json --host=127.0.0.1 --port=8080</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configFile = $input->getArgument('file');
        $host = $input->getOption('host');
        $port = $input->getOption('port');

        $file = new JsonFile($configFile);
        if (!$file->exists()) {
            $output->writeln('<error>File not found: '.$configFile.'</error>');

            return 1;
        }

        $config = $file->read();
        $storageConfig = new StorageConfig($config['storage'] ?? []);
        $adapterLabel = $storageConfig->isRemote()
            ? sprintf('%s://%s/%s', $storageConfig->adapter(), $storageConfig->bucket(), $storageConfig->prefix())
            : 'local';

        $routerPath = dirname(__DIR__, 2).'/Server/Router.php';
        $listenAddress = $host.':'.$port;

        $output->writeln(sprintf('<info>Satis server listening on http://%s</info>', $listenAddress));
        $output->writeln(sprintf('<info>Storage backend: %s</info>', $adapterLabel));
        $output->writeln('<info>Press Ctrl+C to stop</info>');
        $output->writeln('');

        $process = new Process(
            [PHP_BINARY, '-S', $listenAddress, $routerPath],
            null,
            ['SATIS_CONFIG' => realpath($configFile) ?: $configFile],
            null,
            null
        );

        $process->setTty(Process::isTtySupported());

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? 0;
    }
}
