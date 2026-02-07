<?php

namespace PHPNomad\SafeMySql\Integration\Strategies;

use PHPNomad\Database\Interfaces\AtomicOperationStrategy;
use SafeMySQL;

class SafeMySqlAtomicOperationStrategy implements AtomicOperationStrategy
{
    public function __construct(protected SafeMySQL $db)
    {
    }

    /** @inheritDoc */
    public function atomic(callable $operation)
    {
        $this->db->query('START TRANSACTION');

        try {
            $result = $operation();
            $this->db->query('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
    }
}
