<?php

declare(strict_types=1);

namespace Lsr\Console\Commands\Cache;

use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'orm:cache:clean', description: 'Clean ORM model cache.', aliases: ['orm:cache:clear'])]
class OrmCacheCleanCommand extends Command
{
    private const string MODEL_REPOSITORY = 'Lsr\\Orm\\ModelRepository';

    protected function configure(): void {
        $this->addOption(
            'directory',
            'd',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'ORM model cache directory.',
            $this->getDefaultDirectories(),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $this->clearRuntimeCache();

        /** @var string[] $directories */
        $directories = $input->getOption('directory');
        $success = true;
        foreach ($directories as $dir) {
            $dir = rtrim($dir, '/') . '/';

            if (!is_dir($dir)) {
                $output->writeln(
                    "<comment>Directory {$dir} does not exist.</comment>",
                    OutputInterface::VERBOSITY_VERBOSE,
                );
                continue;
            }

            $files = glob($dir . '*.php');
            if ($files === false) {
                $output->writeln("<error>Failed to read directory {$dir}.</error>");
                $success = false;
                continue;
            }

            $lockFiles = glob($dir . '*.php.lock');
            if ($lockFiles !== false) {
                $files = array_merge($files, $lockFiles);
            }
            $metaFiles = glob($dir . '*.php.meta');
            if ($metaFiles !== false) {
                $files = array_merge($files, $metaFiles);
            }

            $removed = 0;
            foreach ($files as $file) {
                if (!is_file($file)) {
                    $output->writeln(
                        "<comment>Skipped {$file} - not a file.</comment>",
                        OutputInterface::VERBOSITY_DEBUG,
                    );
                    continue;
                }
                if (!unlink($file)) {
                    $output->writeln("<error>Failed to delete file {$file}.</error>");
                    $success = false;
                    continue;
                }
                $output->writeln("<comment>Removed {$file}.</comment>", OutputInterface::VERBOSITY_DEBUG);
                $removed++;
            }

            $output->writeln(
                "<comment>Removed {$removed} files from {$dir}.</comment>",
                OutputInterface::VERBOSITY_VERBOSE,
            );
        }

        if (!$success) {
            $output->writeln(
                '<error>Some errors occurred while clearing ORM cache. Please check the messages above.</error>',
            );
            return Command::FAILURE;
        }

        $output->writeln('<info>ORM cache cleared successfully.</info>');
        return Command::SUCCESS;
    }

    private function clearRuntimeCache(): void {
        $repositoryClass = self::MODEL_REPOSITORY;
        if (!class_exists($repositoryClass)) {
            return;
        }

        /** @phpstan-ignore argument.type */
        $repository = new ReflectionClass($repositoryClass);
        $properties = [
            'cacheFileName',
            'cacheClassName',
            'modelConfig',
            'primaryKeys',
            'reflections',
            'factory',
        ];
        foreach ($properties as $property) {
            if (!$repository->hasProperty($property)) {
                continue;
            }
            $repository->getProperty($property)->setValue(null, []);
        }

        if ($repository->hasProperty('generatingConfig')) {
            $repository->getProperty('generatingConfig')->setValue(null, null);
        }

        foreach (['clearInstances', 'clearLoggers'] as $method) {
            if (!$repository->hasMethod($method)) {
                continue;
            }
            $repository->getMethod($method)->invoke(null);
        }
    }

    /**
     * @return string[]
     */
    private function getDefaultDirectories(): array {
        $tmpDir = defined('TMP_DIR') ? (string) constant('TMP_DIR') : rtrim(sys_get_temp_dir(), '/') . '/';

        return [$tmpDir . 'models'];
    }
}
