<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlAsc;

class AqlAscTest extends TestCase
{
    public function testAscWithKeyOnly(): void
    {
        $this->assertSame('age ASC', aqlAsc('age'));
    }

    public function testAscWithKeyAndNullPrefix(): void
    {
        $this->assertSame('name ASC', aqlAsc('name', null));
    }

    public function testAscWithKeyAndEmptyPrefix(): void
    {
        $this->assertSame('status ASC', aqlAsc('status', ''));
    }

    public function testAscWithKeyAndValidPrefix(): void
    {
        $this->assertSame('doc.title ASC', aqlAsc('title', 'doc'));
    }

    public function testAscWithComplexPrefix(): void
    {
        $this->assertSame('v.subDoc._id ASC', aqlAsc('_id', 'v.subDoc'));
    }
}