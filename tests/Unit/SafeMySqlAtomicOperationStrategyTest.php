<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Unit;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPNomad\SafeMySql\Integration\Strategies\SafeMySqlAtomicOperationStrategy;
use PHPUnit\Framework\TestCase;
use SafeMySQL;

class SafeMySqlAtomicOperationStrategyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected SafeMySQL|Mockery\MockInterface $db;
    protected SafeMySqlAtomicOperationStrategy $strategy;

    protected function setUp(): void
    {
        $this->db = Mockery::mock(SafeMySQL::class);
        $this->strategy = new SafeMySqlAtomicOperationStrategy($this->db);
    }

    public function test_commits_on_success_and_returns_result(): void
    {
        $this->db->shouldReceive('query')->with('START TRANSACTION')->once();
        $this->db->shouldReceive('query')->with('COMMIT')->once();

        $result = $this->strategy->atomic(function () {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function test_rolls_back_on_exception_and_rethrows(): void
    {
        $this->db->shouldReceive('query')->with('START TRANSACTION')->once();
        $this->db->shouldReceive('query')->with('ROLLBACK')->once();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Something failed');

        $this->strategy->atomic(function () {
            throw new \RuntimeException('Something failed');
        });
    }

    public function test_preserves_exception_type(): void
    {
        $this->db->shouldReceive('query')->with('START TRANSACTION')->once();
        $this->db->shouldReceive('query')->with('ROLLBACK')->once();

        $this->expectException(\InvalidArgumentException::class);

        $this->strategy->atomic(function () {
            throw new \InvalidArgumentException('Bad input');
        });
    }
}
