<?php

declare(strict_types=1);

namespace Lsr\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'container:clean', description: 'Clear container cache', aliases: ['container:clear'])]
class ContainerCleanCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Container cache directory.', [TMP_DIR . 'di', TMP_DIR]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $directories */
        $directories = $input->getOption('directory');
        $success = true;
        foreach ($directories as $dir) {
            $dir = rtrim($dir, '/') . '/';

            if (!is_dir($dir)) {
                $output->writeln("<error>Directory {$dir} does not exist.</error>");
                $success = false;
                continue;
            }

            // Clear the directory
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
                    $output->writeln("<comment>Skipped {$file} - not a file.</comment>", OutputInterface::VERBOSITY_DEBUG);
                    continue;
                }
                if (!unlink($file)) {
                    $output->writeln("<error>Failed to delete file {$file}.</error>");
                    continue;
                }
                $output->writeln("<comment>Removed {$file}.</comment>", OutputInterface::VERBOSITY_DEBUG);
                $removed++;
            }

            $output->writeln("<comment>Removed {$removed} files from {$dir}.</comment>", OutputInterface::VERBOSITY_VERBOSE);
        }

        if (!$success) {
            $output->writeln('<error>Some errors occurred while clearing container cache. Please check the messages above.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Container cache cleared successfully.</info>');
        return Command::SUCCESS;
    }
}
