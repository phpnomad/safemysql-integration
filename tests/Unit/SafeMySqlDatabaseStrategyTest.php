<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlDatabaseStrategy;
use PHPUnit\Framework\TestCase;
use SafeMySQL;

class SafeMySqlDatabaseStrategyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected SafeMySQL|Mockery\MockInterface $db;
    protected SafeMySqlDatabaseStrategy $strategy;

    protected function setUp(): void
    {
        $this->db = Mockery::mock(SafeMySQL::class);
        $this->strategy = new SafeMySqlDatabaseStrategy($this->db);
    }

    public function test_parse_delegates_to_safemysql(): void
    {
        $this->db->shouldReceive('parse')
            ->with('SELECT * FROM users WHERE id = ?i', 42)
            ->andReturn('SELECT * FROM users WHERE id = 42');

        $result = $this->strategy->parse('SELECT * FROM users WHERE id = ?i', 42);

        $this->assertSame('SELECT * FROM users WHERE id = 42', $result);
    }

    public function test_parse_handles_nested_arrays_as_row_tuples(): void
    {
        $tuples = [['a' => 1, 'b' => 2], ['a' => 3, 'b' => 4]];

        $this->db->shouldReceive('parse')
            ->with('SELECT * FROM t WHERE (a, b) IN (?p)', Mockery::type('string'))
            ->andReturnUsing(function ($query, $arg) {
                // The converted tuples string should be passed as ?p
                return str_replace('?p', $arg, $query);
            });

        $result = $this->strategy->parse('SELECT * FROM t WHERE (a, b) IN (?a)', $tuples);

        $this->assertStringContainsString('(1, 2)', $result);
        $this->assertStringContainsString('(3, 4)', $result);
    }

    public function test_query_returns_associative_arrays_for_select(): void
    {
        $mockResult = Mockery::mock(\mysqli_result::class);
        $mockResult->shouldReceive('fetch_all')
            ->with(MYSQLI_ASSOC)
            ->andReturn([['id' => 1, 'name' => 'test']]);

        $this->db->shouldReceive('query')
            ->with('SELECT * FROM users')
            ->andReturn($mockResult);

        $result = $this->strategy->query('SELECT * FROM users');

        $this->assertSame([['id' => 1, 'name' => 'test']], $result);
    }

    public function test_query_returns_affected_rows_for_update(): void
    {
        $this->db->shouldReceive('query')
            ->with("UPDATE users SET name = 'foo' WHERE id = 1")
            ->andReturn(true);

        $this->db->shouldReceive('affectedRows')
            ->andReturn(1);

        $result = $this->strategy->query("UPDATE users SET name = 'foo' WHERE id = 1");

        $this->assertSame(1, $result);
    }

    public function test_query_wraps_exceptions_as_datastore_error(): void
    {
        $this->db->shouldReceive('query')
            ->andThrow(new \Exception('Connection lost'));

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Failed to execute query: Connection lost');

        $this->strategy->query('SELECT 1');
    }
}
