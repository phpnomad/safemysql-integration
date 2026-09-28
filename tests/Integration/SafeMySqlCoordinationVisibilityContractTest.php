<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use mysqli;
use mysqli_sql_exception;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\CoordinationTable;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;

/** Real account grants distinguish absent metadata from invisible metadata. */
final class SafeMySqlCoordinationVisibilityContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    private ?int $previousPartialRevokes = null;
    private ?string $otherOwnedSchema = null;
    private bool $transactionConsumerDisabled = false;

    protected function tearDown(): void
    {
        try {
            if (isset($this->observer)) {
                if ($this->transactionConsumerDisabled) {
                    $this->observer->query("UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME = 'events_transactions_current'");
                }
                if ($this->previousPartialRevokes !== null) {
                    $this->observer->query('SET GLOBAL partial_revokes = ' . $this->previousPartialRevokes);
                }
                if ($this->otherOwnedSchema !== null) {
                    $this->observer->query('DROP DATABASE `' . $this->otherOwnedSchema . '`');
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @dataProvider insufficientVisibility */
    public function testUnprovedVisibilityRefusesBeforeTheCallbackEvenWhenNoTriggerExists(string $coverage): void
    {
        $this->useRestrictedAccount($coverage);
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('Every participant needs proved trigger visibility.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    /** @dataProvider sufficientVisibility */
    public function testDirectGrantsPermitCoordinationWithoutGlobalPrivileges(string $coverage): void
    {
        $this->useRestrictedAccount($coverage);
        $calls = 0;
        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): string {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(1, $calls);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->logger->entries);
    }

    /** @dataProvider invisibleTriggerCoverage */
    public function testAnInvisibleTriggerCannotLeakANontransactionalWrite(string $coverage): void
    {
        $hidden = 'nomad_hidden_' . bin2hex(random_bytes(6));
        $this->observer->query('CREATE TABLE `' . $hidden . '` (id BIGINT PRIMARY KEY, score BIGINT) ENGINE=MyISAM');
        $this->ownedTables[] = $hidden;
        $this->observer->query('CREATE TRIGGER `' . $hidden . '_trigger` AFTER INSERT ON `' .
            $this->effects->getName() . '` FOR EACH ROW INSERT INTO `' . $hidden . '` VALUES (NEW.id, NEW.score)');
        $this->useRestrictedAccount($coverage);
        $visible = $this->primary->execute_query(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            [$this->effects->getName()]
        );
        self::assertNotFalse($visible);
        self::assertSame([], $visible->fetch_all(), 'The hazardous trigger must be invisible to the operation account.');
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            });
            self::fail('Invisible metadata is not evidence of safe storage.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }
        self::assertSame([], $this->visibleEffects());
        $hiddenRows = $this->observer->query('SELECT * FROM `' . $hidden . '`');
        self::assertNotFalse($hiddenRows);
        self::assertSame([], $hiddenRows->fetch_all());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    /** @dataProvider participantGrantCoverage */
    public function testDirectTableGrantsMustCoverEveryParticipant(?int $missingPosition): void
    {
        $third = new CoordinationTable($this->effects->getName() . '_third', ['id']);
        $this->createTable($third->getName(), '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $participants = [$this->parents, $this->effects, $third];
        $names = array_map(static fn (CoordinationTable $table): string => $table->getName(), $participants);
        $granted = $names;
        if ($missingPosition !== null) {
            unset($granted[$missingPosition]);
        }
        $this->useRestrictedAccount('direct tables', array_values($granted));
        $calls = 0;
        try {
            $result = $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], $participants,
                function (DatabaseStrategy $backend) use (&$calls, $third): string {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 13)', $third->getName()));
                    return 'committed';
                }
            );
            self::assertNull($missingPosition, 'Missing direct visibility must refuse before the callback.');
            self::assertSame('committed', $result);
        } catch (UnsupportedCoordinationException $failure) {
            self::assertNotNull($missingPosition, 'Complete direct table grants must be accepted.');
        }
        self::assertSame($missingPosition === null ? 1 : 0, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame($missingPosition === null ? [['id' => '1', 'score' => '12']] : [], $this->visibleEffects());
        $thirdRows = $this->observer->query('SELECT * FROM `' . $third->getName() . '`');
        self::assertNotFalse($thirdRows);
        self::assertSame(
            $missingPosition === null ? [['id' => '1', 'score' => '13']] : [],
            array_map(static fn (array $row): array => array_map('strval', $row), $thirdRows->fetch_all(MYSQLI_ASSOC))
        );
        if ($missingPosition === null) {
            self::assertSame([], $this->logger->entries);
        } else {
            $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class, null, null, $names);
        }
    }

    /** @return array<string, array{?int}> */
    public function testAnAccountThatCannotReadTheTransactionProbeIsRefusedBeforeAnyChange(): void
    {
        $this->useRestrictedAccount('schema', [], false);
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('An account that cannot prove transaction ownership must not coordinate.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
            self::assertStringContainsString('SELECT on performance_schema.threads', $failure->getMessage());
            self::assertSame(1142, $failure->getPrevious()?->getCode());
        }
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class, '42000', 1142);
    }

    public function testDisabledTransactionInstrumentationIsRefusedBeforeTheCallback(): void
    {
        $this->observer->query("UPDATE performance_schema.setup_consumers SET ENABLED = 'NO' WHERE NAME = 'events_transactions_current'");
        $this->transactionConsumerDisabled = true;
        $this->useRestrictedAccount('schema');
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('Without transaction instrumentation ownership cannot be proved.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
            self::assertStringContainsString('instrumentation is off', $failure->getMessage());
        }
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    public static function participantGrantCoverage(): array
    {
        return ['first absent' => [0], 'middle absent' => [1], 'last absent' => [2], 'all present' => [null]];
    }

    /** @param list<string> $triggerTables */
    private function useRestrictedAccount(string $coverage, array $triggerTables = [], bool $probeGrant = true): void
    {
        if ($coverage === 'partial revoke') {
            $setting = $this->observer->query('SELECT @@GLOBAL.partial_revokes');
            self::assertNotFalse($setting);
            $this->previousPartialRevokes = (int) $setting->fetch_row()[0];
            $this->observer->query('SET GLOBAL partial_revokes = 1');
        }
        $name = 'nomad_coord_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(24));
        $account = $this->quoteAccount($name);
        $this->observer->query('CREATE USER ' . $account . ' IDENTIFIED BY \'' . $this->observer->real_escape_string($password) . '\'');
        $this->ownedAccounts[] = $name;
        $schemaQuery = $this->observer->query('SELECT DATABASE()');
        self::assertNotFalse($schemaQuery);
        $schema = $schemaQuery->fetch_row()[0];
        self::assertIsString($schema);
        self::assertNotSame('', $schema);
        $database = '`' . str_replace('`', '``', $schema) . '`';
        $this->observer->query('GRANT SELECT, INSERT, UPDATE, DELETE ON ' . $database . '.* TO ' . $account);
        // mysqli has no inTransaction(), so the adapter reads MySQL 8's
        // transaction instrumentation instead. These four tables are the whole
        // grant it needs, separate from the trigger visibility under test.
        if ($probeGrant) {
            foreach (['threads', 'events_transactions_current', 'setup_consumers', 'setup_instruments'] as $table) {
                $this->observer->query('GRANT SELECT ON performance_schema.' . $table . ' TO ' . $account);
            }
        }
        if (in_array($coverage, ['other schema', 'other schema tables'], true)) {
            $other = 'nomad_scope_' . bin2hex(random_bytes(6));
            $this->observer->query('CREATE DATABASE `' . $other . '`');
            $this->otherOwnedSchema = $other;
            if ($coverage === 'other schema') {
                $this->observer->query('GRANT TRIGGER ON `' . $other . '`.* TO ' . $account);
            } else {
                foreach ([$this->parents->getName(), $this->effects->getName()] as $table) {
                    $this->observer->query('CREATE TABLE `' . $other . '`.`' . $table . '` (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
                    $this->observer->query('GRANT TRIGGER ON `' . $other . '`.`' . $table . '` TO ' . $account);
                }
            }
        }
        if ($coverage === 'schema') {
            $this->observer->query('GRANT TRIGGER ON ' . $database . '.* TO ' . $account);
        }
        if ($coverage === 'schema all') {
            $this->observer->query('GRANT ALL PRIVILEGES ON ' . $database . '.* TO ' . $account);
        }
        if ($coverage === 'global trigger') {
            $this->observer->query('GRANT TRIGGER ON *.* TO ' . $account);
        }
        if ($coverage === 'global all') {
            $this->observer->query('GRANT ALL PRIVILEGES ON *.* TO ' . $account);
        }
        if ($coverage === 'schema wildcard') {
            $pattern = str_replace('`', '``', $schema . '%');
            $this->observer->query('GRANT TRIGGER ON `' . $pattern . '`.* TO ' . $account);
        }
        if ($coverage === 'escaped schema pattern') {
            $pattern = str_replace(['_', '%'], ['\\_', '\\%'], $schema);
            if ($pattern === $schema) {
                $pattern .= '\\%';
            }
            $this->observer->query('GRANT TRIGGER ON `' . str_replace('`', '``', $pattern) . '`.* TO ' . $account);
        }
        if ($coverage === 'table all') {
            foreach ([$this->parents->getName(), $this->effects->getName()] as $table) {
                $this->observer->query('GRANT ALL PRIVILEGES ON ' . $database . '.`' . $table . '` TO ' . $account);
            }
        }
        foreach ($triggerTables as $table) {
            $this->observer->query('GRANT TRIGGER ON ' . $database . '.`' . $table . '` TO ' . $account);
        }
        if ($coverage === 'partial revoke') {
            $this->observer->query('GRANT TRIGGER ON *.* TO ' . $account);
            $this->observer->query('REVOKE TRIGGER ON ' . $database . '.* FROM ' . $account);
        }
        if (in_array($coverage, ['parent only', 'each table'], true)) {
            $this->observer->query('GRANT TRIGGER ON ' . $database . '.`' . $this->parents->getName() . '` TO ' . $account);
        }
        if (in_array($coverage, ['effect only', 'each table'], true)) {
            $this->observer->query('GRANT TRIGGER ON ' . $database . '.`' . $this->effects->getName() . '` TO ' . $account);
        }
        if ($coverage === 'role only') {
            $roleName = $name . '_r';
            $role = $this->quoteAccount($roleName);
            $this->observer->query('CREATE ROLE ' . $role);
            $this->ownedAccounts[] = $roleName;
            $this->observer->query('GRANT TRIGGER ON ' . $database . '.* TO ' . $role);
            $this->observer->query('GRANT ' . $role . ' TO ' . $account);
            $this->observer->query('SET DEFAULT ROLE ' . $role . ' TO ' . $account);
        }
        $parts = self::parseDsn((string) getenv('TEST_MYSQL_COORDINATION_DSN'));
        $mysqli = new mysqli($parts['host'] ?? '127.0.0.1', $name, $password, $parts['dbname'] ?? '', (int) ($parts['port'] ?? 3306));
        $mysqli->set_charset($parts['charset'] ?? 'utf8mb4');
        if ($coverage === 'role only') {
            $roles = $mysqli->query('SELECT CURRENT_ROLE()');
            self::assertNotFalse($roles);
            self::assertNotSame('NONE', $roles->fetch_row()[0], 'The unsupported role-only profile must have an active role.');
        }
        $this->usePrimary($mysqli);
    }

    private function quoteAccount(string $name): string
    {
        return "'" . str_replace("'", "''", $name) . "'@'%'";
    }

    /** @return array<string, array{string}> */
    public static function insufficientVisibility(): array
    {
        return [
            'none' => ['none'], 'parent only' => ['parent only'], 'effect only' => ['effect only'], 'role only' => ['role only'],
            'schema wildcard' => ['schema wildcard'], 'escaped schema pattern' => ['escaped schema pattern'],
            'other schema' => ['other schema'], 'other schema tables' => ['other schema tables'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function sufficientVisibility(): array
    {
        return [
            'schema' => ['schema'], 'each table' => ['each table'],
            'schema all' => ['schema all'], 'table all' => ['table all'],
            'global trigger' => ['global trigger'], 'global all' => ['global all'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invisibleTriggerCoverage(): array
    {
        return ['no grant' => ['none'], 'global grant partially revoked' => ['partial revoke']];
    }
}
