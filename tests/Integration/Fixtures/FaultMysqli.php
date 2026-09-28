<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_result;

/** A real mysqli client with one programmable statement boundary. */
final class FaultMysqli extends mysqli
{
    /** @var array{match: string, code: int, sqlState: string, after: bool}|null */
    private ?array $fault = null;

    public function failNext(string $match, int $code, string $sqlState = 'HY000', bool $after = false): void
    {
        $this->fault = compact('match', 'code', 'sqlState', 'after');
    }

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        $fault = $this->fault;
        if ($fault !== null && str_starts_with(strtoupper(ltrim($query)), strtoupper($fault['match']))) {
            $this->fault = null;
            if (!$fault['after']) {
                throw new FaultMysqliException('Injected mysqli failure.', $fault['code'], $fault['sqlState']);
            }
            $result = parent::query($query, $result_mode);
            throw new FaultMysqliException('Injected mysqli failure after statement.', $fault['code'], $fault['sqlState']);
        }
        return parent::query($query, $result_mode);
    }
}
