<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use RuntimeException;
use Throwable;

/** A real independent PHP/mysqli client controlled by explicit test barriers. */
final class SafeMySqlCoordinationClient
{
    /** @var resource */
    private $process;
    /** @var array<int, resource> */
    private array $pipes;
    private string $buffer = '';
    private bool $closed = false;
    public int $connectionId;

    /** @param array<string, int|string> $config */
    public function __construct(array $config)
    {
        $environment = [];
        foreach (['TEST_MYSQL_COORDINATION_DSN', 'TEST_MYSQL_USER', 'TEST_MYSQL_PASS'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        $process = proc_open([PHP_BINARY, __DIR__ . '/safemysql-coordination-client.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the independent database client.');
        }
        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        try {
            $this->send($config);
            $ready = $this->await('READY');
            if (!is_int($ready['connectionId'] ?? null)) {
                throw new RuntimeException('The client did not identify its database connection.');
            }
            $this->connectionId = $ready['connectionId'];
        } catch (Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    /** @param array<string, int|string> $message */
    public function send(array $message): void
    {
        $line = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($this->pipes[0], $line) !== strlen($line)) {
            throw new RuntimeException('Could not deliver the database client barrier.');
        }
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed>|null */
    public function read(bool $allowFailure = false): ?array
    {
        if (!str_contains($this->buffer, "\n")) {
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 10000) === false) {
                throw new RuntimeException('Could not observe the database client.');
            }
            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read the database client output.');
                }
                if ($pipe === $this->pipes[2] && $chunk !== '') {
                    throw new RuntimeException('Database client stderr: ' . $chunk);
                }
                $this->buffer .= $chunk;
            }
        }
        $newline = strpos($this->buffer, "\n");
        if ($newline === false) {
            if (feof($this->pipes[1])) {
                throw new RuntimeException('The database client ended without its expected result.');
            }
            return null;
        }
        $line = substr($this->buffer, 0, $newline);
        $this->buffer = substr($this->buffer, $newline + 1);
        $frame = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($frame) || !is_string($frame['event'] ?? null)) {
            throw new RuntimeException('The database client returned an invalid frame.');
        }
        foreach (array_keys($frame) as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Client frame fields must have names.');
            }
        }
        if ($frame['event'] === 'ERROR' && !$allowFailure) {
            throw new RuntimeException('Database client failed: ' . $line);
        }
        return $frame;
    }

    /** @return array<string, mixed> */
    public function await(string $event): array
    {
        $deadline = microtime(true) + 15;
        do {
            $frame = $this->read();
            if (($frame['event'] ?? null) === $event) {
                return $frame;
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Database client protocol timed out waiting for ' . $event . '.');
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        $result = $this->await('DONE');
        return $this->closeWithResult($result);
    }

    /** @return array<string, mixed> */
    public function finishOutcome(): array
    {
        $deadline = microtime(true) + 15;
        do {
            $frame = $this->read(true);
            if (in_array($frame['event'] ?? null, ['DONE', 'ERROR'], true)) {
                return $this->closeWithResult($frame);
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The database client did not report a terminal outcome.');
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function closeWithResult(array $result): array
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $result['exitCode'] = proc_close($this->process);
        $this->closed = true;
        return $result;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
        $this->closed = true;
    }
}
