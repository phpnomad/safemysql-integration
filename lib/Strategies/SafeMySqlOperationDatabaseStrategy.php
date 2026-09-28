<?php

namespace PHPNomad\SafeMySql\Integration\Strategies;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;

/** The callback-only view of a coordinated connection. */
final class SafeMySqlOperationDatabaseStrategy implements DatabaseStrategy
{
    private bool $active = true;

    public function __construct(private SafeMySqlCoordinatedDatabaseStrategy $owner)
    {
    }

    public function close(): void
    {
        $this->active = false;
    }

    public function parse(string $query, ...$args): string
    {
        return $this->owner->parse($query, ...$args);
    }

    public function query(string $query)
    {
        if (!$this->active) {
            throw new DatastoreErrorException('The coordinated operation backend is no longer active.');
        }

        return $this->owner->query($query);
    }
}
