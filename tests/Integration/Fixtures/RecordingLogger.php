<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use Exception;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use RuntimeException;
use Throwable;

/** Records the adapter's observable logging output without external transport. */
final class RecordingLogger implements LoggerStrategy
{
    /** @var list<array{level:string, message:string, context:array<array-key, mixed>}> */
    public array $entries = [];
    public bool $throwOnWrite = false;
    public bool $throwBeforeWrite = false;
    public int $writeAttempts = 0;
    public ?Throwable $transportFailure = null;

    public function emergency(string $message, array $context = []): void { $this->record('emergency', $message, $context); }
    public function alert(string $message, array $context = []): void { $this->record('alert', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->record('critical', $message, $context); }
    public function error(string $message, array $context = []): void { $this->record('error', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->record('warning', $message, $context); }
    public function notice(string $message, array $context = []): void { $this->record('notice', $message, $context); }
    public function info(string $message, array $context = []): void { $this->record('info', $message, $context); }
    public function debug(string $message, array $context = []): void { $this->record('debug', $message, $context); }

    /** @param array<array-key, mixed> $context */
    public function logException(Exception $e, string $message = '', array $context = [], string $level = null)
    {
        $this->record($level ?? 'critical', $message, $context + ['exception' => $e]);
    }

    /** @param array<array-key, mixed> $context */
    private function record(string $level, string $message, array $context): void
    {
        $this->writeAttempts++;
        if ($this->throwBeforeWrite) {
            throw $this->transportFailure ??= new RuntimeException('Logger transport failed.');
        }
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
        if ($this->throwOnWrite) {
            throw $this->transportFailure ??= new RuntimeException('Logger transport failed.');
        }
    }
}
