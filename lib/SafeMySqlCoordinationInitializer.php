<?php

namespace PHPNomad\SafeMySql\Integration;

use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy as CoreCoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy as CoreQueryStrategy;
use PHPNomad\Loader\Interfaces\HasClassDefinitions;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\MySql\Integration\Interfaces\ProvidesOperationBuilders;
use PHPNomad\MySql\Integration\Services\MySqlOperationDatabaseProviderFactory;
use PHPNomad\MySql\Integration\Strategies\CoordinatedQueryStrategy;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;

/** Optional SafeMySQL coordination bindings. Load after the normal MySQL initializer. */
final class SafeMySqlCoordinationInitializer implements HasClassDefinitions
{
    public function getClassDefinitions(): array
    {
        return [
            SafeMySqlCoordinatedDatabaseStrategy::class => [DatabaseStrategy::class, CoordinatedDatabaseStrategy::class],
            CoordinatedQueryStrategy::class => [CoreQueryStrategy::class, CoreCoordinatedQueryStrategy::class, ProvidesOperationBuilders::class],
            MySqlOperationDatabaseProviderFactory::class => OperationDatabaseProviderFactory::class,
        ];
    }
}
