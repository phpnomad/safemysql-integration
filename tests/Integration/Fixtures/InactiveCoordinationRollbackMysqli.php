<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_result;
use RuntimeException;

/**
 * Injects an inactive failure at the one real driver boundary the strategy
 * uses to reach the server: the plain ->query() call. Unlike the PDO
 * adapter, the SafeMySQL strategy never prepares a statement for its own
 * coordination bookkeeping, so there is only one boundary to arm here.
 */
final class InactiveCoordinationRollbackMysqli extends mysqli
{
    public bool $armed = false;
    public string $witnessTable;
    public ?FaultMysqliException $faultCause = null;
    public int $injectedFailures = 0;
    public ?int $visibleBeforeAbort = null;
    public bool $inactiveAtFailure = false;
    public bool $requireOwnedEntry = true;

    public function arm(string $witnessTable): void
    {
        $this->armed = true;
        $this->witnessTable = $witnessTable;
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $upper = strtoupper(ltrim($query));
        if (str_starts_with($upper, 'START TRANSACTION') || str_starts_with($upper, 'COMMIT') || str_starts_with($upper, 'ROLLBACK')) {
            return parent::query($query, $result_mode);
        }
        // The strategy's own transaction-state probe (its only stand-in for
        // PDO::inTransaction(), which can never be intercepted this way)
        // must stay real, or arming this fixture would corrupt the very
        // check the strategy uses to decide whether a statement is owned.
        if (str_contains($query, 'performance_schema.events_transactions_current')) {
            return parent::query($query, $result_mode);
        }

        if ($this->armed && $this->requireOwnedEntry === $this->realTransactionActive()) {
            $this->armed = false;
            return $this->injectFailure();
        }

        return parent::query($query, $result_mode);
    }

    private function injectFailure(): bool
    {
        if ($this->requireOwnedEntry) {
            $table = '`' . str_replace('`', '``', $this->witnessTable) . '`';
            if (parent::query('INSERT INTO ' . $table . ' VALUES (1, 12)') !== true) {
                throw new RuntimeException('The fixture must create a rollback witness.');
            }
            $result = parent::query('SELECT score FROM ' . $table . ' WHERE id = 1');
            if (!$result instanceof mysqli_result) {
                throw new RuntimeException('The fixture must observe its rollback witness.');
            }
            $row = $result->fetch_row();
            $this->visibleBeforeAbort = (int) $row[0];
            if (parent::query('ROLLBACK') !== true) {
                throw new RuntimeException('The fixture must physically roll back before injecting the failure.');
            }
        }
        $this->inactiveAtFailure = !$this->realTransactionActive();
        $this->injectedFailures++;
        $message = $this->requireOwnedEntry
            ? 'Injected coordination failure after whole rollback'
            : 'Injected coordination failure without owned entry';
        $exception = new FaultMysqliException($message, 1213, '40001');
        $this->faultCause = $exception;
        throw $exception;
    }

    private function realTransactionActive(): bool
    {
        $result = parent::query(
            'SELECT STATE FROM performance_schema.events_transactions_current '
            . 'WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())'
        );
        if (!$result instanceof mysqli_result) {
            return false;
        }
        $row = $result->fetch_row();
        return ($row[0] ?? null) === 'ACTIVE';
    }
}
