<?php

declare(strict_types=1);

namespace Lsr\Console\Di;

use Nette\DI\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

readonly class CommandLoader implements CommandLoaderInterface
{
    /**
     * @param Container $container
     * @param array<string, class-string<Command>> $commandMap
     */
    public function __construct(
        private array     $commandMap,
        private Container $container,
    )
    {
    }

    public function get(string $name): Command
    {
        $type = $this->commandMap[$name] ?? null;

        if ($type === null) {
            throw new CommandNotFoundException("Command {$name} not found.");
        }

        $command = $this->container->getByType($type, false);
        if ($command === null) {
            throw new CommandNotFoundException("Command {$name} not found in container.");
        }

        return $command;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->commandMap);
    }

    public function getNames(): array
    {
        return array_keys($this->commandMap);
    }
}
