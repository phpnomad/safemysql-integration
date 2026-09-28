<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use mysqli;
use mysqli_result;

/**
 * Faults that leave the ownership probe itself unable to answer: an access
 * denial on the probe, or a real session kill as COMMIT is sent. FaultMysqli
 * cannot cover these, because its synthetic errors leave the socket alive and
 * the next probe succeeds.
 */
final class ProbeFaultMysqli extends mysqli
{
    public bool $denyProbe = false;
    public int $deniedProbes = 0;
    public ?mysqli $killOnCommitWith = null;

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if ($this->denyProbe && str_contains($query, 'events_transactions_current WHERE THREAD_ID')) {
            $this->deniedProbes++;
            throw new FaultMysqliException('SELECT command denied to user for table \'events_transactions_current\'', 1142, '42000');
        }
        if ($this->killOnCommitWith !== null && strtoupper(ltrim($query)) === 'COMMIT') {
            $this->killOnCommitWith->query('KILL ' . $this->thread_id);
        }
        return parent::query($query, $result_mode);
    }
}
