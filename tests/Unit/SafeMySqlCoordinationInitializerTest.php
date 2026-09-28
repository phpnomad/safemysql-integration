<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Unit;

use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoreCoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\ProvidesOperationBuilders;
use PHPNomad\MySql\Integration\Services\MySqlOperationDatabaseProviderFactory;
use PHPNomad\MySql\Integration\Strategies\CoordinatedQueryStrategy;
use PHPNomad\SafeMySql\Integration\SafeMySqlCoordinationInitializer;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;
use PHPUnit\Framework\TestCase;

final class SafeMySqlCoordinationInitializerTest extends TestCase
{
    public function testItRegistersCoordinationOnlyWhenExplicitlyLoaded(): void
    {
        $definitions = (new SafeMySqlCoordinationInitializer())->getClassDefinitions();

        self::assertSame(
            [DatabaseStrategy::class, CoordinatedDatabaseStrategy::class],
            $definitions[SafeMySqlCoordinatedDatabaseStrategy::class]
        );
        self::assertSame(
            [CoreQueryStrategy::class, CoreCoordinatedQueryStrategy::class, ProvidesOperationBuilders::class],
            $definitions[CoordinatedQueryStrategy::class]
        );
        self::assertSame(OperationDatabaseProviderFactory::class, $definitions[MySqlOperationDatabaseProviderFactory::class]);
    }
}
