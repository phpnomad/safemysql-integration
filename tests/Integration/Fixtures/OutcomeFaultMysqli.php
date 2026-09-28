<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_result;
use Throwable;

/**
 * Injects acknowledgement faults around the real COMMIT/ROLLBACK statements
 * the strategy issues as SQL text. Mirrors OutcomeFaultPdo's boundary model,
 * adapted because the SafeMySQL strategy never calls a driver transaction
 * method directly: START TRANSACTION/COMMIT/ROLLBACK are all plain queries.
 */
final class OutcomeFaultMysqli extends mysqli
{
    public ?string $faultAt = null;
    public bool $afterOperation = false;
    public bool $throwFault = true;
    public ?bool $rollbackThrowsAfterCommitFault = null;
    public bool $rollbackAfterOperationAfterCommitFault = false;
    public ?FaultMysqliException $faultCause = null;
    public ?FaultMysqliException $commitFaultCause = null;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public ?Throwable $coordinationFailure = null;
    public int $coordinationFaultCalls = 0;
    private bool $ownedTransaction = false;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $upper = strtoupper(ltrim($query));

        if (str_starts_with($upper, 'START TRANSACTION')) {
            $result = parent::query($query, $result_mode);
            $this->ownedTransaction = true;
            return $result;
        }

        if (str_starts_with($upper, 'COMMIT')) {
            return $this->handleCommit($query, $result_mode);
        }

        if (str_starts_with($upper, 'ROLLBACK')) {
            return $this->handleRollback($query, $result_mode);
        }

        if ($this->ownedTransaction && $this->coordinationFailure !== null) {
            $failure = $this->coordinationFailure;
            $this->coordinationFailure = null;
            $this->coordinationFaultCalls++;
            throw $failure;
        }

        return parent::query($query, $result_mode);
    }

    private function handleCommit(string $query, int $result_mode): mysqli_result|bool
    {
        $this->commitCalls++;
        if ($this->faultAt !== 'commit') {
            $result = parent::query($query, $result_mode);
            $this->ownedTransaction = false;
            return $result;
        }
        if ($this->afterOperation) {
            parent::query($query, $result_mode);
        }
        $this->ownedTransaction = false;
        try {
            return $this->failAcknowledgement('Connection acknowledgement fault', 2013, $this->throwFault);
        } finally {
            $this->commitFaultCause = $this->faultCause;
        }
    }

    private function handleRollback(string $query, int $result_mode): mysqli_result|bool
    {
        $this->rollbackCalls++;
        if ($this->faultAt === 'commit' && $this->rollbackThrowsAfterCommitFault !== null) {
            if ($this->rollbackAfterOperationAfterCommitFault) {
                parent::query($query, $result_mode);
            }
            $this->ownedTransaction = false;
            return $this->failAcknowledgement('Rollback acknowledgement fault', 2006, $this->rollbackThrowsAfterCommitFault);
        }
        if ($this->faultAt !== 'rollback') {
            $result = parent::query($query, $result_mode);
            $this->ownedTransaction = false;
            return $result;
        }
        if ($this->afterOperation) {
            parent::query($query, $result_mode);
        }
        $this->ownedTransaction = false;
        return $this->failAcknowledgement('Connection acknowledgement fault', 2013, $this->throwFault);
    }

    private function failAcknowledgement(string $message, int $code, bool $throws): bool
    {
        $exception = new FaultMysqliException($message, $code, 'HY000');
        $this->faultCause = $exception;
        if ($throws) {
            throw $exception;
        }
        return false;
    }
}
