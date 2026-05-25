<?php

namespace tests\oihana\arango\db\helpers\fields;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldNumber;

final class AqlFieldNumberTest extends TestCase
{
    public function testFieldNumberDefault(): void
    {
        $result = aqlFieldNumber('count');
        $this->assertEquals('count:TO_NUMBER(doc.count)', $result);
    }

    public function testFieldNumberWithCustomDoc(): void
    {
        $result = aqlFieldNumber('quantity', 'product');
        $this->assertEquals('quantity:TO_NUMBER(product.quantity)', $result);
    }

    public function testFieldNumberWithCustomFieldName(): void
    {
        $result = aqlFieldNumber('id', 'doc', '_key');
        $this->assertEquals('id:TO_NUMBER(doc._key)', $result);
    }

    public function testFieldNumberWithAllParameters(): void
    {
        $result = aqlFieldNumber('userId', 'user', 'id');
        $this->assertEquals('userId:TO_NUMBER(user.id)', $result);
    }
}
