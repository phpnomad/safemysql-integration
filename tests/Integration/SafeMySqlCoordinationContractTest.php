<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration;

use Error;
use InvalidArgumentException;
use mysqli;
use mysqli_sql_exception;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\CoordinationTable;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\OwnedSafeMySqlCoordinationContractCase;
use RuntimeException;
use SafeMySQL;

/** Real-resource atomicity and ownership. Concurrency has a separate contract. */
final class SafeMySqlCoordinationContractTest extends OwnedSafeMySqlCoordinationContractCase
{
    public function testCommitsAllWritesOnTheOwnedConnectionAndReturnsTheExactValue(): void
    {
        $sentinel = new \stdClass();
        $calls = 0;
        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $sentinel) {
            $calls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12), (2, 15)', $this->effects->getName()));
            self::assertSame([], $this->visibleEffects());
            self::assertTrue($this->inTransaction($this->primary));
            return $sentinel;
        });

        self::assertSame($sentinel, $result);
        self::assertSame(1, $calls);
        self::assertSame([['id' => '1', 'score' => '12'], ['id' => '2', 'score' => '15']], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->logger->entries);
    }

    /** @dataProvider supportedIsolationOutcomes */
    public function testSupportedIsolationRemainsSelectedInsideAndAfterTheOperation(string $isolation, bool $fail): void
    {
        $this->primary->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        $expected = str_replace(' ', '-', $isolation);
        $original = new RuntimeException('Rollback isolation probe');
        $calls = 0;
        $caught = null;
        try {
            $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $expected, $fail, $original): string {
                $calls++;
                $setting = $this->primary->query('SELECT @@SESSION.transaction_isolation');
                self::assertNotFalse($setting);
                self::assertSame($expected, $setting->fetch_row()[0], 'The callback must retain the selected isolation.');
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                if ($fail) {
                    throw $original;
                }
                return 'committed';
            });
            self::assertSame('committed', $result);
        } catch (RuntimeException $failure) {
            $caught = $failure;
        }
        self::assertSame($fail ? $original : null, $caught);
        self::assertSame(1, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        $setting = $this->primary->query('SELECT @@SESSION.transaction_isolation');
        self::assertNotFalse($setting);
        self::assertSame($expected, $setting->fetch_row()[0], 'Cleanup must retain the selected isolation.');
        self::assertSame($fail ? [] : [['id' => '1', 'score' => '12']], $this->visibleEffects());
        if ($fail) {
            $this->assertFailureLog('callback', 'rolled_back', false, RuntimeException::class);
        } else {
            self::assertSame([], $this->logger->entries);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function supportedIsolationOutcomes(): array
    {
        return [
            'read committed success' => ['READ COMMITTED', false],
            'read committed failure' => ['READ COMMITTED', true],
            'repeatable read success' => ['REPEATABLE READ', false],
            'repeatable read failure' => ['REPEATABLE READ', true],
        ];
    }

    /**
     * @dataProvider validIdentityValues
     * @param int|string $value
     */
    public function testCompleteIdentityValuesAreNotTreatedAsFlagsOrInterpolated($value): void
    {
        $name = 'nomad_identity_' . bin2hex(random_bytes(6));
        $this->createTable($name, '(tenantId VARBINARY(128) NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (tenantId, id))');
        $this->observer->execute_query('INSERT INTO `' . $name . '` VALUES (?, 7)', [$value]);
        $otherTenant = 'different-tenant-for-proof';
        $this->observer->execute_query('INSERT INTO `' . $name . '` VALUES (?, 7)', [$otherTenant]);
        $parent = new CoordinationTable($name, ['tenantId', 'id']);
        $calls = 0;
        $result = $this->strategy->coordinate($parent, ['id' => 7, 'tenantId' => $value], [$parent, $this->effects],
            function (DatabaseStrategy $backend) use (&$calls, $name, $value, $otherTenant): string {
                $calls++;
                $this->observer->begin_transaction();
                try {
                    $probe = $this->observer->execute_query(
                        'SELECT id FROM `' . $name . '` WHERE tenantId = ? AND id = 7 FOR UPDATE NOWAIT',
                        [$otherTenant]
                    );
                    self::assertNotFalse($probe);
                    self::assertSame([['id' => 7]], $probe->fetch_all(MYSQLI_ASSOC),
                        'The other complete identity must remain independently lockable.');
                    try {
                        $this->observer->execute_query(
                            'SELECT id FROM `' . $name . '` WHERE tenantId = ? AND id = 7 FOR UPDATE NOWAIT',
                            [$value]
                        );
                        self::fail('The supplied complete identity must already be locked by the operation.');
                    } catch (mysqli_sql_exception $failure) {
                        self::assertSame(3572, $failure->getCode(),
                            'The competing lock must fail because NOWAIT found the owned record lock.');
                    }
                } finally {
                    $this->observer->rollback();
                }
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                return 'committed';
            });
        self::assertSame('committed', $result);
        self::assertSame(1, $calls);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->logger->entries);
    }

    /**
     * The PDO contract sets ERRMODE_SILENT here to prove a valid zero-row
     * result still commits under the weaker mode. mysqli has no equivalent
     * per-connection attribute in this codebase (nativeQuery always throws
     * on a real driver failure), so only the zero-row commit behaviour
     * itself is exercised.
     */
    public function testSilentModeCanCommitAValidZeroAffectedRowResult(): void
    {
        $calls = 0;
        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$calls) {
            $calls++;
            return $backend->query($backend->parse('UPDATE ?n SET score = 12 WHERE id = 99', $this->effects->getName()));
        });
        self::assertSame(0, $result);
        self::assertSame(1, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->logger->entries);
    }

    /** @dataProvider callbackFailures */
    public function testEveryCallbackThrowableRollsBackAndPropagatesUnchanged(string $kind): void
    {
        $original = $kind === 'error' ? new Error('private-callback-value') : new RuntimeException('private-callback-value');
        $caught = null;
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $original): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                throw $original;
            });
        } catch (RuntimeException|Error $failure) {
            $caught = $failure;
        }

        self::assertSame($original, $caught);
        self::assertSame(1, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('callback', 'rolled_back', false, get_class($original));
        self::assertSame('reused', $this->coordinate(static fn (): string => 'reused'));
    }

    /**
     * The PDO contract exercises this under both ERRMODE_EXCEPTION and
     * ERRMODE_SILENT. mysqli has no per-connection attribute like that in
     * this codebase: nativeQuery() always converts a real driver failure
     * into an exception, so there is exactly one mode to prove here.
     */
    public function testALaterWriteFailureRollsBackEarlierWritesWithoutRetry(): void
    {
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): void {
                $calls++;
                $sql = $backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName());
                $backend->query($sql);
                $backend->query($sql);
            });
            self::fail('The second write must fail.');
        } catch (DatastoreErrorException $failure) {
            self::assertStringContainsString('Failed to execute query', $failure->getMessage());
        }

        self::assertSame(1, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('callback', 'rolled_back', false, DatastoreErrorException::class, '23000', 1062);
    }

    public function testAMissingParentNeverInvokesTheCallback(): void
    {
        $calls = 0;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 9, 'id' => 7], [$this->parents, $this->effects],
                function () use (&$calls): void { $calls++; });
            self::fail('The parent does not exist.');
        } catch (RecordNotFoundException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', false, RecordNotFoundException::class);
    }

    /**
     * @dataProvider invalidIdentities
     * @param array<array-key, mixed> $identity
     */
    public function testMalformedOrIncompleteIdentityFailsBeforeTheCallback(array $identity): void
    {
        $calls = 0;
        $caught = null;
        try {
            $this->strategy->coordinate($this->parents, $identity, [$this->parents, $this->effects],
                function () use (&$calls): void { $calls++; });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertSame(0, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, InvalidArgumentException::class);
    }

    /** @dataProvider ambientOperations */
    public function testAForeignTransactionRetainsItsWritesAndSavepoint(bool $hasWrite): void
    {
        $this->primary->query('START TRANSACTION');
        if ($hasWrite) {
            $this->primary->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 12)');
        }
        $this->primary->query('SAVEPOINT nomad_foreign_marker');
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('The foreign operation must be refused.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertTrue($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->primary->query('ROLLBACK TO SAVEPOINT nomad_foreign_marker');
        $this->primary->query('COMMIT');
        self::assertSame($hasWrite ? [['id' => '1', 'score' => '12']] : [], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    public function testNestedCoordinationDoesNotFinishTheOuterOperation(): void
    {
        $innerCalls = 0;
        $outerCalls = 0;
        $result = $this->coordinate(function (DatabaseStrategy $backend) use (&$innerCalls, &$outerCalls): string {
            $outerCalls++;
            $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            try {
                $this->coordinate(function () use (&$innerCalls): void { $innerCalls++; });
                self::fail('Nested coordination must fail.');
            } catch (UnsupportedCoordinationException $failure) {
                self::assertSame(0, $innerCalls);
            }
            self::assertTrue($this->inTransaction($this->primary));
            self::assertSame([], $this->visibleEffects());
            return 'outer committed';
        });

        self::assertSame('outer committed', $result);
        self::assertSame(1, $outerCalls);
        self::assertSame(0, $innerCalls);
        self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    public function testDisabledAutocommitIsRefusedWithoutChangingSessionState(): void
    {
        $this->primary->query('SET autocommit = 0');
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('Disabled autocommit must be refused.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }
        $statement = $this->primary->query('SELECT @@autocommit');
        self::assertNotFalse($statement);
        self::assertSame('0', (string) $statement->fetch_row()[0]);
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    public function testANontransactionalParticipantIsRejectedBeforeAnyCallbackWrite(): void
    {
        $name = 'nomad_unsupported_' . bin2hex(random_bytes(6));
        $this->observer->query('CREATE TABLE `' . $name . '` (id INT PRIMARY KEY) ENGINE=MyISAM');
        $this->ownedTables[] = $name;
        $unsupported = new CoordinationTable($name, ['id']);
        $calls = 0;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7],
                [$this->parents, $unsupported, $this->effects], function (DatabaseStrategy $backend) use (&$calls, $name): void {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1)', $name));
                });
            self::fail('A nontransactional participant must be refused.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        $rows = $this->observer->query('SELECT * FROM `' . $name . '`');
        self::assertNotFalse($rows);
        self::assertSame([], $rows->fetch_all());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('coordination', 'rolled_back', false, UnsupportedCoordinationException::class,
            null, null, [$this->parents->getName(), $name, $this->effects->getName()]);
    }

    public function testATemporaryTableCannotMasqueradeAsItsEligiblePermanentNamesake(): void
    {
        $name = $this->effects->getName();
        $this->primary->query('CREATE TEMPORARY TABLE `' . $name . '` (id INT PRIMARY KEY, score INT) ENGINE=InnoDB');
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $name): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $name));
            });
            self::fail('Catalog metadata must not authorize a temporary-table shadow.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        $rows = $this->primary->query('SELECT * FROM `' . $name . '`');
        self::assertNotFalse($rows);
        self::assertSame([], $rows->fetch_all());
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('coordination', 'rolled_back', false, UnsupportedCoordinationException::class);
    }

    public function testAnIncompleteSuppliedIdentityCannotAuthorizeAPartialPrimaryKey(): void
    {
        $inaccurate = new CoordinationTable($this->parents->getName(), ['id']);
        $calls = 0;
        try {
            $this->strategy->coordinate($inaccurate, ['id' => 7], [$inaccurate, $this->effects],
                function () use (&$calls): void { $calls++; });
            self::fail('The identity must match the actual complete primary key.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('validation', 'unchanged', false, InvalidArgumentException::class);
    }

    public function testParticipantAdmissionUsesStableStorageMetadataInsteadOfDescriptorIdentityCallbacks(): void
    {
        $descriptor = $this->createMock(Table::class);
        $descriptor->expects(self::once())->method('getName')->willReturn($this->parents->getName());
        $descriptor->expects(self::never())->method('getFieldsForIdentity');
        $effectDescriptor = $this->createMock(Table::class);
        $effectDescriptor->expects(self::once())->method('getName')->willReturn($this->effects->getName());
        $effectDescriptor->expects(self::never())->method('getFieldsForIdentity');
        $calls = 0;

        $result = $this->strategy->coordinate(
            $descriptor,
            ['tenantId' => 1, 'id' => 7],
            [$descriptor, $effectDescriptor],
            static function () use (&$calls): string {
                $calls++;
                return 'coordinated';
            }
        );

        self::assertSame('coordinated', $result);
        self::assertSame(1, $calls);
        self::assertSame([], $this->logger->entries);
    }

    public function testPreflightIdentityMetadataIsRecheckedAfterTheRecordGuard(): void
    {
        $this->strategy = new ChangedPrimaryMetadataStrategy(
            new SafeMySQL(['mysqli' => $this->primary]),
            $this->logger,
            $this->parents->getName()
        );
        $calls = 0;

        try {
            $this->coordinate(static function () use (&$calls): void {
                $calls++;
            });
            self::fail('Changed primary metadata must refuse the operation before the callback.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('The coordination identity must match the stable primary key.', $failure->getMessage());
        }

        self::assertSame(0, $calls);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('coordination', 'rolled_back', false, InvalidArgumentException::class);
    }

    public function testTheCoordinationTableMustBeAmongTheParticipants(): void
    {
        $calls = 0;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], [$this->effects],
                function () use (&$calls): void { $calls++; });
            self::fail('The coordination table must participate.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('validation', 'unchanged', false, InvalidArgumentException::class,
            null, null, [$this->effects->getName()]);
    }

    /**
     * @dataProvider invalidParticipantShapes
     * @param 'empty'|'named keys'|'sparse list'|'invalid first'|'invalid middle'|'invalid last'|'empty table name' $kind
     */
    public function testEveryParticipantIsValidatedBeforeTheCallback(string $kind): void
    {
        $participants = match ($kind) {
            'empty' => [],
            'named keys' => ['parent' => $this->parents, 'effects' => $this->effects],
            'sparse list' => [0 => $this->parents, 2 => $this->effects],
            'invalid first' => [new \stdClass(), $this->parents, $this->effects],
            'invalid middle' => [$this->parents, new \stdClass(), $this->effects],
            'invalid last' => [$this->parents, $this->effects, new \stdClass()],
            'empty table name' => [$this->parents, new CoordinationTable('', ['id'])],
        };
        $calls = 0;
        $caught = null;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], $participants,
                function () use (&$calls): void { $calls++; });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(InvalidArgumentException::class, $caught);
        self::assertSame(0, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $names = match ($kind) {
            'empty' => [],
            'empty table name' => [$this->parents->getName(), ''],
            default => [$this->parents->getName(), $this->effects->getName()],
        };
        $this->assertFailureLog('validation', 'unchanged', false, InvalidArgumentException::class, null, null, $names);
    }

    /** @dataProvider unsupportedIsolationLevels */
    public function testUnsupportedIsolationRemainsUnchanged(string $isolation): void
    {
        $this->primary->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
        $calls = 0;
        try {
            $this->coordinate(function () use (&$calls): void { $calls++; });
            self::fail('Unsupported isolation cannot authorize a callback.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        $result = $this->primary->query('SELECT @@transaction_isolation');
        self::assertNotFalse($result);
        self::assertSame(str_replace(' ', '-', $isolation), $result->fetch_row()[0]);
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame([], $this->visibleEffects());
        $this->assertFailureLog('validation', 'unchanged', false, UnsupportedCoordinationException::class);
    }

    public function testAViewCannotAuthorizeItsUnderlyingTables(): void
    {
        $name = 'nomad_view_' . bin2hex(random_bytes(6));
        $this->observer->query('CREATE VIEW `' . $name . '` AS SELECT * FROM `' . $this->effects->getName() . '`');
        $this->ownedViews[] = $name;
        $view = new CoordinationTable($name, ['id']);
        $calls = 0;
        try {
            $this->strategy->coordinate($this->parents, ['tenantId' => 1, 'id' => 7], [$this->parents, $view],
                function (DatabaseStrategy $backend) use (&$calls, $name): void {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $name));
                });
            self::fail('A view does not establish eligible participants.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('coordination', 'rolled_back', false, UnsupportedCoordinationException::class,
            null, null, [$this->parents->getName(), $name]);
    }

    public function testATriggerCannotWriteAnUndeclaredTable(): void
    {
        $hiddenName = 'nomad_hidden_' . bin2hex(random_bytes(6));
        $this->createTable($hiddenName, '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $this->observer->query('CREATE TRIGGER `' . $hiddenName . '_trigger` AFTER INSERT ON `' .
            $this->effects->getName() . '` FOR EACH ROW INSERT INTO `' . $hiddenName . '` VALUES (NEW.id, NEW.score)');
        $calls = 0;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
            });
            self::fail('A trigger-bearing participant is unsupported.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        self::assertSame([], $this->visibleEffects());
        $hidden = $this->observer->query('SELECT * FROM `' . $hiddenName . '`');
        self::assertNotFalse($hidden);
        self::assertSame([], $hidden->fetch_all());
        self::assertFalse($this->inTransaction($this->primary));
        $this->assertFailureLog('coordination', 'rolled_back', false, UnsupportedCoordinationException::class);
    }

    /** @dataProvider cascadingForeignKeys */
    public function testNativeCascadeEffectsShareTheOwnedTransaction(string $action, string $rule, bool $commit): void
    {
        $hiddenName = 'nomad_cascade_' . bin2hex(random_bytes(6));
        $this->createTable($hiddenName, '(id BIGINT PRIMARY KEY, parentId BIGINT NULL, FOREIGN KEY (parentId) REFERENCES `' .
            $this->effects->getName() . '` (id) ON ' . $action . ' ' . $rule . ')');
        $this->observer->query('INSERT INTO `' . $this->effects->getName() . '` VALUES (1, 12)');
        $this->observer->query('INSERT INTO `' . $hiddenName . '` VALUES (11, 1)');
        $calls = 0;
        $original = new RuntimeException('Roll back the complete native effect.');
        $caught = null;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use (&$calls, $action, $commit, $original): void {
                $calls++;
                $sql = $action === 'DELETE' ? 'DELETE FROM ?n WHERE id = 1' : 'UPDATE ?n SET id = 2 WHERE id = 1';
                $backend->query($backend->parse($sql, $this->effects->getName()));
                self::assertSame([['id' => '1', 'score' => '12']], $this->visibleEffects());
                if (!$commit) {
                    throw $original;
                }
            });
        } catch (RuntimeException $failure) {
            $caught = $failure;
        }
        self::assertSame($commit ? null : $original, $caught);
        self::assertSame(1, $calls);
        $expectedParent = !$commit ? [['id' => '1', 'score' => '12']] : ($action === 'DELETE' ? [] : [['id' => '2', 'score' => '12']]);
        self::assertSame($expectedParent, $this->visibleEffects());
        $hidden = $this->observer->query('SELECT * FROM `' . $hiddenName . '`');
        self::assertNotFalse($hidden);
        $expectedChild = !$commit ? [['id' => '11', 'parentId' => '1']]
            : ($rule === 'SET NULL' ? [['id' => '11', 'parentId' => null]]
                : ($action === 'DELETE' ? [] : [['id' => '11', 'parentId' => '2']]));
        self::assertSame($expectedChild, $hidden->fetch_all(MYSQLI_ASSOC));
        self::assertFalse($this->inTransaction($this->primary));
        if ($commit) {
            self::assertSame([], $this->logger->entries);
        } else {
            $this->assertFailureLog('callback', 'rolled_back', false, RuntimeException::class);
        }
    }

    /** The logger failure remains visible without replacing the exact callback failure. */
    public function testLoggerFailureSurfacesWithoutReplacingTheConfirmedRollbackFailure(): void
    {
        $this->logger->throwOnWrite = true;
        $reporting = new RuntimeException('Exact logger transport failure.');
        $this->logger->transportFailure = $reporting;
        $original = new RuntimeException('private-callback-value');
        $calls = 0;
        $caught = null;
        try {
            $this->coordinate(function (DatabaseStrategy $backend) use ($original, &$calls): void {
                $calls++;
                $backend->query($backend->parse('INSERT INTO ?n VALUES (1, 12)', $this->effects->getName()));
                throw $original;
            });
        } catch (CoordinatedOperationReportingFailedException $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(CoordinatedOperationReportingFailedException::class, $caught);
        self::assertSame($original, $caught->getOperationFailure());
        self::assertSame($reporting, $caught->getReportingFailure());
        self::assertSame($reporting, $caught->getPrevious());
        self::assertSame(1, $calls);
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        self::assertSame(1, $this->logger->writeAttempts);
        $this->assertFailureLog('callback', 'rolled_back', false, RuntimeException::class);
    }

    /**
     * @dataProvider participantEligibilityHazards
     * @param 0|1|2 $position
     * @param 'engine'|'trigger'|'view'|'temporary' $hazard
     */
    public function testEveryParticipantMustPassEveryStorageEligibilityGuard(int $position, string $hazard): void
    {
        $extraName = 'nomad_extra_' . bin2hex(random_bytes(6));
        $this->createTable($extraName, '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)');
        $participants = [$this->parents, $this->effects, new CoordinationTable($extraName, ['id'])];
        $target = $participants[$position];
        $targetName = $target->getName();
        $hiddenName = null;
        if ($hazard === 'engine') {
            $this->observer->query('ALTER TABLE `' . $targetName . '` ENGINE=MyISAM');
        } elseif ($hazard === 'trigger') {
            $hiddenName = 'nomad_hidden_' . bin2hex(random_bytes(6));
            $this->observer->query('CREATE TABLE `' . $hiddenName . '` (id BIGINT PRIMARY KEY, score BIGINT) ENGINE=MyISAM');
            $this->ownedTables[] = $hiddenName;
            $this->observer->query('CREATE TRIGGER `' . $hiddenName . '_trigger` AFTER INSERT ON `' . $targetName .
                '` FOR EACH ROW INSERT INTO `' . $hiddenName . '` VALUES (NEW.id, 99)');
        } elseif ($hazard === 'view') {
            $viewName = 'nomad_view_' . bin2hex(random_bytes(6));
            $this->observer->query('CREATE VIEW `' . $viewName . '` AS SELECT * FROM `' . $targetName . '`');
            $this->ownedViews[] = $viewName;
            $participants[$position] = new CoordinationTable($viewName, $position === 0 ? ['tenantId', 'id'] : ['id']);
            $targetName = $viewName;
        } else {
            $columns = $position === 0
                ? '(tenantId BIGINT NOT NULL, id BIGINT NOT NULL, PRIMARY KEY (tenantId, id))'
                : '(id BIGINT PRIMARY KEY, score BIGINT NOT NULL)';
            $this->primary->query('CREATE TEMPORARY TABLE `' . $targetName . '` ' . $columns . ' ENGINE=InnoDB');
            if ($position === 0) {
                $this->primary->query('INSERT INTO `' . $targetName . '` VALUES (1, 7)');
            }
        }
        $calls = 0;
        try {
            $this->strategy->coordinate($participants[0], ['tenantId' => 1, 'id' => 7], $participants,
                function (DatabaseStrategy $backend) use (&$calls, $targetName): void {
                    $calls++;
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (3, 8)', $targetName));
                });
            self::fail('Every declared participant must pass storage eligibility before any callback.');
        } catch (UnsupportedCoordinationException $failure) {
            self::assertSame(0, $calls);
        }

        $reader = $hazard === 'temporary' ? $this->primary : $this->observer;
        $rows = $reader->query('SELECT * FROM `' . $targetName . '` ORDER BY ' . ($position === 0 ? 'tenantId, id' : 'id'));
        self::assertNotFalse($rows);
        $expected = $position !== 0 ? [] : ($hazard === 'temporary'
            ? [['tenantId' => '1', 'id' => '7']]
            : [['tenantId' => '1', 'id' => '7'], ['tenantId' => '2', 'id' => '7']]);
        self::assertSame($expected, array_map(
            static fn (array $row): array => array_map('strval', $row),
            $rows->fetch_all(MYSQLI_ASSOC)
        ));
        if ($hiddenName !== null) {
            $hiddenRows = $this->observer->query('SELECT * FROM `' . $hiddenName . '`');
            self::assertNotFalse($hiddenRows);
            self::assertSame([], $hiddenRows->fetch_all());
        }
        self::assertSame([], $this->visibleEffects());
        self::assertFalse($this->inTransaction($this->primary));
        $validationRefusal = $position === 0 && $hazard === 'view';
        $this->assertFailureLog($validationRefusal ? 'validation' : 'coordination', $validationRefusal ? 'unchanged' : 'rolled_back', false, UnsupportedCoordinationException::class,
            null, null, array_map(static fn (CoordinationTable $table): string => $table->getName(), $participants));
    }

    /** @return array<string, array{0|1|2, 'engine'|'trigger'|'view'|'temporary'}> */
    public static function participantEligibilityHazards(): array
    {
        $cases = [];
        foreach ([0, 1, 2] as $position) {
            foreach (['engine', 'trigger', 'view', 'temporary'] as $hazard) {
                $cases[$position . ' ' . $hazard] = [$position, $hazard];
            }
        }
        return $cases;
    }

    /** @return array<string, array{string, string, bool}> */
    public static function cascadingForeignKeys(): array
    {
        $cases = [];
        foreach (['DELETE', 'UPDATE'] as $action) {
            foreach (['CASCADE', 'SET NULL'] as $rule) {
                foreach ([true, false] as $commit) {
                    $cases[$action . ' ' . $rule . ($commit ? ' commit' : ' rollback')] = [$action, $rule, $commit];
                }
            }
        }
        return $cases;
    }

    /** @return array<string, array{string}> */
    public static function unsupportedIsolationLevels(): array
    {
        return ['read uncommitted' => ['READ UNCOMMITTED'], 'serializable' => ['SERIALIZABLE']];
    }

    /** @return array<string, array{string}> */
    public static function callbackFailures(): array
    {
        return ['exception' => ['exception'], 'error' => ['error']];
    }

    /**
     * PDO's counterpart proves that ERRMODE_WARNING cannot authorize
     * coordination. mysqli has no per-connection error-reporting attribute
     * in this codebase at all: mysqli_report() is a process-wide setting
     * this integration never touches, and validateSession() has nothing
     * analogous to refuse. There is no weaker mysqli mode that could
     * masquerade as the exception-reporting one PDO ships by default.
     */
    public function testWarningModeIsRefusedWithoutReplacingTheHostConfiguration(): void
    {
        $this->markTestSkipped(
            'mysqli has no per-connection error-reporting attribute analogous to PDO::ATTR_ERRMODE; '
            . 'this codebase never varies mysqli_report(), so there is no weaker mode to refuse.'
        );
    }

    /** @return array<string, array{int|string}> */
    public static function validIdentityValues(): array
    {
        return [
            'integer zero' => [0], 'negative integer' => [-1], 'string zero' => ['0'],
            'empty string' => [''], 'quoted binary text' => ["lead'\\\0x"], 'unicode' => ['référence'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidParticipantShapes(): array
    {
        return array_combine(
            ['empty', 'named keys', 'sparse list', 'invalid first', 'invalid middle', 'invalid last', 'empty table name'],
            array_map(static fn (string $kind): array => [$kind],
                ['empty', 'named keys', 'sparse list', 'invalid first', 'invalid middle', 'invalid last', 'empty table name'])
        );
    }

    /** @return array<string, array{bool}> */
    public static function ambientOperations(): array
    {
        return ['empty transaction' => [false], 'existing writes' => [true]];
    }

    /** @return array<string, array{array<array-key, mixed>}> */
    public static function invalidIdentities(): array
    {
        return [
            'empty' => [[]], 'missing tenant' => [['id' => 7]],
            'missing id' => [['tenantId' => 1]], 'extra field' => [['tenantId' => 1, 'id' => 7, 'other' => 9]],
            'null value' => [['tenantId' => null, 'id' => 7]], 'boolean value' => [['tenantId' => true, 'id' => 7]],
            'float value' => [['tenantId' => 1.5, 'id' => 7]], 'array value' => [['tenantId' => [1], 'id' => 7]],
        ];
    }
}

final class ChangedPrimaryMetadataStrategy extends SafeMySqlCoordinatedDatabaseStrategy
{
    private int $coordinationReads = 0;

    public function __construct(SafeMySQL $db, LoggerStrategy $logger, private string $coordinationTable)
    {
        parent::__construct($db, $logger);
    }

    /** @return list<string> */
    protected function readPrimaryFields(mysqli $mysqli, string $schema, string $table): array
    {
        $fields = parent::readPrimaryFields($mysqli, $schema, $table);
        if ($table === $this->coordinationTable && ++$this->coordinationReads === 2) {
            return array_reverse($fields);
        }

        return $fields;
    }
}
