<?php

use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlCoordinatedDatabaseStrategy;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\CoordinationTable;
use PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures\RecordingLogger;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function readMessage(): array
{
    $line = fgets(STDIN);
    if ($line === false) {
        throw new RuntimeException('The controlling test closed its input pipe.');
    }
    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($message)) {
        throw new RuntimeException('The controlling test sent an invalid message.');
    }
    foreach (array_keys($message) as $key) {
        if (!is_string($key)) {
            throw new RuntimeException('Protocol fields must have names.');
        }
    }
    return $message;
}

/** @param array<string, mixed> $data */
function emit(string $event, array $data = []): void
{
    fwrite(STDOUT, json_encode(['event' => $event] + $data, JSON_THROW_ON_ERROR) . "\n");
    fflush(STDOUT);
}

/** @param array<string, mixed> $message */
function textField(array $message, string $key): string
{
    $value = $message[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing protocol text field: ' . $key);
    }
    return $value;
}

/** @param array<string, mixed> $message */
function integerField(array $message, string $key): int
{
    $value = $message[$key] ?? null;
    if (!is_int($value)) {
        throw new RuntimeException('Missing protocol integer field: ' . $key);
    }
    return $value;
}

function isTransactionActive(mysqli $mysqli): bool
{
    $result = $mysqli->query(
        'SELECT STATE FROM performance_schema.events_transactions_current '
        . 'WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())'
    );
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $row = $result->fetch_row();
    return ($row[0] ?? null) === 'ACTIVE';
}

/** @return array<string, string> */
function parseDsn(string $dsn): array
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

