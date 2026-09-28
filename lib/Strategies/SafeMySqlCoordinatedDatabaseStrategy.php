<?php

namespace PHPNomad\SafeMySql\Integration\Strategies;

use InvalidArgumentException;
use mysqli;
use mysqli_result;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\MySql\Integration\Interfaces\CoordinatedDatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException;
use SafeMySQL;
use Throwable;

/**
 * Optional coordination on the mysqli connection owned by SafeMySQL.
 *
 * The capability has the same deployment assumptions as the PDO adapter:
 * grants must remain stable after this connection opens, and participant
 * descriptors name tables only. Coordination requires MySQL 8 metadata.
 */
class SafeMySqlCoordinatedDatabaseStrategy extends SafeMySqlDatabaseStrategy implements CoordinatedDatabaseStrategy
{
    private ?mysqli $ownedAttemptMysqli = null;
    private ?Throwable $inactiveAbortEvidence = null;

    public function __construct(SafeMySQL $db, private LoggerStrategy $logger)
    {
        parent::__construct($db);
    }

    /** @inheritDoc */
    public function coordinate(Table $coordinationTable, array $identity, array $participants, callable $operation)
    {
        $tableNames = [];

        try {
            $definitions = $this->validateRequest($coordinationTable, $identity, $participants, $tableNames);
            $mysqli = $this->mysqli();
            $schema = $this->validateSession($mysqli);
            $this->validateTriggerVisibility($mysqli, $schema, $tableNames);
            $coordinationIdentity = $this->validateCompleteIdentity(
                $this->readPrimaryFields($mysqli, $schema, $definitions[0]['name']),
                $identity
            );
        } catch (Throwable $failure) {
            $this->reportFailure('validation', $tableNames, 'unchanged', false, $failure);
            throw $failure;
        }

        try {
            $this->nativeQuery($mysqli, 'START TRANSACTION');
        } catch (Throwable $failure) {
            $this->reportFailure('coordination', $tableNames, 'unchanged', false, $failure);
            throw $failure;
        }

        $this->openOwnedAttempt($mysqli);
        $backend = new SafeMySqlOperationDatabaseStrategy($this);

        try {
            try {
                $this->guardCoordinationRecord($mysqli, $definitions[0]['name'], $coordinationIdentity, $identity);
                $this->validateParticipants($mysqli, $schema, $definitions, $coordinationIdentity);
            } catch (Throwable $failure) {
                $this->abortAttempt($mysqli, 'coordination', $tableNames, $failure);
            }

            try {
                $result = $operation($backend);
            } catch (Throwable $failure) {
                $this->abortAttempt($mysqli, 'callback', $tableNames, $failure);
            }

            try {
                $active = $this->transactionIsActive($mysqli);
            } catch (Throwable $probe) {
                $this->throwUnprovedCleanup($mysqli, $tableNames, 'commit', new DatastoreErrorException(
                    'The coordinated operation could not confirm transaction ownership before commit.', 0, $probe
                ), $probe);
            }

            if (!$active) {
                $cause = new DatastoreErrorException('The coordinated operation lost transaction ownership.');
                $failure = new CoordinatedOperationOutcomeUnknownException(
                    'The coordinated database operation outcome is unknown.', 0, $cause
                );
                $this->reportFailure('commit', $tableNames, 'unknown', false, $failure, $cause);
                throw $failure;
            }

            try {
                $this->nativeQuery($mysqli, 'COMMIT');
            } catch (Throwable $failure) {
                $this->handleCommitFailure($mysqli, $tableNames, $failure);
            }

            return $result;
        } finally {
            $backend->close();
            $this->closeOwnedAttempt();
        }
    }

    /** @inheritDoc */
    public function query(string $query)
    {
        $mysqli = $this->mysqli();
        $enteredOwned = $this->callerOwnershipCheck(fn () => $this->enterOwnedStatement($mysqli));

        try {
            $result = $this->nativeQuery($mysqli, $query);
        } catch (Throwable $failure) {
            // Recorded and thrown as the exact same object: a caller that
            // lets this propagate unchanged is later recognized by identity
            // as the failure whose inactive rollback was already proven.
            $wrapped = new DatastoreErrorException('Failed to execute query: ' . $failure->getMessage(), 500, $failure);
            $this->observeInactiveDriverFailure($mysqli, $wrapped);
            throw $wrapped;
        }

        // Captured before the ownership probe below issues its own SELECT,
        // which would otherwise overwrite mysqli's affected-row count.
        $affectedRows = $mysqli->affected_rows;

        $this->callerOwnershipCheck(fn () => $this->requireRetainedOwnership($mysqli, $enteredOwned));

        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }

