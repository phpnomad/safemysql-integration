<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use Closure;
use mysqli;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\InactiveCoordinationRollbackMysqli;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\InactiveQueryRollbackMysqli;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use RuntimeException;
use SafeMySQL;
use Throwable;

/**
 * Ownership loss cannot turn already committed effects into retryable
 * failures.
 *
 * Several PDO counterparts also vary PDO::ATTR_ERRMODE (EXCEPTION vs
 * SILENT) and the exact PDO call boundary (query/prepare/execute). Neither
 * dimension has a mysqli equivalent in this codebase: every coordination
 * statement here, owned or internal, is a plain ->query() call, and a real
 * mysqli connection in this codebase's default report mode always throws.
 * Those dataProviders are collapsed accordingly; the scenario itself is
 * still exercised in full.
 */
final class SafeMySqlCoordinationOwnershipContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    public function testInactiveOwnedCoordinationStatementFailureRemainsRetryEligible(): void
    {
        $mysqli = $this->connect(InactiveCoordinationRollbackMysqli::class);
        $mysqli->arm($this->effects->getName());
        $this->usePrimary($mysqli);
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationConflictException::class, $caught);
        $cause = $caught->getPrevious();
        self::assertInstanceOf(MysqliDriverException::class, $cause);
        self::assertSame('40001', $cause->sqlState);
        self::assertSame(1213, $cause->getCode());
        self::assertSame($mysqli->faultCause, $cause->getPrevious());
        self::assertSame(12, $mysqli->visibleBeforeAbort, 'The real transaction must contain a write before the injected abort.');
        self::assertTrue($mysqli->inactiveAtFailure, 'The driver must already be inactive before operation-owner cleanup.');
        self::assertSame(1, $mysqli->injectedFailures);
        self::assertSame(0, $calls, 'Coordination failure must not invoke or replay the callback.');
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', true, MysqliDriverException::class, '40001', 1213);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    /**
     * Every internal coordination statement shares the same ownership gate
     * as the callback-facing query() (see ownedInternalQuery() on the
     * strategy), so this always takes the "stricter adapter refuses before
     * issuing the unowned statement" branch the PDO counterpart only
     * sometimes takes: the fixture's injected failure never even gets a
     * chance to fire, because the gate's own pre-check already observes
     * the committed, ownerless transaction and refuses first.
     */
    public function testInternalFailureEnteredWithoutOwnershipCannotProveRollback(): void
    {
        $mysqli = $this->connect(InactiveCoordinationRollbackMysqli::class);
        $mysqli->requireOwnedEntry = false;
        $hookCalls = 0;
        $this->useParticipantValidationHook($mysqli, function () use (&$hookCalls, $mysqli): void {
            $hookCalls++;
            $this->primary->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 99)');
            $this->primary->query('COMMIT');
            $mysqli->arm($this->effects->getName());
        });
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        $original = $caught->getOperationFailure();
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls, 'Participant validation must create the committed ownership-loss hazard.');
        self::assertSame(0, $calls);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        self::assertSame(0, $mysqli->injectedFailures, 'The gate must refuse before the fixture even gets to fire.');
        self::assertNull($mysqli->faultCause);
        self::assertInstanceOf(DatastoreErrorException::class, $original);
        self::assertSame(
            'The coordinated operation lost transaction ownership before a database statement.',
            $original->getMessage()
        );
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => DatastoreErrorException::class,
            'sqlState' => null, 'driverCode' => null,
        ]);
    }

    public function testPriorCoordinationEvidenceCannotAuthorizeTheSameFailureAfterALaterCommit(): void
    {
        $mysqli = $this->connect(InactiveCoordinationRollbackMysqli::class);
        $mysqli->arm($this->effects->getName());
        $this->usePrimary($mysqli);
        $firstCalls = 0;
        $original = null;
        try {
            $this->coordinate(static function () use (&$firstCalls): void { $firstCalls++; });
        } catch (CoordinatedOperationConflictException $failure) {
            $original = $failure->getPrevious();
        }
        self::assertInstanceOf(MysqliDriverException::class, $original);
        self::assertSame('40001', $original->sqlState);
        self::assertSame(1213, $original->getCode());
        self::assertSame($mysqli->faultCause, $original->getPrevious());
        self::assertSame(12, $mysqli->visibleBeforeAbort);
        self::assertTrue($mysqli->inactiveAtFailure);
        self::assertSame(0, $firstCalls);
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', true, MysqliDriverException::class, '40001', 1213);
        $this->logger->entries = [];
        $hookCalls = 0;
        $this->useParticipantValidationHook($mysqli, function () use ($original, &$hookCalls): void {
            $hookCalls++;
            $this->primary->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 99)');
            $this->primary->query('COMMIT');
            throw $original;
        });
        $secondCalls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$secondCalls): void { $secondCalls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($original, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls);
        self::assertSame(0, $secondCalls);
        self::assertSame(1, $mysqli->injectedFailures);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => MysqliDriverException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider allOwnershipLoss */
    public function testCoordinationPhaseAloneCannotAuthorizeADeadlockShapedValidationFailure(string $action): void
    {
        $original = $this->deadlockShapedFailure();
        $hookCalls = 0;
        $this->useParticipantValidationHook($this->primary, function () use ($action, $original, &$hookCalls): void {
            $hookCalls++;
            $this->primary->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 12)');
            $this->endOwnership($action);
            throw $original;
        });
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(static function () use (&$calls): void { $calls++; });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($original, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $hookCalls);
        self::assertSame(0, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'coordination', 'causeClass' => MysqliDriverException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider inactiveQueryReplacements */
    public function testInactiveRollbackEvidenceMustComeFromTheExactSuppliedQueryFailure(bool $replace): void
    {
        $mysqli = $this->connect(InactiveQueryRollbackMysqli::class);
        $this->usePrimary($mysqli);
        $replacement = $this->deadlockShapedFailure();
        $observed = null;
        $inactiveAtFailure = false;
        $calls = 0;
        $caught = null;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($mysqli, $replace, $replacement, &$observed, &$calls, &$inactiveAtFailure): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $mysqli->faultQuery = 'SELECT 123 AS injected_rollback';
            try {
                $backend->query($mysqli->faultQuery);
            } catch (DatastoreErrorException $failure) {
                $observed = $failure;
                $inactiveAtFailure = !$this->inTransaction($mysqli);
                throw $replace ? $replacement : $failure;
            }
        };
        try {
            $this->coordinate($operation);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(DatastoreErrorException::class, $observed);
        self::assertTrue($inactiveAtFailure, 'The driver must already be inactive before operation-owner cleanup.');
        self::assertInstanceOf(MysqliDriverException::class, $observed->getPrevious());
        self::assertSame('40001', $observed->getPrevious()->sqlState);
        self::assertSame(1213, $observed->getPrevious()->getCode());
        self::assertSame($mysqli->faultCause, $observed->getPrevious()->getPrevious());
        if ($replace) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
            self::assertSame($replacement, $caught->getOperationFailure());
            self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
            $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
                'phase' => 'callback', 'causeClass' => MysqliDriverException::class, 'sqlState' => '40001', 'driverCode' => 1213,
            ]);
        } else {
            self::assertInstanceOf(CoordinatedOperationConflictException::class, $caught);
            self::assertSame($observed, $caught->getPrevious());
            $this->assertFailureLog('callback', 'rolled_back', true, DatastoreErrorException::class, '40001', 1213);
        }
        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->injectedFailures);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    public function testPriorAttemptEvidenceCannotAuthorizeTheSameFailureAfterALaterCommit(): void
    {
        $mysqli = $this->connect(InactiveQueryRollbackMysqli::class);
        $this->usePrimary($mysqli);
        $observed = null;
        $inactiveAtFailure = false;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use ($mysqli, &$inactiveAtFailure): void {
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                $mysqli->faultQuery = 'SELECT 123 AS injected_rollback';
                try {
                    $backend->query($mysqli->faultQuery);
                } catch (DatastoreErrorException $failure) {
                    $inactiveAtFailure = !$this->inTransaction($mysqli);
                    throw $failure;
                }
            });
        } catch (CoordinatedOperationConflictException $failure) {
            $observed = $failure->getPrevious();
        }
        self::assertInstanceOf(DatastoreErrorException::class, $observed);
        self::assertTrue($inactiveAtFailure, 'The first attempt must report its query failure after actual rollback.');
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('callback', 'rolled_back', true, DatastoreErrorException::class, '40001', 1213);
        $this->logger->entries = [];
        $calls = 0;
        $caught = null;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($observed, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 99)', $this->effects->getName()));
            $this->primary->query('COMMIT');
            throw $observed;
        };
        try {
            $this->coordinate($operation);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $caught);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $caught);
        self::assertSame($observed, $caught->getOperationFailure());
        self::assertInstanceOf(DatastoreErrorException::class, $caught->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->injectedFailures);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([['id' => '1', 'score' => '99']], $this->visibleEffects());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'callback', 'causeClass' => DatastoreErrorException::class, 'sqlState' => '40001', 'driverCode' => 1213,
        ]);
    }

    /** @dataProvider ordinaryQueryOutcomes */
    public function testOrdinaryQueriesRemainAvailableBeforeAndAfterTheOwnedAttempt(bool $callbackFails): void
    {
        self::assertSame([['id' => '41']], $this->strategy->query('SELECT 41 AS id'));
        $original = new RuntimeException('Callback failed');
        $caught = null;
        $result = null;
        try {
            $result = $this->coordinate(function (DatabaseStrategy $backend) use ($callbackFails, $original): string {
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                if ($callbackFails) {
                    throw $original;
                }
                return 'committed';
            });
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertSame($callbackFails ? $original : null, $caught);
        self::assertSame($callbackFails ? null : 'committed', $result);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($callbackFails ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        if ($callbackFails) {
            $this->assertFailureLog('callback', 'rolled_back', false, RuntimeException::class);
        } else {
            self::assertSame([], $this->logger->entries);
        }
    }

    /** @dataProvider allOwnershipLoss */
    public function testASuppliedBackendStatementThatEndsOwnershipCannotReturnToTheCallback(string $action): void
    {
        $continued = false;
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, &$continued, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $backend->query(match ($action) {
                'commit' => 'COMMIT',
                'rollback' => 'ROLLBACK',
                default => $backend->parse('ALTER TABLE ?n COMMENT = ?s', $this->effects->getName(), 'ownership boundary'),
            });
            $continued = true;
            throw $this->deadlockShapedFailure();
        };
        try {
            $this->coordinate($operation);
            self::fail('A query that ends the owned transaction must report an uncertain operation.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getOperationFailure());
            $this->assertUnknownCleanupLog($failure);
        }
        self::assertFalse($continued, 'The ownership-ending statement must not return normally.');
        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    /** @dataProvider allOwnershipLoss */
    public function testALaterBackendQueryCannotWriteAfterOwnershipWasLost(string $action): void
    {
        $continued = false;
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, &$continued, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $this->endOwnership($action);
            $backend->query($backend->parse('INSERT INTO ?n VALUES (2, 99)', $this->effects->getName()));
            $continued = true;
            throw $this->deadlockShapedFailure();
        };
        try {
            $this->coordinate($operation);
            self::fail('The supplied backend must refuse a query outside its owned transaction.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getOperationFailure());
            $this->assertUnknownCleanupLog($failure);
        }
        self::assertFalse($continued);
        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame(
            $action === 'rollback' ? [] : [['id' => '1', 'score' => '12']],
            $this->visibleEffects(),
            'The second write must never reach a replacement autocommit operation.'
        );
    }

    /** @dataProvider allOwnershipLoss */
    public function testADeadlockShapedCallbackFailureDoesNotProveAnInactiveAttemptRolledBack(string $action): void
    {
        $original = $this->deadlockShapedFailure();
        $calls = 0;
        /** @var callable(DatabaseStrategy): void $operation */
        $operation = function (DatabaseStrategy $backend) use ($action, $original, &$calls): void {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $this->endOwnership($action);
            throw $original;
        };
        try {
            $this->coordinate($operation);
            self::fail('Error numbers alone cannot establish whole-attempt rollback.');
        } catch (DatastoreErrorException $failure) {
            self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
            self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
            self::assertSame($original, $failure->getOperationFailure());
            self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
            $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
                'phase' => 'callback', 'causeClass' => MysqliDriverException::class, 'sqlState' => '40001', 'driverCode' => 1213,
            ]);
        }
        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($action === 'rollback' ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    private function useParticipantValidationHook(mysqli $mysqli, Closure $hook): void
    {
        $this->primary = $mysqli;
        $this->strategy = new ParticipantValidationHookStrategy(
            new SafeMySQL(['mysqli' => $mysqli]),
            $this->logger,
            $hook
        );
    }

    private function endOwnership(string $action): void
    {
        if ($action === 'rollback') {
            $this->primary->query('ROLLBACK');
        } elseif ($action === 'implicit commit') {
            $this->primary->query('ALTER TABLE `' . $this->effects->getName() . '` COMMENT = \'ownership boundary\'');
        } else {
            $this->primary->query('COMMIT');
        }
    }

    private function deadlockShapedFailure(): MysqliDriverException
    {
        return new MysqliDriverException('Deadlock-shaped callback failure', 1213, '40001');
    }

    private function assertUnknownCleanupLog(CoordinatedOperationCleanupFailedException $failure): void
    {
        self::assertInstanceOf(DatastoreErrorException::class, $failure->getPrevious());
        $this->assertFailureLog('rollback', 'unknown', false, DatastoreErrorException::class, priorFailure: [
            'phase' => 'callback', 'causeClass' => get_class($failure->getOperationFailure()),
            'sqlState' => null, 'driverCode' => null,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function allOwnershipLoss(): array
    {
        return ['commit' => ['commit'], 'implicit commit' => ['implicit commit'], 'rollback' => ['rollback']];
    }

    /** @return array<string, array{bool}> */
    public static function ordinaryQueryOutcomes(): array
    {
        return ['commit' => [false], 'callback failure' => [true]];
    }

    /** @return array<string, array{bool}> */
    public static function inactiveQueryReplacements(): array
    {
        return ['exact failure propagates' => [false], 'failure is replaced' => [true]];
    }
}

final class ParticipantValidationHookStrategy extends SafeMySqlCoordinatedDatabaseStrategy
{
    public function __construct(
        SafeMySQL $db,
        LoggerStrategy $logger,
        private Closure $beforeValidation
    ) {
        parent::__construct($db, $logger);
    }

    /**
     * @param non-empty-list<array{table: \PHPNomad\Database\Interfaces\Table, name: string}> $definitions
     * @param non-empty-list<string> $coordinationIdentity
     */
    protected function validateParticipants(
        mysqli $mysqli,
        string $schema,
        array $definitions,
        array $coordinationIdentity
    ): void {
        ($this->beforeValidation)();
        parent::validateParticipants($mysqli, $schema, $definitions, $coordinationIdentity);
    }
}
