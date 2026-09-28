<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_sql_exception;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;
use PHPUnit\Framework\TestCase;
use SafeMySQL;
use Throwable;

/** Owns exact fixture tables in an explicitly assigned schema. */
abstract class OwnedSafeMySqlCoordinationContractCase extends TestCase
{
    protected mysqli $primary;
    protected mysqli $observer;
    protected RecordingLogger $logger;
    protected SafeMySqlCoordinatedDatabaseStrategy $strategy;
    protected CoordinationTable $parents;
    protected CoordinationTable $effects;
    /** @var list<string> */
    protected array $ownedTables = [];
    /** @var list<string> */
    protected array $ownedViews = [];
    /** @var list<string> */
    protected array $ownedAccounts = [];

    protected function setUp(): void
    {
        $this->primary = $this->connect();
        $this->observer = $this->connect();
        $prefix = 'nomad_coord_' . bin2hex(random_bytes(6));
        $this->parents = new CoordinationTable($prefix . '_parents', ['tenantId', 'id']);
        $this->effects = new CoordinationTable($prefix . '_effects', ['id']);
        $this->createTable($this->parents->getName(), '(tenantId BIGINT NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (tenantId, id))');
        $this->createTable($this->effects->getName(), '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $this->observer->query('INSERT INTO `' . $this->parents->getName() . '` VALUES (1, 7), (2, 7)');
        $this->logger = new RecordingLogger();
        $this->usePrimary($this->primary);
    }

    protected function tearDown(): void
    {
        try {
            // A test fixture may intercept ROLLBACK itself and never let a
            // real one through (that is the point of an acknowledgement
            // fault). Closing the connection immediately, before any
            // observer-side DDL, guarantees the server drops its locks
            // rather than leaving the observer's DROP TABLE below to wait
            // out MySQL's very long default lock_wait_timeout.
            if (isset($this->primary)) {
                $this->primary->close();
            }
        } finally {
            try {
                foreach (array_reverse($this->ownedViews) as $name) {
                    $this->observer->query('DROP VIEW IF EXISTS `' . $name . '`');
                }
                foreach (array_reverse($this->ownedTables) as $name) {
                    $this->observer->query('DROP TABLE IF EXISTS `' . $name . '`');
                }
                foreach (array_reverse($this->ownedAccounts) as $name) {
                    $this->observer->query('DROP USER IF EXISTS ' . $this->quoteAccount($name));
                }
            } finally {
                if (isset($this->observer)) {
                    $this->observer->close();
                }
                unset($this->strategy, $this->primary, $this->observer);
            }
        }
    }

    protected function usePrimary(mysqli $mysqli): void
    {
        $this->primary = $mysqli;
        $this->strategy = new SafeMySqlCoordinatedDatabaseStrategy(new SafeMySQL(['mysqli' => $mysqli]), $this->logger);
    }

    /**
     * @template TResult
     * @param callable(\PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy): TResult $operation
     * @return TResult
     */
    protected function coordinate(callable $operation)
    {
        return $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], [$this->parents, $this->effects], $operation);
    }

    /** @return list<array<string, mixed>> */
    protected function visibleEffects(): array
    {
        $result = $this->observer->query('SELECT id, score FROM `' . $this->effects->getName() . '` ORDER BY id');
        self::assertNotFalse($result);
        return array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'score' => (string) $row['score']],
            $result->fetch_all(MYSQLI_ASSOC)
        );
    }

    /** Mirrors PDO::inTransaction() using MySQL 8's transaction instrumentation. */
    protected function inTransaction(mysqli $mysqli): bool
    {
        $result = $mysqli->query(
            'SELECT STATE FROM performance_schema.events_transactions_current '
            . 'WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())'
        );
        if (!$result instanceof \mysqli_result) {
            return false;
        }
        $row = $result->fetch_row();
        return ($row[0] ?? null) === 'ACTIVE';
    }

    protected function createTable(string $name, string $columns): void
    {
        $this->observer->query('CREATE TABLE `' . $name . '` ' . $columns . ' ENGINE=InnoDB');
        $this->ownedTables[] = $name;
    }

    /**
     * @param list<string>|null $tables
     * @param array{phase:string, causeClass:class-string, sqlState:?string, driverCode:?int}|null $priorFailure
     */
    protected function assertFailureLog(
        string $phase,
        string $outcome,
        bool $retryable,
        string $causeClass,
        ?string $sqlState = null,
        ?int $driverCode = null,
        ?array $tables = null,
        ?array $priorFailure = null
    ): void {
        $expected = [[
            'level' => 'error',
            'message' => 'Coordinated database operation failed.',
            'context' => [
                'phase' => $phase,
                'tables' => $tables ?? [$this->parents->getName(), $this->effects->getName()],
                'outcome' => $outcome,
                'retryable' => $retryable,
                'causeClass' => $causeClass,
                'sqlState' => $sqlState,
                'driverCode' => $driverCode,
            ],
        ]];
        if ($priorFailure !== null) {
            ksort($priorFailure);
            $expected[0]['context']['priorFailure'] = $priorFailure;
        }
        self::assertCount(1, $this->logger->entries);
        $actual = $this->logger->entries;
        if (isset($actual[0]['context']['priorFailure']) && is_array($actual[0]['context']['priorFailure'])) {
            ksort($actual[0]['context']['priorFailure']);
        }
        ksort($expected[0]['context']);
        ksort($actual[0]['context']);
        self::assertSame($expected, $actual);
    }

    /**
     * @template TMysqli of mysqli
     * @param class-string<TMysqli> $class
     * @return TMysqli
     */
    protected function connect(string $class = mysqli::class): mysqli
    {
        $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
        if (!$dsn) {
            $this->markTestSkipped('TEST_MYSQL_COORDINATION_DSN must name an explicitly owned schema.');
        }
        $parts = self::parseDsn($dsn);
        try {
            $mysqli = new $class(
                $parts['host'] ?? '127.0.0.1',
                getenv('TEST_MYSQL_USER') ?: 'root',
                getenv('TEST_MYSQL_PASS') ?: 'root',
                $parts['dbname'] ?? '',
                (int) ($parts['port'] ?? 3306)
            );
        } catch (mysqli_sql_exception) {
            $this->markTestSkipped('The explicitly configured coordination test database is unavailable.');
        }
        if ($mysqli->connect_errno) {
            $this->markTestSkipped('The explicitly configured coordination test database is unavailable.');
        }
        $mysqli->set_charset($parts['charset'] ?? 'utf8mb4');

        return $mysqli;
    }

    /** @return array<string, string> */
    public static function parseDsn(string $dsn): array
    {
        $parts = [];
        foreach (explode(';', preg_replace('/^mysql:/', '', $dsn)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }
        return $parts;
    }

    private function quoteAccount(string $name): string
    {
        return "'" . str_replace("'", "''", $name) . "'@'%'";
    }
}
