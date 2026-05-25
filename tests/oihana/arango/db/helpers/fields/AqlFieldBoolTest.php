<?php

namespace tests\oihana\arango\db\helpers\fields;

use PHPUnit\Framework\TestCase;
use function oihana\arango\db\helpers\fields\aqlFieldBool;

final class AqlFieldBoolTest extends TestCase
{
    public function testFieldBoolDefault(): void
    {
        $result = aqlFieldBool('isActive');

        $this->assertEquals('isActive:TO_BOOL(doc.isActive)', $result);
    }

    public function testFieldBoolWithCustomDoc(): void
    {
        $result = aqlFieldBool('isVerified', 'user');
        $this->assertEquals('isVerified:TO_BOOL(user.isVerified)', $result);
    }

    public function testFieldBoolWithCustomFieldName(): void
    {
        $result = aqlFieldBool('hasImage', keyName: 'image');
        $this->assertEquals('hasImage:TO_BOOL(doc.image)', $result);
    }

    public function testFieldBoolWithAllParameters(): void
    {
        $result = aqlFieldBool('isEnabled', 'product', 'enabled');
        $this->assertEquals('isEnabled:TO_BOOL(product.enabled)', $result);
    }
}
