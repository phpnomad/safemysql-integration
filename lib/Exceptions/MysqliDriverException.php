<?php

namespace PHPNomad\SafeMySql\Integration\Exceptions;

use RuntimeException;
use Throwable;

/** Preserves mysqli's numeric error and SQL state when SafeMySQL reports an error. */
final class MysqliDriverException extends RuntimeException
{
    public function __construct(string $message, int $code, public readonly ?string $sqlState, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
