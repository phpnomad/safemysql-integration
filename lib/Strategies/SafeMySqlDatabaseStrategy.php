<?php

namespace PHPNomad\SafeMySql\Integration\Strategies;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\MySql\Integration\Interfaces\DatabaseStrategy;
use SafeMySQL;

class SafeMySqlDatabaseStrategy implements DatabaseStrategy
{
    public function __construct(protected SafeMySQL $db)
    {
    }

    /** @inheritDoc */
    public function parse(string $query, ...$args): string
    {
        $processedArgs = [];
        foreach ($args as $arg) {
            if (is_array($arg) && !empty($arg)) {
                if (is_array(reset($arg))) {
                    $processedArgs[] = $this->convertRowTuples($arg);
                } elseif ($this->isAssociativeArray($arg)) {
                    $processedArgs[] = $this->convertRowTuples([$arg]);
                } else {
                    $processedArgs[] = $arg;
                }
            } else {
                $processedArgs[] = $arg;
            }
        }

        $needsArrayWrap = [];

        $argIndex = 0;
        $query = preg_replace_callback('/\?([ansiup])/', function ($match) use (&$argIndex, $args, $processedArgs, &$needsArrayWrap) {
            $placeholder = $match[0];
            $currentArg = $args[$argIndex] ?? null;
            $processedArg = $processedArgs[$argIndex] ?? null;
            $idx = $argIndex;
            $argIndex++;

            if ($placeholder === '?a' && is_string($processedArg) && is_array($currentArg)) {
                return '?p';
            }

            if ($placeholder === '?a' && !is_array($processedArg)) {
                $needsArrayWrap[$idx] = true;
            }

            return $placeholder;
        }, $query);

        foreach ($needsArrayWrap as $idx => $flag) {
            if ($flag && !is_array($processedArgs[$idx])) {
                $processedArgs[$idx] = [$processedArgs[$idx]];
            }
        }

        return $this->db->parse($query, ...$processedArgs);
    }

    /** @inheritDoc */
    public function query(string $query)
    {
        try {
            $result = $this->db->query("?p", $query);

            if ($result instanceof \mysqli_result) {
                return $result->fetch_all(MYSQLI_ASSOC);
            }

            if ($result === true) {
                return $this->db->affectedRows();
            }

            return $result;
        } catch (\Exception $e) {
            throw new DatastoreErrorException('Failed to execute query: ' . $e->getMessage(), 500, $e);
        }
    }

    protected function isAssociativeArray(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    protected function convertRowTuples(array $tuples): string
    {
        $rows = [];
        foreach ($tuples as $tuple) {
            $values = [];
            foreach ($tuple as $value) {
                if (is_null($value)) {
                    $values[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $values[] = $value;
                } else {
                    $values[] = $this->db->parse('?s', $value);
                }
            }
            $rows[] = '(' . implode(', ', $values) . ')';
        }

        return implode(', ', $rows);
    }
}