        return $affectedRows;
    }

    /** @param list<string> $tableNames @return non-empty-list<array{table: Table, name: string}> */
    private function validateRequest(Table $coordinationTable, array $identity, array $participants, array &$tableNames): array
    {
        $definitionsByIndex = [];
        foreach ($participants as $index => $participant) {
            if ($participant instanceof Table) {
                $name = $participant->getName();
                $tableNames[] = $name;
                $definitionsByIndex[$index] = ['table' => $participant, 'name' => $name];
            }
        }
        if ($participants === [] || !array_is_list($participants)) {
            throw new InvalidArgumentException('Participants must be a nonempty list of tables.');
        }
        foreach ($participants as $index => $participant) {
            if (!$participant instanceof Table) {
                throw new InvalidArgumentException('Every participant must be a table descriptor.');
            }
            if (!$this->isValidIdentifier($definitionsByIndex[$index]['name'])) {
                throw new InvalidArgumentException('Every participant must have a valid table name.');
            }
        }
        $definitions = array_values($definitionsByIndex);
        $coordinationIndex = null;
        foreach ($definitions as $index => $definition) {
            if ($definition['table'] === $coordinationTable || $definition['name'] === $coordinationTable->getName()) {
                $coordinationIndex = $index;
                break;
            }
        }
        if ($coordinationIndex === null) {
            throw new InvalidArgumentException('The coordination table must be a participant.');
        }
        if (array_keys($identity) === []) {
            throw new InvalidArgumentException('The coordination identity must be nonempty.');
        }
        foreach (array_keys($identity) as $field) {
            if (!is_string($field) || !$this->isValidIdentifier($field)) {
                throw new InvalidArgumentException('Coordination identity fields must be valid names.');
            }
            if (!is_int($identity[$field]) && !is_string($identity[$field])) {
                throw new InvalidArgumentException('Coordination identity values must be integers or strings.');
            }
        }
        $ordered = [$definitions[$coordinationIndex]];
        array_splice($definitions, $coordinationIndex, 1);
        array_push($ordered, ...$definitions);
        return $ordered;
    }

    /** @param list<string> $primaryFields @return non-empty-list<string> */
    private function validateCompleteIdentity(array $primaryFields, array $identity): array
    {
        $identityFields = array_keys($identity);
        if ($primaryFields === []) {
            throw new UnsupportedCoordinationException('The coordination resource must have a primary key.');
        }
        if (count($identityFields) !== count($primaryFields) || array_diff($primaryFields, $identityFields) !== [] || array_diff($identityFields, $primaryFields) !== []) {
            throw new InvalidArgumentException('The coordination identity must contain every primary field exactly once.');
        }
        return $primaryFields;
    }

    private function validateSession(mysqli $mysqli): string
    {
        $this->requireTransactionInstrumentation($mysqli);
        if ($this->transactionIsActive($mysqli)) {
            throw new UnsupportedCoordinationException('Coordination cannot join an ambient transaction.');
        }
        $version = $this->firstValue($this->nativeQuery($mysqli, 'SELECT VERSION()'));
        if (!is_string($version) || version_compare($version, '8.0.0', '<')) {
            throw new UnsupportedCoordinationException('This database version is unsupported for coordination.');
        }
        $state = $this->firstRow($this->nativeQuery($mysqli, 'SELECT @@autocommit AS autocommit, @@SESSION.transaction_isolation AS isolation, DATABASE() AS schemaName, CURRENT_ROLE() AS currentRole'));
        if ($state === null) {
            throw new UnsupportedCoordinationException('The database session state could not be established.');
        }
        if ((string) ($state['autocommit'] ?? '') !== '1') {
            throw new UnsupportedCoordinationException('Disabled autocommit is unsupported for coordination.');
        }
        if (!in_array($state['isolation'] ?? null, ['READ-COMMITTED', 'REPEATABLE-READ'], true)) {
            throw new UnsupportedCoordinationException('The selected transaction isolation is unsupported.');
        }
        if (($state['currentRole'] ?? null) !== 'NONE') {
            throw new UnsupportedCoordinationException('Active database roles are unsupported for coordination.');
        }
        $schema = $state['schemaName'] ?? null;
        if (!is_string($schema) || $schema === '') {
            throw new UnsupportedCoordinationException('Coordination requires a selected database.');
        }
        return $schema;
    }

    /** @param list<string> $tables */
    private function validateTriggerVisibility(mysqli $mysqli, string $schema, array $tables): void
    {
        $result = $this->nativeQuery($mysqli, 'SHOW GRANTS FOR CURRENT_USER()');
        if (!$result instanceof mysqli_result) {
            throw new UnsupportedCoordinationException('The account grants could not be established.');
        }
        $grants = [];
        while (($row = $result->fetch_row()) !== null) {
            $grant = $row[0] ?? null;
            if (!is_string($grant)) {
                throw new UnsupportedCoordinationException('The account grants could not be established.');
            }
            if (str_starts_with(strtoupper($grant), 'REVOKE ')) {
                throw new UnsupportedCoordinationException('Partial privilege revokes are unsupported for coordination.');
            }
            $grants[] = $grant;
        }
        $result->free();
        foreach ($tables as $table) {
            foreach ($grants as $grant) {
                if ($this->grantProvesTriggerVisibility($grant, $schema, $table)) {
                    continue 2;
                }
            }
            throw new UnsupportedCoordinationException('Direct trigger visibility is required for every participant.');
        }
    }

    private function grantProvesTriggerVisibility(string $grant, string $schema, string $table): bool
    {
        if (!preg_match('/^GRANT (.+) ON (.+) TO /i', $grant, $matches)) {
            return false;
        }
        $privileges = array_map('trim', explode(',', strtoupper($matches[1])));
        if (!in_array('TRIGGER', $privileges, true) && !in_array('ALL PRIVILEGES', $privileges, true)) {
            return false;
        }
        $resource = trim($matches[2]);
        if ($resource === '*.*') {
            return true;
        }
        if (preg_match('/^`((?:``|[^`])*)`\\.\\*$/D', $resource, $scope)) {
            return $this->decodeIdentifier($scope[1]) === $schema;
        }
        if (preg_match('/^`((?:``|[^`])*)`\\.`((?:``|[^`])*)`$/D', $resource, $scope)) {
            return $this->decodeIdentifier($scope[1]) === $schema && $this->decodeIdentifier($scope[2]) === $table;
        }
        return false;
    }

    /** @param non-empty-list<string> $fields @param array<string, int|string> $identity */
    private function guardCoordinationRecord(mysqli $mysqli, string $table, array $fields, array $identity): void
    {
        $conditions = array_map(fn (string $field): string => $this->quoteIdentifier($field) . ' = ' . $this->quoteValue($mysqli, $identity[$field]), $fields);
        $result = $this->ownedInternalQuery($mysqli, 'SELECT 1 FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . implode(' AND ', $conditions) . ' FOR UPDATE');
        if (!$result instanceof mysqli_result || $result->fetch_row() === null) {
            throw new RecordNotFoundException('The coordination record does not exist.');
        }
        $result->free();
    }

    /** @param non-empty-list<array{table: Table, name: string}> $definitions @param non-empty-list<string> $coordinationIdentity */
    protected function validateParticipants(mysqli $mysqli, string $schema, array $definitions, array $coordinationIdentity): void
    {
        foreach ($definitions as $index => $definition) {
            $create = $this->readCreateDefinition($mysqli, $definition['name']);
            if ($create['temporary'] || $create['view']) {
                throw new UnsupportedCoordinationException('Temporary tables and views are unsupported participants.');
            }
            $this->ownedInternalQuery($mysqli, 'SELECT 1 FROM ' . $this->quoteIdentifier($definition['name']) . ' WHERE 1 = 0 FOR UPDATE');
            $create = $this->readCreateDefinition($mysqli, $definition['name']);
            if ($create['temporary'] || $create['view']) {
                throw new UnsupportedCoordinationException('Temporary tables and views are unsupported participants.');
            }
            $metadata = $this->firstRow($this->ownedInternalQuery($mysqli, 'SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $this->quoteValue($mysqli, $schema) . ' AND TABLE_NAME = ' . $this->quoteValue($mysqli, $definition['name'])));
            if ($metadata === null || ($metadata['TABLE_TYPE'] ?? null) !== 'BASE TABLE' || strtoupper((string) ($metadata['ENGINE'] ?? '')) !== 'INNODB') {
                throw new UnsupportedCoordinationException('Every participant must be an InnoDB base table.');
            }
            $primaryFields = $this->readPrimaryFields($mysqli, $schema, $definition['name']);
            if ($primaryFields === []) {
                throw new UnsupportedCoordinationException('Every participant must have a primary key.');
            }
            if ($index === 0 && $primaryFields !== $coordinationIdentity) {
                throw new InvalidArgumentException('The coordination identity must match the stable primary key.');
            }
            $trigger = $this->firstValue($this->ownedInternalQuery($mysqli, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ' . $this->quoteValue($mysqli, $schema) . ' AND EVENT_OBJECT_TABLE = ' . $this->quoteValue($mysqli, $definition['name']) . ' LIMIT 1'));
            if ($trigger !== null) {
                throw new UnsupportedCoordinationException('Trigger-bearing tables are unsupported participants.');
            }
        }
    }

    /** @return list<string> */
    protected function readPrimaryFields(mysqli $mysqli, string $schema, string $table): array
    {
        $result = $this->ownedInternalQuery($mysqli, "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = " . $this->quoteValue($mysqli, $schema) . " AND TABLE_NAME = " . $this->quoteValue($mysqli, $table) . " AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX");
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $fields = [];
        while (($row = $result->fetch_row()) !== null) {
            if (is_string($row[0] ?? null)) {
                $fields[] = $row[0];
            }
        }
        $result->free();
        return $fields;
    }

    /** @return array{temporary: bool, view: bool} */
    private function readCreateDefinition(mysqli $mysqli, string $table): array
    {
        $result = $this->ownedInternalQuery($mysqli, 'SHOW CREATE TABLE ' . $this->quoteIdentifier($table));
        $definition = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        if (!is_array($definition)) {
            throw new UnsupportedCoordinationException('A participant definition could not be established.');
        }
        $create = $definition['Create Table'] ?? '';
        return ['temporary' => is_string($create) && preg_match('/^CREATE\\s+TEMPORARY\\s+TABLE\\b/i', $create) === 1, 'view' => array_key_exists('Create View', $definition)];
    }

    /** @param list<string> $tables */
    private function abortAttempt(mysqli $mysqli, string $phase, array $tables, Throwable $failure): never
    {
        try {
            $active = $this->transactionIsActive($mysqli);
        } catch (Throwable $probe) {
            $this->throwUnprovedCleanup($mysqli, $tables, $phase, $failure, $probe);
        }
        if (!$active) {
            if ($this->hasInactiveAbortEvidence($failure) && $this->isDeadlockFailure($failure)) {
                $operationFailure = new CoordinatedOperationConflictException('The coordinated database operation conflicted.', 0, $failure);
                $this->reportFailure($phase, $tables, 'rolled_back', true, $operationFailure, $failure);
                throw $operationFailure;
            }
            $this->throwCleanupFailure($tables, $phase, $failure, new DatastoreErrorException('Transaction ownership was lost before rollback.'));
        }
        try {
            $this->nativeQuery($mysqli, 'ROLLBACK');
        } catch (Throwable $cleanup) {
            $this->throwCleanupFailure($tables, $phase, $failure, $cleanup);
        }
        $retryable = $this->isContentionFailure($failure);
        $operationFailure = $retryable ? new CoordinatedOperationConflictException('The coordinated database operation conflicted.', 0, $failure) : $failure;
        $this->reportFailure($phase, $tables, 'rolled_back', $retryable, $operationFailure, $failure);
        throw $operationFailure;
    }

    /** @param list<string> $tables */
    private function handleCommitFailure(mysqli $mysqli, array $tables, Throwable $failure): never
    {
        try {
            $active = $this->transactionIsActive($mysqli);
        } catch (Throwable) {
            // The COMMIT may or may not have landed. A ROLLBACK is harmless
            // either way and releases any locks a live session still holds.
            $this->bestEffortRollback($mysqli);
            $active = false;
        }
        if (!$active) {
            $operationFailure = new CoordinatedOperationOutcomeUnknownException('The coordinated database operation outcome is unknown.', 0, $failure);
            $this->reportFailure('commit', $tables, 'unknown', false, $operationFailure, $failure);
            throw $operationFailure;
        }
        try {
            $this->nativeQuery($mysqli, 'ROLLBACK');
        } catch (Throwable $cleanup) {
            $this->throwCleanupFailure($tables, 'commit', $failure, $cleanup);
        }
        $operationFailure = new DatastoreErrorException('The coordinated database commit failed.', 0, $failure);
        $this->reportFailure('commit', $tables, 'rolled_back', false, $operationFailure, $failure);
        throw $operationFailure;
    }

    /**
     * The ownership probe itself failed, so whether the transaction is still
     * open is unknown. Release it if the session is alive, then report the
     * cleanup as unconfirmed.
     *
     * @param list<string> $tables
     */
    private function throwUnprovedCleanup(mysqli $mysqli, array $tables, string $phase, Throwable $failure, Throwable $probe): never
    {
        $this->bestEffortRollback($mysqli);
        $this->throwCleanupFailure($tables, $phase, $failure, $probe);
    }

    private function bestEffortRollback(mysqli $mysqli): void
    {
        try {
            $mysqli->query('ROLLBACK');
        } catch (Throwable) {
            // A dead session has already rolled back on the server.
        }
    }

    /** @param list<string> $tables */
    private function throwCleanupFailure(array $tables, string $operationPhase, Throwable $operationFailure, Throwable $cleanupFailure): never
    {
        $failure = new CoordinatedOperationCleanupFailedException($operationFailure, $cleanupFailure);
        $this->reportFailure('rollback', $tables, 'unknown', false, $failure, $cleanupFailure, $this->failureDetails($operationPhase, $operationFailure));
        throw $failure;
    }

    /** @param list<string> $tables @param array{phase: string, causeClass: class-string, sqlState: ?string, driverCode: ?int}|null $priorFailure */
    private function reportFailure(string $phase, array $tables, string $outcome, bool $retryable, Throwable $operationFailure, ?Throwable $logFailure = null, ?array $priorFailure = null): void
    {
        $failure = $logFailure ?? $operationFailure;
        $driver = $this->driverDetails($failure);
        $context = ['phase' => $phase, 'tables' => $tables, 'outcome' => $outcome, 'retryable' => $retryable, 'causeClass' => get_class($failure), 'sqlState' => $driver['sqlState'], 'driverCode' => $driver['driverCode']];
        if ($priorFailure !== null) {
            $context['priorFailure'] = $priorFailure;
        }
        try {
            $this->logger->error('Coordinated database operation failed.', $context);
        } catch (Throwable $reportingFailure) {
            throw new CoordinatedOperationReportingFailedException($operationFailure, $reportingFailure);
        }
    }

    /** @return array{phase: string, causeClass: class-string, sqlState: ?string, driverCode: ?int} */
    private function failureDetails(string $phase, Throwable $failure): array
    {
        return ['phase' => $phase, 'causeClass' => get_class($failure), ...$this->driverDetails($failure)];
    }

    /** @return array{sqlState: ?string, driverCode: ?int} */
    private function driverDetails(Throwable $failure): array
    {
        for ($node = $failure; $node !== null; $node = $node->getPrevious()) {
            if ($node instanceof MysqliDriverException) {
                return ['sqlState' => $node->sqlState, 'driverCode' => $node->getCode() ?: null];
            }
        }
        return ['sqlState' => null, 'driverCode' => null];
    }

    private function isContentionFailure(Throwable $failure): bool
    {
        $driver = $this->driverDetails($failure);
        return ($driver['driverCode'] === 1213 && ($driver['sqlState'] === '40001' || $driver['sqlState'] === null)) || ($driver['driverCode'] === 1205 && ($driver['sqlState'] === 'HY000' || $driver['sqlState'] === null));
    }

    private function isDeadlockFailure(Throwable $failure): bool
    {
        $driver = $this->driverDetails($failure);
        return $driver['driverCode'] === 1213 && ($driver['sqlState'] === '40001' || $driver['sqlState'] === null);
    }

    private function isConnectionLossFailure(Throwable $failure): bool
    {
        return in_array($this->driverDetails($failure)['driverCode'], [2006, 2013], true);
    }

    private function openOwnedAttempt(mysqli $mysqli): void { $this->ownedAttemptMysqli = $mysqli; $this->inactiveAbortEvidence = null; }
    private function closeOwnedAttempt(): void { $this->ownedAttemptMysqli = null; $this->inactiveAbortEvidence = null; }
    private function hasInactiveAbortEvidence(Throwable $failure): bool { return $this->inactiveAbortEvidence === $failure; }

    /**
     * Runs an ownership check for a caller's query(). A failed probe reaches
     * the caller as a datastore error, like any other query failure, rather
     * than as a bare driver error. Internal coordination statements skip this:
     * abortAttempt() classifies their failures itself.
     *
     * @template T
     * @param callable(): T $check
     * @return T
     */
    private function callerOwnershipCheck(callable $check): mixed
    {
        try {
            return $check();
        } catch (MysqliDriverException $probe) {
            throw new DatastoreErrorException('The coordinated operation could not confirm transaction ownership.', 0, $probe);
        }
    }

    private function enterOwnedStatement(mysqli $mysqli): bool
    {
        if ($this->ownedAttemptMysqli !== $mysqli) { return false; }
        if (!$this->transactionIsActive($mysqli)) { throw new DatastoreErrorException('The coordinated operation lost transaction ownership before a database statement.'); }
        return true;
    }

    private function requireRetainedOwnership(mysqli $mysqli, bool $enteredOwned): void
    {
        if ($enteredOwned && !$this->transactionIsActive($mysqli)) { throw new DatastoreErrorException('The coordinated operation lost transaction ownership during a database statement.'); }
    }

    private function observeInactiveDriverFailure(mysqli $mysqli, Throwable $failure): void
    {
        if ($this->ownedAttemptMysqli !== $mysqli || $this->isConnectionLossFailure($failure)) {
            return;
        }
        try {
            if (!$this->transactionIsActive($mysqli) && $this->isDeadlockFailure($failure)) {
                $this->inactiveAbortEvidence = $failure;
            }
        } catch (Throwable) {
            // An inaccessible session cannot prove that rollback occurred.
        }
    }

    private function mysqli(): mysqli
    {
        $reader = \Closure::bind(static function (SafeMySQL $db): mysqli { return $db->conn; }, null, SafeMySQL::class);
        $mysqli = $reader($this->db);
        if (!$mysqli instanceof mysqli) { throw new \LogicException('SafeMySQL did not expose a mysqli connection.'); }
        return $mysqli;
    }

    private function transactionIsActive(mysqli $mysqli): bool
    {
        // mysqli does not expose PDO::inTransaction(). MySQL 8's current
        // transaction instrumentation identifies the session without adding a
        // user-table read or write to an ambient transaction. This calls
        // mysqli directly, not nativeQuery(): a failed probe must not be
        // observed by another probe, or it recurses until memory runs out.
        $sql = 'SELECT STATE FROM performance_schema.events_transactions_current '
            . 'WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())';
        try {
            $result = $mysqli->query($sql);
        } catch (Throwable $failure) {
            throw $this->driverFailure($mysqli, 'The transaction ownership probe failed.', $failure);
        }
        if ($result === false) {
            throw $this->driverFailure($mysqli, 'The transaction ownership probe failed.');
        }
        return $this->firstValue($result) === 'ACTIVE';
    }

    /**
     * transactionIsActive() is the adapter's only proof of transaction
     * ownership. Refuse before anything changes when the account cannot read
     * the instrumentation, or when MySQL is not recording this session's
     * transactions, because the probe would then read "inactive" after every
     * statement.
     */
    private function requireTransactionInstrumentation(mysqli $mysqli): void
    {
        try {
            $row = $this->firstRow($this->nativeQuery(
                $mysqli,
                "SELECT (SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE ENABLED = 'YES' AND NAME IN "
                . "('global_instrumentation', 'thread_instrumentation', 'events_transactions_current')) AS consumers, "
                . "(SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'transaction') AS instrument, "
                . "(SELECT INSTRUMENTED FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID()) AS thread, "
                . "(SELECT COUNT(*) FROM performance_schema.events_transactions_current WHERE 1 = 0) AS readable"
            ));
        } catch (MysqliDriverException $failure) {
            // 1142 and 1143 are table and column access denied.
            if (!in_array($failure->getCode(), [1142, 1143], true)) {
                throw $failure;
            }
            throw new UnsupportedCoordinationException(
                'Coordination needs SELECT on performance_schema.threads, events_transactions_current, '
                . 'setup_consumers and setup_instruments for the connected account.',
                0,
                $failure
            );
        }
        if ((int) ($row['consumers'] ?? 0) !== 3 || ($row['instrument'] ?? null) !== 'YES' || ($row['thread'] ?? null) !== 'YES') {
            throw new UnsupportedCoordinationException(
                'MySQL transaction instrumentation is off for this session. Coordination needs the performance_schema '
                . '"transaction" instrument, the events_transactions_current consumer and thread instrumentation enabled.'
            );
        }
    }

    private function nativeQuery(mysqli $mysqli, string $sql): mysqli_result|bool
    {
        try {
            $result = $mysqli->query($sql);
        } catch (Throwable $failure) {
            $wrapped = $this->driverFailure($mysqli, 'A coordinated database statement failed.', $failure);
            $this->observeInactiveDriverFailure($mysqli, $wrapped);
            throw $wrapped;
        }
        if ($result === false) {
            $wrapped = $this->driverFailure($mysqli, 'A coordinated database statement failed.');
            $this->observeInactiveDriverFailure($mysqli, $wrapped);
            throw $wrapped;
        }
        return $result;
    }

    /**
     * Every internal coordination-phase statement (guarding the coordination
     * record, validating participants, reading their metadata) shares this
     * same ownership gate with the callback-facing query(): it refuses
     * before issuing a statement whose transaction is already observably
     * gone, and detects loss during the statement too. Without this, a
     * failure injected after ownership was already lost -- before this
     * statement even started -- could be mistaken for proof that this
     * statement itself observed a live deadlock and rolled back, which is
     * exactly the false "retry-eligible" classification the interface
     * contract forbids.
     */
    private function ownedInternalQuery(mysqli $mysqli, string $sql): mysqli_result|bool
    {
        $enteredOwned = $this->enterOwnedStatement($mysqli);
        $result = $this->nativeQuery($mysqli, $sql);
        $this->requireRetainedOwnership($mysqli, $enteredOwned);
        return $result;
    }

    private function driverFailure(mysqli $mysqli, string $message, ?Throwable $previous = null): MysqliDriverException
    {
        $injectedState = $previous !== null && property_exists($previous, 'sqlState') && is_string($previous->sqlState)
            ? $previous->sqlState
            : null;
        $detail = $mysqli->error !== '' ? $mysqli->error : ($previous?->getMessage() ?? '');
        return new MysqliDriverException(
            $detail !== '' ? $message . ' ' . $detail : $message,
            $mysqli->errno ?: ($previous?->getCode() ?? 0),
            $mysqli->sqlstate !== '00000' ? $mysqli->sqlstate : $injectedState,
            $previous
        );
    }

    private function firstValue(mysqli_result|bool $result): mixed
    {
        if (!$result instanceof mysqli_result) { return null; }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function firstRow(mysqli_result|bool $result): ?array
    {
        if (!$result instanceof mysqli_result) { return null; }
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }

    private function quoteIdentifier(string $identifier): string { return '`' . str_replace('`', '``', $identifier) . '`'; }
    private function quoteValue(mysqli $mysqli, int|string $value): string { return "'" . $mysqli->real_escape_string((string) $value) . "'"; }
    private function decodeIdentifier(string $identifier): string { return str_replace('``', '`', $identifier); }
    private function isValidIdentifier(string $identifier): bool { return strlen($identifier) <= 64 && preg_match('/^[A-Za-z_][A-Za-z0-9_$]*$/D', $identifier) === 1; }
}
