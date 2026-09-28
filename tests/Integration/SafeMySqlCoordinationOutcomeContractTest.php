<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use Error;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OutcomeFaultMysqli;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use RuntimeException;
use Throwable;

/**
 * Real persistence with faults at the driver's commit/rollback acknowledgement.
 *
 * The PDO contract also varies each of these scenarios across
 * PDO::ATTR_ERRMODE (EXCEPTION vs SILENT), because a PDO method can be made
 * to return false instead of throwing. mysqli has no equivalent per-call
 * choice here: mysqli::errno/sqlstate are live driver properties nothing in
 * this fixture can override the way OutcomeFaultPdo overrides errorInfo(),
 * so a synthetic "returns false" acknowledgement fault cannot carry an
 * injected error code the way a thrown exception can. Every case below
 * therefore exercises the throwing path, which is what a real mysqli
 * connection in this codebase's default report mode actually does.
 */
final class SafeMySqlCoordinationOutcomeContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    /** @dataProvider commitFaults */
    public function testCommitFailureDistinguishesConfirmedRollbackFromUncertainCommit(bool $afterCommit): void
    {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'commit';
        $mysqli->afterOperation = $afterCommit;
        $this->usePrimary($mysqli);
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                return 'must not report success';
            });
            self::fail('A failed commit acknowledgement must not return success.');
        } catch (DatastoreErrorException $failure) {
            if ($afterCommit) {
                self::assertInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
            } else {
                self::assertNotInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
            }
            $cause = $failure->getPrevious();
            self::assertInstanceOf(MysqliDriverException::class, $cause);
            self::assertSame('HY000', $cause->sqlState);
            self::assertSame(2013, $cause->getCode());
            self::assertSame($mysqli->faultCause, $cause->getPrevious());
        }

        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->commitCalls);
        self::assertSame($afterCommit ? 0 : 1, $mysqli->rollbackCalls);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame($afterCommit ? [['id' => '1', 'score' => '12']] : [], $this->visibleEffects());
        $this->assertFailureLog('commit', $afterCommit ? 'unknown' : 'rolled_back', false, MysqliDriverException::class, 'HY000', 2013);
    }

    /** @dataProvider throwableRollbackFaults */
    public function testRollbackFailureNeverMasqueradesAsTheOriginalCallbackFailure(
        bool $afterRollback,
        bool $loggerFails,
        bool $operationIsError
    ): void {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'rollback';
        $mysqli->afterOperation = $afterRollback;
        $this->usePrimary($mysqli);
        $this->logger->throwOnWrite = $loggerFails;
        $original = $operationIsError ? new Error('Original callback failure') : new RuntimeException('Original callback failure');
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use (&$calls, $original): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            throw $original;
        };
        try {
            $this->coordinate($operation);
            self::fail('An unconfirmed rollback must report an unknown outcome.');
        } catch (Throwable $caught) {
            $failure = $this->operationFailure($caught, $loggerFails);
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertSame($original, $failure->getOperationFailure());
            $cause = $failure->getPrevious();
            self::assertInstanceOf(MysqliDriverException::class, $cause);
            self::assertSame('HY000', $cause->sqlState);
            self::assertSame(2013, $cause->getCode());
            self::assertSame($mysqli->faultCause, $cause->getPrevious());
        }

        self::assertSame(1, $calls);
        self::assertSame(0, $mysqli->commitCalls);
        self::assertSame(1, $mysqli->rollbackCalls);
        self::assertSame(!$afterRollback, $this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, MysqliDriverException::class, 'HY000', 2013, priorFailure: [
            'phase' => 'callback', 'causeClass' => get_class($original), 'sqlState' => null, 'driverCode' => null,
        ]);
    }

    /** @dataProvider lostOwnership */
    public function testLosingTransactionOwnershipInsideTheCallbackCannotProduceAConfirmedSuccess(string $action): void
    {
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $action): string {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                if ($action === 'rollback') {
                    $this->primary->query('ROLLBACK');
                } elseif ($action === 'implicit commit') {
                    $this->primary->query('ALTER TABLE `' . $this->effects->getName() . '` COMMENT = \'ownership proof\'');
                } else {
                    $this->primary->query('COMMIT');
                }
                return 'must not claim owned commit';
            });
            self::fail('Losing the owned transaction must not report success.');
        } catch (CoordinatedOperationOutcomeUnknownException $failure) {
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
        }

        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertFailureLog('commit', 'unknown', false, DatastoreErrorException::class);
    }

    /** @dataProvider combinedAcknowledgementFaults */
    public function testFailedCommitFollowedByUnconfirmedRollbackHasAnUnknownOutcome(
        bool $afterRollback,
        bool $loggerFails
    ): void {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'commit';
        $mysqli->rollbackThrowsAfterCommitFault = true;
        $mysqli->rollbackAfterOperationAfterCommitFault = $afterRollback;
        $this->usePrimary($mysqli);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                return 'must not report success';
            });
            self::fail('An unconfirmed cleanup rollback leaves the commit outcome unknown.');
        } catch (Throwable $caught) {
            $failure = $this->operationFailure($caught, $loggerFails);
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            $operationCause = $failure->getOperationFailure();
            self::assertInstanceOf(MysqliDriverException::class, $operationCause);
            self::assertSame('HY000', $operationCause->sqlState);
            self::assertSame(2013, $operationCause->getCode());
            self::assertSame($mysqli->commitFaultCause, $operationCause->getPrevious());
            $cause = $failure->getPrevious();
            self::assertInstanceOf(MysqliDriverException::class, $cause);
            self::assertSame('HY000', $cause->sqlState);
            self::assertSame(2006, $cause->getCode());
            self::assertSame($mysqli->faultCause, $cause->getPrevious());
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->commitCalls);
        self::assertSame(1, $mysqli->rollbackCalls);
        self::assertSame(!$afterRollback, $this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, MysqliDriverException::class, 'HY000', 2006, priorFailure: [
            'phase' => 'commit', 'causeClass' => MysqliDriverException::class, 'sqlState' => 'HY000', 'driverCode' => 2013,
        ]);
    }

    /** @dataProvider rollbackFaults */
    public function testCoordinationFailureRemainsInspectableWhenCleanupAlsoFails(bool $afterRollback, bool $loggerFails): void
    {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'rollback';
        $mysqli->afterOperation = $afterRollback;
        $this->usePrimary($mysqli);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 999], [$this->parents, $this->effects],
                function () use (&$calls): void { $calls++; }
            );
            self::fail('Missing coordination state with unconfirmed cleanup is an unknown outcome.');
        } catch (Throwable $caught) {
            $failure = $this->operationFailure($caught, $loggerFails);
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertInstanceOf(RecordNotFoundException::class, $failure->getOperationFailure());
            $cleanup = $failure->getPrevious();
            self::assertInstanceOf(MysqliDriverException::class, $cleanup);
            self::assertSame('HY000', $cleanup->sqlState);
            self::assertSame(2013, $cleanup->getCode());
            self::assertSame($mysqli->faultCause, $cleanup->getPrevious());
        }
        self::assertSame(0, $calls);
        self::assertSame(0, $mysqli->commitCalls);
        self::assertSame(1, $mysqli->rollbackCalls);
        self::assertSame(!$afterRollback, $this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, MysqliDriverException::class, 'HY000', 2013, priorFailure: [
            'phase' => 'coordination', 'causeClass' => RecordNotFoundException::class, 'sqlState' => null, 'driverCode' => null,
        ]);
    }

    /**
     * The PDO adapter's per-call queryStatement()/prepareStatement() let a
     * non-driver Throwable (an Error, or an application exception such as
     * RecordNotFoundException) bubble out of the owned resource with its
     * exact identity intact. The SafeMySQL adapter has one boundary for
     * every owned statement, nativeQuery(), and it always normalizes
     * whatever ->query() throws into a MysqliDriverException, because a
     * real mysqli connection can only ever throw driver-shaped failures
     * there. This still proves the same contract -- an owned-resource
     * failure survives an unconfirmed cleanup with both causes retained --
     * just one level down, as the wrapper's previous().
     *
     * @dataProvider throwableRollbackFaults
     */
    public function testCoordinationCleanupRetainsTheExactFailureFromTheOwnedResource(
        bool $afterRollback,
        bool $loggerFails,
        bool $operationIsError
    ): void {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $original = $operationIsError ? new Error('Coordination resource failure') : new RecordNotFoundException('Coordination resource failure');
        $mysqli->coordinationFailure = $original;
        $mysqli->faultAt = 'rollback';
        $mysqli->afterOperation = $afterRollback;
        $this->usePrimary($mysqli);
        $this->logger->throwOnWrite = $loggerFails;
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('An owned-resource failure with unconfirmed cleanup must retain both causes.');
        } catch (Throwable $caught) {
            $failure = $this->operationFailure($caught, $loggerFails);
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            $operationFailure = $failure->getOperationFailure();
            self::assertInstanceOf(MysqliDriverException::class, $operationFailure);
            self::assertSame($original, $operationFailure->getPrevious());
            $cleanup = $failure->getPrevious();
            self::assertInstanceOf(MysqliDriverException::class, $cleanup);
            self::assertSame('HY000', $cleanup->sqlState);
            self::assertSame(2013, $cleanup->getCode());
            self::assertSame($mysqli->faultCause, $cleanup->getPrevious());
        }
        self::assertSame(1, $mysqli->coordinationFaultCalls);
        self::assertNull($mysqli->coordinationFailure);
        self::assertSame(0, $calls);
        self::assertSame(0, $mysqli->commitCalls);
        self::assertSame(1, $mysqli->rollbackCalls);
        self::assertSame(!$afterRollback, $this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, MysqliDriverException::class, 'HY000', 2013, priorFailure: [
            'phase' => 'coordination', 'causeClass' => MysqliDriverException::class, 'sqlState' => null, 'driverCode' => null,
        ]);
    }

    /** @return array<string, array{bool, bool}> */
    public static function combinedAcknowledgementFaults(): array
    {
        return [
            'rollback before' => [false, false],
            'rollback before, logger failure' => [false, true],
            'rollback after' => [true, false],
            'rollback after, logger failure' => [true, true],
        ];
    }

    /** @return array<string, array{string}> */
    public static function lostOwnership(): array
    {
        return ['commit' => ['commit'], 'rollback' => ['rollback'], 'implicit commit' => ['implicit commit']];
    }

    /** @return array<string, array{bool}> */
    public static function commitFaults(): array
    {
        return ['before commit' => [false], 'after commit' => [true]];
    }

    /** @return array<string, array{bool, bool}> */
    public static function rollbackFaults(): array
    {
        return [
            'before rollback' => [false, false], 'before rollback, logger failure' => [false, true],
            'after rollback' => [true, false], 'after rollback, logger failure' => [true, true],
        ];
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function throwableRollbackFaults(): array
    {
        $cases = [];
        foreach (self::rollbackFaults() as $name => $faults) {
            $cases[$name . ', exception'] = [...$faults, false];
            $cases[$name . ', error'] = [...$faults, true];
        }
        return $cases;
    }

    private function operationFailure(Throwable $failure, bool $loggerFails): Throwable
    {
        if (!$loggerFails) {
            self::assertNotInstanceOf(CoordinatedOperationReportingFailedException::class, $failure);
            return $failure;
        }

        self::assertInstanceOf(CoordinatedOperationReportingFailedException::class, $failure);
        self::assertSame($this->logger->transportFailure, $failure->getReportingFailure());
        self::assertSame($failure->getReportingFailure(), $failure->getPrevious());
        self::assertSame(1, $this->logger->writeAttempts);

        return $failure->getOperationFailure();
    }
}
