<?php

namespace tests\oihana\arango\db\helpers\fields;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldDefault;

final class AqlFieldDefaultTest extends TestCase
{
    public function testFieldDefaultBasic(): void
    {
        $result = aqlFieldDefault('name', 'doc');

        $this->assertEquals('name:doc.name', $result);
    }

    public function testFieldDefaultWithCustomDoc(): void
    {
        $result = aqlFieldDefault('email', 'user');

        $this->assertEquals('email:user.email', $result);
    }

    public function testFieldDefaultWithCustomFieldName(): void
    {
        $result = aqlFieldDefault('userId', 'user', 'id');

        $this->assertEquals('userId:user.id', $result);
    }

    public function testFieldDefaultWithUnderscoreFields(): void
    {
        $result = aqlFieldDefault('key', 'doc', '_key');

        $this->assertEquals('key:doc._key', $result);
    }
}