$callbackCalls = 0;
$logger = new RecordingLogger();
$mysqli = null;
try {
    $config = readMessage();
    $dsn = getenv('TEST_MYSQL_COORDINATION_DSN');
    if (!$dsn) {
        throw new RuntimeException('An explicitly assigned test schema is required.');
    }
    $parts = parseDsn($dsn);
    $mysqli = new mysqli(
        $parts['host'] ?? '127.0.0.1',
        getenv('TEST_MYSQL_USER') ?: 'root',
        getenv('TEST_MYSQL_PASS') ?: 'root',
        $parts['dbname'] ?? '',
        (int) ($parts['port'] ?? 3306)
    );
    $mysqli->set_charset($parts['charset'] ?? 'utf8mb4');
    $id = $mysqli->thread_id;

    if (($config['mode'] ?? 'effect') === 'ddl') {
        emit('READY', ['connectionId' => $id]);
        if ((readMessage()['action'] ?? null) !== 'run') {
            throw new RuntimeException('Expected the DDL run barrier.');
        }
        $table = textField($config, 'table');
        if (!preg_match('/^nomad_coord_[a-f0-9]+_(parents|effects|claims)$/D', $table)) {
            throw new RuntimeException('DDL requires an explicitly owned participant table.');
        }
        emit('ATTEMPT');
        $mysqli->query('ALTER TABLE `' . $table . '` ENGINE=MyISAM');
        emit('DONE');
        exit(0);
    }

    $isolation = textField($config, 'isolation');
    if (!in_array($isolation, ['READ COMMITTED', 'REPEATABLE READ'], true)) {
        throw new RuntimeException('Unsupported test isolation.');
    }
    $mysqli->query('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
    if (isset($config['lockWaitSeconds'])) {
        $seconds = integerField($config, 'lockWaitSeconds');
        if ($seconds < 1 || $seconds > 5) {
            throw new RuntimeException('The test lock wait must be bounded.');
        }
        $mysqli->query('SET SESSION innodb_lock_wait_timeout = ' . $seconds);
    }
    $parents = new CoordinationTable(textField($config, 'parents'), ['tenantId', 'id']);
    $effects = new CoordinationTable(textField($config, 'effects'), ['id']);
    $claims = new CoordinationTable(textField($config, 'claims'), ['id']);
    $strategy = new SafeMySqlCoordinatedDatabaseStrategy(new SafeMySQL(['mysqli' => $mysqli]), $logger);
    emit('READY', ['connectionId' => $id]);
    if ((readMessage()['action'] ?? null) !== 'run') {
        throw new RuntimeException('Expected the run barrier.');
    }

    emit('ATTEMPT');
    $result = $strategy->coordinate(
        $parents,
        ['tenantId' => integerField($config, 'tenantId'), 'id' => integerField($config, 'recordId')],
        [$parents, $effects, $claims],
        function (DatabaseStrategy $backend) use ($config, $effects, $claims, &$callbackCalls): array {
            $callbackCalls++;
            emit('CALLBACK_ENTERED');
            if ($callbackCalls === 1 && ($config['holdBeforeIo'] ?? 0) === 1) {
                emit('BEFORE_IO');
                if ((readMessage()['action'] ?? null) !== 'continue') {
                    throw new RuntimeException('Expected the first-I/O barrier.');
                }
            }
            $effectId = integerField($config, 'effectId');
            $rows = $backend->query($backend->parse('SELECT score FROM ?n WHERE id = ?i', $effects->getName(), $effectId));
            if (!is_array($rows)) {
                throw new RuntimeException('A score read did not return rows.');
            }
            $before = 0;
            if ($rows !== []) {
                $value = $rows[0]['score'] ?? null;
                if (!is_numeric($value)) {
                    throw new RuntimeException('The score fixture has invalid data.');
                }
                $before = (int) $value;
            }
            $claimId = integerField($config, 'claimId');
            $claimed = $claimId === 0 ? [] : $backend->query($backend->parse('SELECT id FROM ?n WHERE id = ?i', $claims->getName(), $claimId));
            if (!is_array($claimed)) {
                throw new RuntimeException('A claim read did not return rows.');
            }
            $applied = $claimed === [];
            $after = $before;
            if ($applied) {
                if ($claimId !== 0) {
                    $backend->query($backend->parse('INSERT INTO ?n VALUES (?i)', $claims->getName(), $claimId));
                }
                $after += integerField($config, 'delta');
                $sql = $rows === [] ? 'INSERT INTO ?n (score, id) VALUES (?i, ?i)' : 'UPDATE ?n SET score = ?i WHERE id = ?i';
                $backend->query($backend->parse($sql, $effects->getName(), $after, $effectId));
            }
            $result = ['before' => $before, 'after' => $after, 'applied' => $applied];
            if ($callbackCalls === 1) {
                emit('HOLDING', ['result' => $result]);
                if ((readMessage()['action'] ?? null) !== 'release') {
                    throw new RuntimeException('Expected the release barrier.');
                }
            }
            if (isset($config['thenEffectId'])) {
                $backend->query($backend->parse('UPDATE ?n SET score = score + ?i WHERE id = ?i',
                    $effects->getName(), integerField($config, 'delta'), integerField($config, 'thenEffectId')));
            }
            return $result;
        }
    );
    emit('DONE', ['result' => $result, 'callbackCalls' => $callbackCalls, 'logs' => $logger->entries]);
} catch (Throwable $failure) {
    $cause = $failure->getPrevious();
    $driverCause = null;
    for ($node = $cause; $node !== null; $node = $node->getPrevious()) {
        if ($node instanceof \PHPNomad\SafeMySql\Integration\Exceptions\MysqliDriverException) {
            $driverCause = ['class' => get_class($node), 'sqlState' => $node->sqlState, 'driverCode' => $node->getCode() ?: null];
            break;
        }
    }
    emit('ERROR', ['class' => get_class($failure), 'message' => $failure->getMessage(),
        'causeClass' => $cause === null ? null : get_class($cause), 'driverCause' => $driverCause,
        'callbackCalls' => $callbackCalls, 'transactionActive' => $mysqli !== null && isTransactionActive($mysqli), 'logs' => $logger->entries]);
    exit(1);
}
