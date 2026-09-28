<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_result;
use RuntimeException;

/** Injects a query failure after real rollback, not a second real deadlock. */
final class InactiveQueryRollbackMysqli extends mysqli
{
    public ?string $faultQuery = null;
    public ?FaultMysqliException $faultCause = null;
    public int $injectedFailures = 0;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if ($this->faultQuery === $query) {
            $this->faultQuery = null;
            if (parent::query('ROLLBACK') !== true) {
                throw new RuntimeException('The fixture must physically roll back before injecting its query failure.');
            }
            $this->injectedFailures++;
            $exception = new FaultMysqliException('Injected query failure after whole rollback', 1213, '40001');
            $this->faultCause = $exception;
            throw $exception;
        }

        return parent::query($query, $result_mode);
    }
}
