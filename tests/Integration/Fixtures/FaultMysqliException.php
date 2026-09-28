<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use RuntimeException;

final class FaultMysqliException extends RuntimeException
{
    public function __construct(string $message, int $code, public string $sqlState)
    {
        parent::__construct($message, $code);
    }
}
