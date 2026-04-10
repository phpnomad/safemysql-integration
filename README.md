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

## Documentation

Framework docs live at [phpnomad.com](https://phpnomad.com). For the underlying library, see the [SafeMySQL repository](https://github.com/colshrapnel/safemysql) and its placeholder reference.

## License

MIT. See [LICENSE](LICENSE).
