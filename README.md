# phpnomad/safemysql-integration

[![Latest Version](https://img.shields.io/packagist/v/phpnomad/safemysql-integration.svg)](https://packagist.org/packages/phpnomad/safemysql-integration)
[![Total Downloads](https://img.shields.io/packagist/dt/phpnomad/safemysql-integration.svg)](https://packagist.org/packages/phpnomad/safemysql-integration)
[![PHP Version](https://img.shields.io/packagist/php-v/phpnomad/safemysql-integration.svg)](https://packagist.org/packages/phpnomad/safemysql-integration)
[![License](https://img.shields.io/packagist/l/phpnomad/safemysql-integration.svg)](https://packagist.org/packages/phpnomad/safemysql-integration)

Integrates the [SafeMySQL](https://github.com/colshrapnel/safemysql) library with PHPNomad's database layer. Provides concrete strategies that plug a `SafeMySQL` instance into the abstractions declared by `phpnomad/mysql-integration` and `phpnomad/db`.

## Installation

```bash
composer require phpnomad/safemysql-integration
```

## What This Provides

- `SafeMySqlDatabaseStrategy` implements `DatabaseStrategy` from `phpnomad/mysql-integration`. It uses SafeMySQL's placeholder syntax (`?s`, `?i`, `?a`, `?u`, `?n`, `?p`) for parameter substitution, with extra handling for row tuples and associative arrays so bulk inserts and updates format correctly.
- `SafeMySqlAtomicOperationStrategy` implements `AtomicOperationStrategy` from `phpnomad/db`. It wraps a callable in `START TRANSACTION` / `COMMIT` / `ROLLBACK`, rolling back and re-throwing on any `Throwable`.

## Requirements

- PHP 8.2+
- `phpnomad/mysql-integration`
- `phpnomad/db`
- `phpnomad/datastore`
- `colshrapnel/safemysql`

## Usage

Create one `SafeMySQL` instance for the application and bind both strategies to it in your container. The strategies share the same connection so transaction state carries across queries run through the database strategy.

```php
<?php

use PHPNomad\Database\Interfaces\AtomicOperationStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlAtomicOperationStrategy;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlDatabaseStrategy;
use SafeMySQL;

$db = new SafeMySQL([
    'host' => 'localhost',
    'user' => 'app',
    'pass' => 'secret',
    'db'   => 'myapp',
]);

$container->bindFactory(
    DatabaseStrategy::class,
    fn() => new SafeMySqlDatabaseStrategy($db)
);

$container->bindFactory(
    AtomicOperationStrategy::class,
    fn() => new SafeMySqlAtomicOperationStrategy($db)
);
```

From here, any PHPNomad component that depends on `DatabaseStrategy` or `AtomicOperationStrategy` resolves through the SafeMySQL-backed implementations.

## Coordinated database operations

Coordination is optional. Load `SafeMySqlCoordinationInitializer` after the normal MySQL initializer. It binds `SafeMySqlCoordinatedDatabaseStrategy` as the `DatabaseStrategy`, and the container autowires it, so bind the application's `SafeMySQL` instance and a `LoggerStrategy`. Without the `SafeMySQL` binding the container builds a new, unconfigured connection.

```php
use PHPNomad\SafeMySql\Integration\SafeMySqlCoordinationInitializer;

$container->bindFactory(SafeMySQL::class, fn () => $db);
// ...then, in the initializer list, after the normal MySQL initializer:
new SafeMySqlCoordinationInitializer(),
```

The coordinated strategy extends `SafeMySqlDatabaseStrategy`, so ordinary queries behave as before.

The capability locks the complete primary-key identity of the coordination row, checks every declared participant, and runs the callback once in one transaction on the supplied `mysqli` connection. Declared writes commit together. The callback receives a backend that detects lost ownership and refuses queries after the operation ends. Do not perform external effects in that callback.

It requires MySQL 8, autocommit enabled, `READ COMMITTED` or `REPEATABLE READ`, no ambient transaction, stable direct `TRIGGER` visibility for each participant, and InnoDB base tables without triggers. Unsupported cases fail before the callback.

mysqli cannot report whether a transaction is open, so the adapter reads MySQL's transaction instrumentation instead. Grant the application account read access to four `performance_schema` tables:

```sql
GRANT SELECT ON performance_schema.threads TO 'app'@'%';
GRANT SELECT ON performance_schema.events_transactions_current TO 'app'@'%';
GRANT SELECT ON performance_schema.setup_consumers TO 'app'@'%';
GRANT SELECT ON performance_schema.setup_instruments TO 'app'@'%';
```

MySQL 8 turns on the `transaction` instrument and the `events_transactions_current` consumer by default. Without the grant, or with either one off, coordination refuses before it opens a transaction and names what is missing.

Deadlocks and lock waits become `CoordinatedOperationConflictException` only after rollback is confirmed. A failed commit or lost ownership can produce `CoordinatedOperationOutcomeUnknownException`. An unconfirmed rollback produces `CoordinatedOperationCleanupFailedException`, and a logger transport failure produces `CoordinatedOperationReportingFailedException` while retaining the classified database failure.

## Documentation

Framework docs live at [phpnomad.com](https://phpnomad.com). For the underlying library, see the [SafeMySQL repository](https://github.com/colshrapnel/safemysql) and its placeholder reference.

## License

MIT. See [LICENSE](LICENSE).
