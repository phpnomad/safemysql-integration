<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use Error;
use InvalidArgumentException;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\InactiveQueryRollbackMysqli;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OutcomeFaultMysqli;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use RuntimeException;
use Throwable;

/** Guards the reporting transport contract. */
final class SafeMySqlCoordinationReportingFailureContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    /** @dataProvider loggerFailures */
    public function testValidationReportingFailureRetainsTheExactRefusal(
        string $position,
        bool $reportingIsError
    ): void {
        $reporting = $this->failLogger($position, $reportingIsError);
        $operation = new InvalidArgumentException('Exact validation refusal.');
        $descriptor = $this->createMock(Table::class);
        $descriptor->method('getName')->willThrowException($operation);
        $calls = 0;

        $failure = $this->captureReportingFailure(function () use ($descriptor, &$calls): void {
            $this->strategy->coordinate(
                $this->parents,
                ['tenantId' => 1, 'id' => 7],
                [$descriptor],
                static function () use (&$calls): void {
                    $calls++;
                }
            );
        });

        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame(0, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertReportingAttempt(
            $position,
            'validation',
            'unchanged',
            false,
            InvalidArgumentException::class,
            tables: []
        );
    }

    /** @dataProvider loggerFailures */
    public function testCallbackReportingFailureRetainsTheExactRolledBackFailure(
        string $position,
        bool $reportingIsError
    ): void {
        $reporting = $this->failLogger($position, $reportingIsError);
        $operation = new RuntimeException('Exact callback failure.');
        $calls = 0;

        $failure = $this->captureReportingFailure(function () use ($operation, &$calls): void {
            $this->coordinate(function (DatabaseStrategy $backend) use ($operation, &$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                throw $operation;
            });
        });

        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertReportingAttempt($position, 'callback', 'rolled_back', false, RuntimeException::class);
        self::assertSame([['id' => '42']], $this->strategy->query('SELECT 42 AS id'));
    }

    /** @dataProvider loggerFailures */
    public function testConflictReportingFailureRetainsTheClassifiedConflict(
        string $position,
        bool $reportingIsError
    ): void {
        $mysqli = $this->connect(InactiveQueryRollbackMysqli::class);
        $this->usePrimary($mysqli);
        $reporting = $this->failLogger($position, $reportingIsError);
        $calls = 0;
        $observed = null;

        $failure = $this->captureReportingFailure(function () use ($mysqli, &$calls, &$observed): void {
            $this->coordinate(function (DatabaseStrategy $backend) use ($mysqli, &$calls, &$observed): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                $mysqli->faultQuery = 'SELECT 123 AS injected_rollback';
                try {
                    $backend->query($mysqli->faultQuery);
                } catch (DatastoreErrorException $failure) {
                    $observed = $failure;
                    throw $failure;
                }
            });
        });
        $operation = $failure->getOperationFailure();

        self::assertInstanceOf(CoordinatedOperationConflictException::class, $operation);
        self::assertSame($observed, $operation->getPrevious());
        self::assertInstanceOf(DatastoreErrorException::class, $observed);
        self::assertInstanceOf(MysqliDriverException::class, $observed->getPrevious());
        self::assertSame($mysqli->faultCause, $observed->getPrevious()->getPrevious());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->injectedFailures);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertReportingAttempt(
            $position,
            'callback',
            'rolled_back',
            true,
            DatastoreErrorException::class,
            '40001',
            1213
        );
    }

    /** @dataProvider loggerFailures */
    public function testCommitReportingFailureRetainsTheUnknownCommittedOutcome(
        string $position,
        bool $reportingIsError
    ): void {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'commit';
        $mysqli->afterOperation = true;
        $this->usePrimary($mysqli);
        $reporting = $this->failLogger($position, $reportingIsError);
        $calls = 0;

        $failure = $this->captureReportingFailure(function () use (&$calls): void {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            });
        });
        $operation = $failure->getOperationFailure();

        self::assertInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $operation);
        self::assertInstanceOf(MysqliDriverException::class, $operation->getPrevious());
        self::assertSame($mysqli->commitFaultCause, $operation->getPrevious()->getPrevious());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame(1, $mysqli->commitCalls);
        self::assertSame(0, $mysqli->rollbackCalls);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertReportingAttempt(
            $position,
            'commit',
            'unknown',
            false,
            MysqliDriverException::class,
            'HY000',
            2013
        );
    }

    /** @dataProvider loggerFailures */
    public function testCleanupReportingFailureRetainsBothOperationCauses(
        string $position,
        bool $reportingIsError
    ): void {
        $mysqli = $this->connect(OutcomeFaultMysqli::class);
        $mysqli->faultAt = 'rollback';
        $mysqli->afterOperation = true;
        $this->usePrimary($mysqli);
        $reporting = $this->failLogger($position, $reportingIsError);
        $callbackFailure = new RuntimeException('Exact callback failure before cleanup.');
        $calls = 0;

        $failure = $this->captureReportingFailure(function () use ($callbackFailure, &$calls): void {
            $this->coordinate(function (DatabaseStrategy $backend) use ($callbackFailure, &$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                throw $callbackFailure;
            });
        });
        $operation = $failure->getOperationFailure();

        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $operation);
        self::assertSame($callbackFailure, $operation->getOperationFailure());
        self::assertInstanceOf(MysqliDriverException::class, $operation->getPrevious());
        self::assertSame($mysqli->faultCause, $operation->getPrevious()->getPrevious());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame(0, $mysqli->commitCalls);
        self::assertSame(1, $mysqli->rollbackCalls);
        self::assertFalse($this->inTransaction($mysqli));
        self::assertSame([], $this->visibleEffects());
        $this->assertReportingAttempt(
            $position,
            'rollback',
            'unknown',
            false,
            MysqliDriverException::class,
            'HY000',
            2013,
            priorFailure: [
                'phase' => 'callback',
                'causeClass' => RuntimeException::class,
                'sqlState' => null,
                'driverCode' => null,
            ]
        );
    }

    /** @dataProvider loggerFailures */
    public function testSuccessfulCommitDoesNotTouchTheBrokenLogger(
        string $position,
        bool $reportingIsError
    ): void {
        $this->failLogger($position, $reportingIsError);
        $calls = 0;

        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(1, $calls);
        self::assertSame(0, $this->logger->writeAttempts);
        self::assertSame([], $this->logger->entries);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    private function failLogger(string $position, bool $reportingIsError): Throwable
    {
        $failure = $reportingIsError
            ? new Error('Exact logger transport error.')
            : new RuntimeException('Exact logger transport exception.');
        $this->logger->transportFailure = $failure;
        $this->logger->throwBeforeWrite = $position === 'before';
        $this->logger->throwOnWrite = $position === 'after';

        return $failure;
    }

    /** @param callable(): void $operation */
    private function captureReportingFailure(callable $operation): CoordinatedOperationReportingFailedException
    {
        try {
            $operation();
            self::fail('A logger transport failure must escape with the classified operation failure.');
        } catch (CoordinatedOperationReportingFailedException $failure) {
            return $failure;
        }
    }

    /**
     * @param list<string>|null $tables
     * @param array{phase:string, causeClass:class-string, sqlState:?string, driverCode:?int}|null $priorFailure
     */
    private function assertReportingAttempt(
        string $position,
        string $phase,
        string $outcome,
        bool $retryable,
        string $causeClass,
        ?string $sqlState = null,
        ?int $driverCode = null,
        ?array $tables = null,
        ?array $priorFailure = null
    ): void {
        self::assertSame(1, $this->logger->writeAttempts);
        if ($position === 'before') {
            self::assertSame([], $this->logger->entries);
            return;
        }
        $this->assertFailureLog(
            $phase,
            $outcome,
            $retryable,
            $causeClass,
            $sqlState,
            $driverCode,
            $tables,
            $priorFailure
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function loggerFailures(): array
    {
        return [
            'exception before recording' => ['before', false],
            'error before recording' => ['before', true],
            'exception after recording' => ['after', false],
            'error after recording' => ['after', true],
        ];
    }
}
