<?php

declare(strict_types=1);

namespace Mgrunder\CreateCluster\Tests;

use InvalidArgumentException;
use Mgrunder\CreateCluster\ChaosCategorySelection;
use Mgrunder\CreateCluster\ChaosConfig;
use Mgrunder\CreateCluster\ChaosConfigLoader;
use Mgrunder\CreateCluster\ChaosOptions;
use Mgrunder\CreateCluster\SlotMigrationStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChaosConfigLoaderTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $writtenFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->writtenFiles = [];
    }

    public function testLoadsEveryKnobFromAMapping(): void
    {
        $config = $this->load(<<<'YAML'
        categories:
          replica-kill: 0.5
          slot-migration: 4
          primary-failover:
        interval: 12
        max-events: 200
        max-failures: 3
        abort-on-failure: true
        dry-run: true
        watch: true
        seed: 20250905
        wait-timeout: 90
        cooldown: 5
        unsafe: true
        slot-strategy: random
        slot-batch: 128
        YAML);

        self::assertInstanceOf(ChaosCategorySelection::class, $config->categories);
        self::assertSame(
            [
                ChaosOptions::CATEGORY_REPLICA_KILL => 0.5,
                ChaosOptions::CATEGORY_SLOT_MIGRATION => 4.0,
                ChaosOptions::CATEGORY_PRIMARY_FAILOVER => 1.0,
            ],
            $config->categories->weightByCategory,
        );
        self::assertSame(12, $config->intervalSeconds);
        self::assertSame(200, $config->maxEvents);
        self::assertSame(3, $config->maxFailures);
        self::assertTrue($config->abortOnFailure);
        self::assertTrue($config->dryRun);
        self::assertTrue($config->watch);
        self::assertSame(20250905, $config->seed);
        self::assertSame(90, $config->waitTimeoutSeconds);
        self::assertSame(5, $config->cooldownSeconds);
        self::assertTrue($config->unsafe);
        self::assertSame(SlotMigrationStrategy::Random, $config->slotMigrationStrategy);
        self::assertSame(128, $config->slotMigrationBatch);
    }

    public function testSettingsTheFileOmitsStayNull(): void
    {
        $config = $this->load(<<<'YAML'
        categories:
          - replica-kill
          - replica-restart
        YAML);

        self::assertInstanceOf(ChaosCategorySelection::class, $config->categories);
        self::assertSame(
            [ChaosOptions::CATEGORY_REPLICA_KILL => 1.0, ChaosOptions::CATEGORY_REPLICA_RESTART => 1.0],
            $config->categories->weightByCategory,
        );
        self::assertNull($config->intervalSeconds);
        self::assertNull($config->maxEvents);
        self::assertNull($config->maxFailures);
        self::assertNull($config->abortOnFailure);
        self::assertNull($config->dryRun);
        self::assertNull($config->watch);
        self::assertNull($config->seed);
        self::assertNull($config->waitTimeoutSeconds);
        self::assertNull($config->cooldownSeconds);
        self::assertNull($config->unsafe);
        self::assertNull($config->slotMigrationStrategy);
        self::assertNull($config->slotMigrationBatch);
    }

    public function testCategoryNamesAreNormalized(): void
    {
        $config = $this->load(<<<'YAML'
        categories:
          Slot-Migration: 2
        YAML);

        self::assertInstanceOf(ChaosCategorySelection::class, $config->categories);
        self::assertSame([ChaosOptions::CATEGORY_SLOT_MIGRATION => 2.0], $config->categories->weightByCategory);
    }

    public function testTheShippedExampleProfileLoads(): void
    {
        $config = new ChaosConfigLoader()->load(dirname(__DIR__) . '/chaos.dist.yml');

        self::assertInstanceOf(ChaosCategorySelection::class, $config->categories);
        self::assertNotSame([], $config->categories->weightByCategory);
        self::assertSame(SlotMigrationStrategy::Balanced, $config->slotMigrationStrategy);
    }

    #[DataProvider('rejectedDocuments')]
    public function testInvalidConfigurationIsRejected(string $yaml, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        $this->load($yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedDocuments(): iterable
    {
        yield 'unknown setting' => [
            "categorys:\n  - replica-kill\n",
            '/Unsupported chaos config setting "categorys"/',
        ];
        yield 'the all alias must be enumerated instead' => [
            "categories:\n  all: 2\n",
            '/must enumerate categories explicitly/',
        ];
        yield 'unknown category' => [
            "categories:\n  - replica-explode\n",
            '/Unsupported chaos category "replica-explode"/',
        ];
        yield 'empty category list' => [
            "categories: []\n",
            '/must list at least one category/',
        ];
        yield 'zero weight' => [
            "categories:\n  replica-kill: 0\n",
            '/must be a finite number greater than 0/',
        ];
        yield 'non-numeric weight' => [
            "categories:\n  replica-kill: often\n",
            "/weight for \"replica-kill\".+must be a number greater than 0/",
        ];
        yield 'non-integer interval' => [
            "interval: soon\n",
            '/"interval".+must be an integer/',
        ];
        yield 'interval below its minimum' => [
            "interval: 0\n",
            '/"interval".+must be >= 1/',
        ];
        yield 'slot-batch above the slot count' => [
            "slot-batch: 16385\n",
            '/"slot-batch".+must be <= 16384/',
        ];
        yield 'non-boolean flag' => [
            "unsafe: yes please\n",
            '/"unsafe".+must be true or false/',
        ];
        yield 'unknown slot strategy' => [
            "slot-strategy: chaotic\n",
            '/Unsupported slot migration strategy: chaotic/',
        ];
        yield 'sequence instead of a mapping' => [
            "- replica-kill\n",
            '/must contain a YAML mapping of settings/',
        ];
        yield 'empty document' => [
            "# nothing here\n",
            '/must contain a YAML mapping of settings/',
        ];
        yield 'malformed yaml' => [
            "categories:\n  - replica-kill\n \tbroken\n",
            '/is not valid YAML/',
        ];
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Chaos config file not found/');

        new ChaosConfigLoader()->load(sprintf('%s/manage-cluster-missing-%d.yml', sys_get_temp_dir(), mt_rand()));
    }

    private function load(string $yaml): ChaosConfig
    {
        $path = tempnam(sys_get_temp_dir(), 'manage-cluster-chaos-config');
        self::assertIsString($path);
        $this->writtenFiles[] = $path;
        self::assertNotFalse(file_put_contents($path, $yaml));

        return new ChaosConfigLoader()->load($path);
    }
}
