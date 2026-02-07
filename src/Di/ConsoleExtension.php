<?php

declare(strict_types=1);

namespace Lsr\Console\Di;

use Lsr\Console\Commands\Cache\CacheCleanCommand;
use Lsr\Console\Commands\ContainerCleanCommand;
use Nette\DI\CompilerExtension;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\PhpGenerator\Literal;
use Nette\Schema\DynamicParameter;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * @property-read object{
 *     autowired: class-string<Application>,
 *     catchExceptions: bool,
 *     name: string|DynamicParameter|null,
 *     version: DynamicParameter|string|int|float|null,
 *   } $config
 */
class ConsoleExtension extends CompilerExtension
{
    private ServiceDefinition $commandLoader;
    private ServiceDefinition $applicationDefinition;

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'autowired' => Expect::string(Application::class),
            'catchExceptions' => Expect::bool(false),
            'name' => Expect::anyOf(
                Expect::string(),
                Expect::null(),
            )->dynamic()->default(null),
            'version' => Expect::anyOf(
                Expect::string(),
                Expect::int(),
                Expect::float(),
                Expect::null(),
            )->dynamic()->default(null),
        ]);
    }

    public function loadConfiguration(): void
    {
        parent::loadConfiguration();

        $builder = $this->getContainerBuilder();
        $config = $this->config;

        $this->commandLoader = $builder->addDefinition($this->prefix('commandLoader'))
            ->setFactory(CommandLoader::class)
            ->setType(CommandLoaderInterface::class)
            ->setAutowired(false);

        $this->applicationDefinition = $builder->addDefinition($this->prefix('application'))
            ->setFactory($config->autowired)
            ->setAutowired($config->autowired)
            ->addSetup('setAutoExit', [false])
            ->addSetup('setCatchExceptions', [$config->catchExceptions])
            ->addSetup('setCommandLoader', [$this->commandLoader]);

        if ($config->name !== null) {
            if ($config->name instanceof DynamicParameter) {
                $this->applicationDefinition->addSetup('setName', [
                    new Literal(
                        "(string) (!in_array(?, [null, ''], true) ? ? : 'UNKNOWN')",
                        [
                            $config->name,
                            new Literal('?'),
                            $config->name,
                        ],
                    ),
                ]);
            } else {
                $this->applicationDefinition->addSetup('setName', [$config->name]);
            }
        }

        if ($config->version !== null) {
            if ($config->version instanceof DynamicParameter) {
                $this->applicationDefinition->addSetup('setVersion', [
                    new Literal(
                        "(string) (!in_array(?, [null, ''], true) ? ? : 'UNKNOWN')",
                        [
                            $config->version,
                            new Literal('?'),
                            $config->version,
                        ],
                    ),
                ]);
            } else {
                $this->applicationDefinition->addSetup('setVersion', [(string)$config->version]);
            }
        }

        $this->compiler->addExportedType($config->autowired);

        $this->defineSystemCommands($builder);
    }

    private function defineSystemCommands(ContainerBuilder $builder): void
    {
        $builder->addDefinition($this->prefix('commands.container_clean'))
            ->setFactory(ContainerCleanCommand::class)
            ->setTags(['lsr', 'console', 'command']);

        // If app uses the cache package
        if (class_exists('Lsr\Caching\Cache')) {
            $builder->addDefinition($this->prefix('commands.cache_clean'))
                ->setFactory(CacheCleanCommand::class)
                ->setTags(['lsr', 'console', 'cache', 'command']);
        }
    }

    public function beforeCompile(): void
    {
        parent::beforeCompile();

        $builder = $this->getContainerBuilder();
        $config = $this->config;

        $this->addCommands($builder, $this->commandLoader, $this->applicationDefinition);
    }

    private function addCommands(
        ContainerBuilder  $builder,
        ServiceDefinition $commandLoader,
        ServiceDefinition $applicationDefinition,
    ): void
    {
        // Load all commands from DI
        $commands = $builder->findByType(Command::class);

        $commandMap = [];

        foreach ($commands as $command) {
            assert($command instanceof ServiceDefinition);

            $definition = $this->getCommandDefinition($command);

            $name = $definition['name'];
            $description = $definition['description'];

            // Hidden command starts with an empty name in the list of aliases `|name|alias|...`
            // Normal command starts with the name `name|alias|...`
            $aliases = explode('|', $name);
            $name = array_shift($aliases);
            if ($name === '') {
                $hidden = true;
                $name = array_shift($aliases);
            } else {
                $hidden = false;
            }

            $lazyCommand = null;
            if ($name !== null && $description !== null) {
                $lazyCommand = $builder->addDefinition("$this->name.lazy.{$command->getName()}")
                    ->setFactory(LazyCommand::class, [
                        $name,
                        $aliases,
                        $description,
                        $hidden,
                        new Literal('fn(): ? => $this->getService(?)', [
                            new Literal(Command::class),
                            $command->getName(),
                        ]),
                    ]);

                $commandMap[$name] = $lazyCommand->getName();
            } else {
                if ($name !== null) {
                    $command->addSetup('setName', [$name]);
                    $commandMap[$name] = $command->getName();
                } else {
                    $applicationDefinition->addSetup('add', [$command]);
                }

                if ($description !== null) {
                    $command->addSetup('setDescription', [$description]);
                }

                $command->addSetup('setHidden', [$hidden]);
                $command->addSetup('setAliases', [$aliases]);
            }
        }

        // Add command map to the command loader
        $commandLoader->setArgument('commandMap', $commandMap);
    }

    /**
     * @return array{name: string, description: string|null, help: string|null, usages: string[]}
     */
    private function getCommandDefinition(ServiceDefinition $command): array
    {
        // Get class
        $factory = $command->getFactory()->entity;
        $class = null;
        if (is_string($factory) && is_a($factory, Command::class, true)) {
            $class = $factory;
        }

        // From type - service definition has `type` set
        $type = $command->getType();
        if (is_string($type) && is_a($type, Command::class, true)) {
            $class = $type;
        }

        if ($class === null) {
            throw new \RuntimeException('Cannot determine command class for service ' . $command->getName());
        }

        $reflection = new \ReflectionClass($class);

        // Try to get the attribute
        $attributes = $reflection->getAttributes(AsCommand::class);
        foreach ($attributes as $attribute) {
            /** @var AsCommand $asCommand */
            $asCommand = $attribute->newInstance();
            return [
                'name' => $asCommand->name,
                'description' => $asCommand->description,
                'help' => $asCommand->help,
                'usages' => $asCommand->usages,
            ];
        }

        // Try to get from properties
        $name = $reflection->getStaticPropertyValue('defaultName', null);
        $description = $reflection->getStaticPropertyValue('defaultDescription', null);
        $help = $reflection->getStaticPropertyValue('defaultHelp', null);
        $usages = $reflection->getStaticPropertyValue('defaultUsages', []);

        // Try to get from static methods
        if ($name === null && $reflection->hasMethod('getDefaultName')) {
            $method = $reflection->getMethod('getDefaultName');
            if ($method->isStatic() && $method->isPublic()) {
                $name = $method->invoke(null);
            }
        }
        if ($description === null && $reflection->hasMethod('getDefaultDescription')) {
            $method = $reflection->getMethod('getDefaultDescription');
            if ($method->isStatic() && $method->isPublic()) {
                $description = $method->invoke(null);
            }
        }
        if ($help === null && $reflection->hasMethod('getDefaultHelp')) {
            $method = $reflection->getMethod('getDefaultHelp');
            if ($method->isStatic() && $method->isPublic()) {
                $help = $method->invoke(null);
            }
        }
        if (empty($usages) && $reflection->hasMethod('getDefaultUsages')) {
            $method = $reflection->getMethod('getDefaultUsages');
            if ($method->isStatic() && $method->isPublic()) {
                $usages = $method->invoke(null);
            }
        }

        if ($name === null) {
            throw new \RuntimeException('Cannot determine command class for service ' . $command->getName());
        }

        return [
            'name' => $name,
            'description' => $description,
            'help' => $help,
            'usages' => $usages,
        ];
    }
}
