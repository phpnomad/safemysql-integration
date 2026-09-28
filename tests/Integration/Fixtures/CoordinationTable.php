<?php

namespace PHPNomad\SafeMySql\Integration\Tests\Integration\Fixtures;

use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;

final class CoordinationTable implements Table
{
    /** @param non-empty-list<string> $identity */
    public function __construct(private string $name, private array $identity)
    {
    }

    public function getName(): string { return $this->name; }
    public function getAlias(): string { return 't'; }
    public function getTableVersion(): string { return '1.0.0'; }
    public function getCharset(): ?string { return 'utf8mb4'; }
    public function getCollation(): ?string { return 'utf8mb4_unicode_ci'; }
    public function getFieldsForIdentity(): array { return $this->identity; }
    public function getUnprefixedName(): string { return $this->name; }
    public function getSingularUnprefixedName(): string { return $this->name; }
    public function getColumns(): array { return array_map(fn (string $name) => new Column($name, 'BIGINT'), $this->identity); }
    public function getIndices(): array { return [new Index($this->identity, null, 'PRIMARY KEY')]; }
}
