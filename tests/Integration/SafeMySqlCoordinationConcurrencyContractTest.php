<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\SafeMySqlCoordinationClient;
use RuntimeException;
use Throwable;

/** Independent clients plus server-observed blocking, never elapsed-time proof. */
final class SafeMySqlCoordinationConcurrencyContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    /** @var list<SafeMySqlCoordinationClient> */
    private array $clients = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->clients as $client) {
                $client->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @dataProvider overlappingEffects */
    public function testOverlappingEffectsWaitAndReadThePrecedingCommittedResult(string $isolation, string $kind): void
    {
        $claimTable = $this->createClaims();
        if ($kind !== 'absent child') {
            $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10)');
        }
        $first = $this->client($isolation, $claimTable, 1, 1, 2, $kind === 'duplicate claim' ? 31 : 0);
        $first->send(['action' => 'run']);
        $firstHolding = $first->await('HOLDING');
        $firstResult = $firstHolding['result'] ?? null;
        self::assertIsArray($firstResult);
        self::assertSame($kind === 'absent child' ? 0 : 10, $firstResult['before']);

        $second = $this->client($isolation, $claimTable, 1, 1, 3, $kind === 'duplicate claim' ? 31 : 0);
        $second->send(['action' => 'run']);
        $second->await('ATTEMPT');
        self::assertSame('blocked', $this->observeWaitOrEntry($second, $first),
            'The same complete parent identity must serialize callback entry.');

        $first->send(['action' => 'release']);
        $this->assertClientFinished($first);
        $secondHolding = $second->await('HOLDING');
        $secondResult = $secondHolding['result'] ?? null;
        self::assertIsArray($secondResult);
        $preceding = $kind === 'absent child' ? 2 : 12;
        self::assertSame($preceding, $secondResult['before'],
            'The callback must see the predecessor, not a pre-wait snapshot.');
        self::assertSame($kind !== 'duplicate claim', $secondResult['applied']);
        $second->send(['action' => 'release']);
        $this->assertClientFinished($second);

        $expected = $kind === 'duplicate claim' ? 12 : $preceding + 3;
        self::assertSame([['id' => '1', 'score' => (string) $expected]], $this->visibleEffects());
        $claims = $this->observer->query('SELECT id FROM `' . $claimTable . '`');
        self::assertNotFalse($claims);
        self::assertSame($kind === 'duplicate claim' ? [['31']] : [], $claims->fetch_all());
    }

    /** @dataProvider distinctCompoundIdentities */
    public function testDistinctCompoundIdentitiesProgressWhileTheFirstOwnerStillHoldsItsGuard(string $isolation, int $tenant, int $record): void
    {
        $claimTable = $this->createClaims();
        $this->observer->query('INSERT INTO `' . $this->parents->getName() . '` VALUES (1, 8)');
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10), (2, 10)');
        $first = $this->client($isolation, $claimTable, 1, 1, 2, 0);
        $first->send(['action' => 'run']);
        $first->await('HOLDING');

        $second = $this->client($isolation, $claimTable, $tenant, 2, 3, 0, ['recordId' => $record]);
        $second->send(['action' => 'run']);
        $second->await('ATTEMPT');
        self::assertSame('entered', $this->observeWaitOrEntry($second, $first),
            'Changing either component of the primary identity must permit independent progress.');
        $second->await('HOLDING');
        self::assertSame([['id' => '1', 'score' => '10'], ['id' => '2', 'score' => '10']], $this->visibleEffects());

        $second->send(['action' => 'release']);
        $this->assertClientFinished($second);
        self::assertSame([['id' => '1', 'score' => '10'], ['id' => '2', 'score' => '13']], $this->visibleEffects());
        $first->send(['action' => 'release']);
        $this->assertClientFinished($first);
        self::assertSame([['id' => '1', 'score' => '12'], ['id' => '2', 'score' => '13']], $this->visibleEffects());
    }

    /** @dataProvider supportedIsolationLevels */
    public function testARealDeadlockAbortsOneWholeEffectWithoutReplayingItsCallback(string $isolation): void
    {
        $detect = $this->observer->query('SELECT @@innodb_deadlock_detect');
        self::assertNotFalse($detect);
        if ((int) $detect->fetch_row()[0] !== 1) {
            $this->markTestSkipped('The real-deadlock contract requires server deadlock detection.');
        }
        $claims = $this->createClaims();
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10), (2, 10)');
        $first = $this->client($isolation, $claims, 1, 1, 2, 0, ['thenEffectId' => 2]);
        $second = $this->client($isolation, $claims, 2, 2, 3, 0, ['thenEffectId' => 1]);
        $first->send(['action' => 'run']);
        $first->await('HOLDING');
        $second->send(['action' => 'run']);
        $second->await('HOLDING');

        $first->send(['action' => 'release']);
        self::assertSame('blocked', $this->observeWaitOrEntry($first, $second, $this->effects->getName()));
        $second->send(['action' => 'release']);
        $firstResult = $first->finishOutcome();
        $secondResult = $second->finishOutcome();
        $firstWon = $firstResult['event'] === 'DONE';
        $winner = $firstWon ? $firstResult : $secondResult;
        $loser = $firstWon ? $secondResult : $firstResult;
        self::assertSame('DONE', $winner['event']);
        self::assertSame(0, $winner['exitCode']);
        self::assertSame(1, $winner['callbackCalls']);
        self::assertSame([], $winner['logs']);
        $this->assertConflictOutcome($loser, $claims, 'callback', 1, '40001', 1213);
        $expected = $firstWon ? '12' : '13';
        self::assertSame([['id' => '1', 'score' => $expected], ['id' => '2', 'score' => $expected]], $this->visibleEffects());
    }

    /** @dataProvider supportedIsolationLevels */
    public function testALockWaitTimeoutAbortsTheWholeAttemptAndLeavesItsPredecessorAlone(string $isolation): void
    {
        $claims = $this->createClaims();
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10)');
        $first = $this->client($isolation, $claims, 1, 1, 2, 0);
        $first->send(['action' => 'run']);
        $first->await('HOLDING');
        $second = $this->client($isolation, $claims, 1, 1, 3, 0, ['lockWaitSeconds' => 5]);
        $second->send(['action' => 'run']);
        $second->await('ATTEMPT');
        self::assertSame('blocked', $this->observeWaitOrEntry($second, $first));
        $this->assertConflictOutcome($second->finishOutcome(), $claims, 'coordination', 0, 'HY000', 1205);
        self::assertSame([['id' => '1', 'score' => '10']], $this->visibleEffects());
        $first->send(['action' => 'release']);
        $this->assertClientFinished($first);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
    }

    /** @dataProvider supportedIsolationLevels */
    public function testACallbackTimeoutRollsBackItsEarlierWriteAndClaim(string $isolation): void
    {
        $claims = $this->createClaims();
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10), (2, 10)');
        $first = $this->client($isolation, $claims, 1, 1, 2, 0);
        $first->send(['action' => 'run']);
        $first->await('HOLDING');
        $second = $this->client($isolation, $claims, 2, 2, 3, 31, ['thenEffectId' => 1, 'lockWaitSeconds' => 5]);
        $second->send(['action' => 'run']);
        $second->await('HOLDING');
        $second->send(['action' => 'release']);
        self::assertSame('blocked', $this->observeWaitOrEntry($second, $first, $this->effects->getName()));
        $this->assertConflictOutcome($second->finishOutcome(), $claims, 'callback', 1, 'HY000', 1205);
        self::assertSame([['id' => '1', 'score' => '10'], ['id' => '2', 'score' => '10']], $this->visibleEffects());
        $claimRows = $this->observer->query('SELECT id FROM `' . $claims . '`');
        self::assertNotFalse($claimRows);
        self::assertSame([], $claimRows->fetch_all());
        $first->send(['action' => 'release']);
        $this->assertClientFinished($first);
        self::assertSame([['id' => '1', 'score' => '12'], ['id' => '2', 'score' => '10']], $this->visibleEffects());
    }

    /** @dataProvider participantDefinitionRaces */
    public function testParticipantDefinitionsStayLockedBeforeTheCallbackFirstUsesThem(string $isolation, string $participant): void
    {
        $claims = $this->createClaims();
        $target = ['first' => $this->parents->getName(), 'middle' => $this->effects->getName(), 'last' => $claims][$participant];
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 10)');
        $owner = $this->client($isolation, $claims, 1, 1, 2, 31, ['holdBeforeIo' => 1]);
        $owner->send(['action' => 'run']);
        $owner->await('BEFORE_IO');
        $ddl = new SafeMySqlCoordinationClient(['mode' => 'ddl', 'table' => $target]);
        $this->clients[] = $ddl;
        $ddl->send(['action' => 'run']);
        $ddl->await('ATTEMPT');
        self::assertSame('blocked', $this->observeMetadataWaitOrCompletion($ddl, $owner, $target),
            'An authorized participant must not change engine before its first callback write.');

        $owner->send(['action' => 'continue']);
        $owner->await('HOLDING');
        $owner->send(['action' => 'release']);
        $this->assertClientFinished($owner);
        self::assertSame(0, $ddl->finish()['exitCode']);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        $claimRows = $this->observer->query('SELECT id FROM `' . $claims . '`');
        self::assertNotFalse($claimRows);
        self::assertSame([['31']], $claimRows->fetch_all());
        $engine = $this->observer->execute_query(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$target]
        );
        self::assertNotFalse($engine);
        self::assertSame('MyISAM', $engine->fetch_row()[0]);
    }

    private function createClaims(): string
    {
        $name = str_replace('_parents', '_claims', $this->parents->getName());
        $this->createTable($name, '(id BIGINT PRIMARY KEY)');
        return $name;
    }

    /** @param array<string, int|string> $extra */
    private function client(string $isolation, string $claims, int $tenant, int $effect, int $delta, int $claim, array $extra = []): SafeMySqlCoordinationClient
    {
        $client = new SafeMySqlCoordinationClient($extra + [
            'isolation' => $isolation, 'parents' => $this->parents->getName(), 'effects' => $this->effects->getName(),
            'claims' => $claims, 'tenantId' => $tenant, 'recordId' => 7, 'effectId' => $effect, 'delta' => $delta, 'claimId' => $claim,
        ]);
        $this->clients[] = $client;
        return $client;
    }

    private function observeWaitOrEntry(SafeMySqlCoordinationClient $waiting, SafeMySqlCoordinationClient $holding, ?string $table = null): string
    {
        $sql = 'SELECT COUNT(*) FROM performance_schema.data_lock_waits w
            JOIN performance_schema.threads requester ON requester.THREAD_ID = w.REQUESTING_THREAD_ID
            JOIN performance_schema.threads blocker ON blocker.THREAD_ID = w.BLOCKING_THREAD_ID
            JOIN performance_schema.data_locks l ON l.ENGINE = w.ENGINE AND l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
            WHERE requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
              AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = ?';
        $deadline = microtime(true) + 15;
        do {
            $frame = $waiting->read();
            if (($frame['event'] ?? null) === 'CALLBACK_ENTERED') {
                return 'entered';
            }
            if (($frame['event'] ?? null) === 'DONE') {
                return 'completed';
            }
            $result = $this->observer->execute_query($sql, [$waiting->connectionId, $holding->connectionId, $table ?? $this->parents->getName()]);
            self::assertNotFalse($result);
            if ((int) $result->fetch_row()[0] > 0) {
                return 'blocked';
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Neither callback entry nor a server lock wait became observable. Check the client protocol and lock-table access.');
    }

    private function observeMetadataWaitOrCompletion(SafeMySqlCoordinationClient $waiting, SafeMySqlCoordinationClient $holding, string $table): string
    {
        $sql = 'SELECT COUNT(*) FROM performance_schema.metadata_locks pending
            JOIN performance_schema.threads waiter ON waiter.THREAD_ID = pending.OWNER_THREAD_ID
            JOIN performance_schema.metadata_locks held ON held.OBJECT_TYPE = pending.OBJECT_TYPE
              AND held.OBJECT_SCHEMA = pending.OBJECT_SCHEMA AND held.OBJECT_NAME = pending.OBJECT_NAME
            JOIN performance_schema.threads owner ON owner.THREAD_ID = held.OWNER_THREAD_ID
            WHERE pending.OBJECT_TYPE = \'TABLE\' AND pending.OBJECT_SCHEMA = DATABASE() AND pending.OBJECT_NAME = ?
              AND pending.LOCK_STATUS = \'PENDING\' AND held.LOCK_STATUS = \'GRANTED\'
              AND waiter.PROCESSLIST_ID = ? AND owner.PROCESSLIST_ID = ?';
        $deadline = microtime(true) + 15;
        do {
            $frame = $waiting->read();
            if (($frame['event'] ?? null) === 'DONE') {
                return 'completed';
            }
            $result = $this->observer->execute_query($sql, [$table, $waiting->connectionId, $holding->connectionId]);
            self::assertNotFalse($result);
            if ((int) $result->fetch_row()[0] > 0) {
                return 'blocked';
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Neither DDL completion nor its metadata wait became observable. Check metadata lock instrumentation.');
    }

    /** @param array<string, mixed> $result */
    private function assertConflictOutcome(array $result, string $claims, string $phase, int $calls, string $sqlState, int $driverCode): void
    {
        self::assertSame('ERROR', $result['event']);
        self::assertSame(1, $result['exitCode']);
        self::assertSame(CoordinatedOperationConflictException::class, $result['class']);
        self::assertSame($calls, $result['callbackCalls']);
        self::assertFalse($result['transactionActive']);
        $logs = $result['logs'];
        self::assertIsArray($logs);
        self::assertCount(1, $logs);
        $entry = $logs[0];
        self::assertIsArray($entry);
        self::assertSame('error', $entry['level']);
        self::assertSame('Coordinated database operation failed.', $entry['message']);
        $context = $entry['context'];
        self::assertIsArray($context);
        $causeClass = $result['causeClass'];
        self::assertIsString($causeClass);
        self::assertTrue(is_a($causeClass, Throwable::class, true),
            'A conflict must retain a real failure cause independently of its log.');
        self::assertSame(['class' => MysqliDriverException::class, 'sqlState' => $sqlState, 'driverCode' => $driverCode],
            $result['driverCause'], 'The conflict cause chain must retain the actual driver failure, independently of its log.');
        $expected = [
            'phase' => $phase, 'tables' => [$this->parents->getName(), $this->effects->getName(), $claims],
            'outcome' => 'rolled_back', 'retryable' => true, 'causeClass' => $causeClass,
            'sqlState' => $sqlState, 'driverCode' => $driverCode,
        ];
        ksort($expected);
        ksort($context);
        self::assertSame($expected, $context);
    }

    private function assertClientFinished(SafeMySqlCoordinationClient $client): void
    {
        $result = $client->finish();
        self::assertSame(0, $result['exitCode']);
        self::assertSame(1, $result['callbackCalls']);
        self::assertSame([], $result['logs']);
    }

    /** @return array<string, array{string}> */
    public static function supportedIsolationLevels(): array
    {
        return ['read committed' => ['READ COMMITTED'], 'repeatable read' => ['REPEATABLE READ']];
    }

    /** @return array<string, array{string, string}> */
    public static function participantDefinitionRaces(): array
    {
        $cases = [];
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['first', 'middle', 'last'] as $participant) {
                $cases[$isolation . ' ' . $participant] = [$isolation, $participant];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, int, int}> */
    public static function distinctCompoundIdentities(): array
    {
        $cases = [];
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            $cases[$isolation . ' tenant component'] = [$isolation, 2, 7];
            $cases[$isolation . ' record component'] = [$isolation, 1, 8];
        }
        return $cases;
    }

    /** @return array<string, array{string, string}> */
    public static function overlappingEffects(): array
    {
        $cases = [];
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach (['additive update', 'absent child', 'duplicate claim'] as $kind) {
                $cases[$isolation . ' ' . $kind] = [$isolation, $kind];
            }
        }
        return $cases;
    }
}
