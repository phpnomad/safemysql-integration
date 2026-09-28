<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\ProbeFaultMysqli;
use Throwable;

/**
 * mysqli has no local inTransaction(), so the ownership probe is a round trip
 * and can fail. A failed probe must still end in a classified, logged
 * outcome, never a bare driver error a caller could retry blindly.
 */
final class SafeMySqlCoordinationProbeFailureContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    public function testASessionKilledBeforeCommitIsAnUnconfirmedCleanup(): void
    {
        $failure = $this->coordinateAndCatch(function (DatabaseStrategy $backend): string {
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $this->killPrimary();
            return 'returned';
        });

        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
        self::assertSame(2006, $failure->getPrevious()?->getCode());
        self::assertSame([], $this->visibleEffects());
        $this->assertLoggedOnce('rollback', 'unknown');
    }

    public function testAQueryAfterTheSessionIsKilledIsAnUnconfirmedCleanup(): void
    {
        $failure = $this->coordinateAndCatch(function (DatabaseStrategy $backend): mixed {
            $this->killPrimary();
            return $backend->query('SELECT 1');
        });

        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
        self::assertSame([], $this->visibleEffects());
        $this->assertLoggedOnce('rollback', 'unknown');
    }

    public function testADeniedProbeMidCallbackEndsWithoutRecursing(): void
    {
        $mysqli = $this->connect(ProbeFaultMysqli::class);
        $this->usePrimary($mysqli);

        $failure = $this->coordinateAndCatch(function (DatabaseStrategy $backend) use ($mysqli): mixed {
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            $mysqli->denyProbe = true;
            return $backend->query('SELECT 1');
        });

        self::assertInstanceOf(CoordinatedOperationCleanupFailedException::class, $failure);
        $probe = $failure->getPrevious();
        self::assertInstanceOf(MysqliDriverException::class, $probe);
        self::assertSame(1142, $probe->getCode());
        self::assertSame(2, $mysqli->deniedProbes);
        // The best-effort ROLLBACK released the insert and its locks.
        self::assertSame([], $this->visibleEffects());
        $this->assertLoggedOnce('rollback', 'unknown');
    }

    public function testASessionKilledDuringCommitIsAnUnknownOutcome(): void
    {
        $mysqli = $this->connect(ProbeFaultMysqli::class);
        $mysqli->killOnCommitWith = $this->observer;
        $this->usePrimary($mysqli);

        $failure = $this->coordinateAndCatch(function (DatabaseStrategy $backend): string {
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            return 'returned';
        });

        self::assertInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
        self::assertSame([], $this->visibleEffects());
        $this->assertLoggedOnce('commit', 'unknown');
    }

    private function coordinateAndCatch(callable $operation): Throwable
    {
        try {
            $this->coordinate($operation);
        } catch (Throwable $failure) {
            return $failure;
        }
        self::fail('The coordinated operation was expected to fail.');
    }

    private function killPrimary(): void
    {
        $this->observer->query('KILL ' . $this->primary->thread_id);
        // KILL returns before the server has torn the session down.
        usleep(100000);
    }

    private function assertLoggedOnce(string $phase, string $outcome): void
    {
        self::assertCount(1, $this->logger->entries);
        self::assertSame($phase, $this->logger->entries[0]['context']['phase']);
        self::assertSame($outcome, $this->logger->entries[0]['context']['outcome']);
        self::assertFalse($this->logger->entries[0]['context']['retryable']);
    }
}
