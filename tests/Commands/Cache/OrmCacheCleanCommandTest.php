<?php

declare(strict_types=1);

namespace Tests\Commands\Cache;

use Lsr\Console\Commands\Cache\OrmCacheCleanCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OrmCacheCleanCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = rtrim(sys_get_temp_dir(), '/') . '/lsr-console-tests/orm-cache-command/';
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    protected function tearDown(): void {
        $files = glob($this->directory . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testClearsOrmCacheFiles(): void {
        file_put_contents($this->directory . 'model-config.php', '<?php return [];');
        file_put_contents($this->directory . 'model-config.php.lock', '');
        file_put_contents($this->directory . 'model-config.php.meta', '');
        file_put_contents($this->directory . 'keep.txt', '');

        $tester = new CommandTester(new OrmCacheCleanCommand());
        $code = $tester->execute([
            '--directory' => [$this->directory],
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertFileDoesNotExist($this->directory . 'model-config.php');
        self::assertFileDoesNotExist($this->directory . 'model-config.php.lock');
        self::assertFileDoesNotExist($this->directory . 'model-config.php.meta');
        self::assertFileExists($this->directory . 'keep.txt');
        self::assertStringContainsString('ORM cache cleared successfully.', $tester->getDisplay());
    }
}
