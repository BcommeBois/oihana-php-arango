<?php

namespace tests\oihana\arango\db\helpers;

use function oihana\arango\db\helpers\aqlSafeArray;
use PHPUnit\Framework\TestCase;

class AqlSafeArrayTest extends TestCase
{
    public function testAqlSafeArrayWithDocumentProperty(): void
    {
        $expected = '(IS_ARRAY(doc.offers) ? doc.offers : [])';
        $actual   = aqlSafeArray('doc.offers');

        $this->assertEquals($expected, $actual);
    }

    public function testAqlSafeArrayWithSimpleVariable(): void
    {
        $expected = '(IS_ARRAY(items) ? items : [])';
        $actual   = aqlSafeArray('items');

        $this->assertEquals($expected, $actual);
    }

    public function testAqlSafeArrayWithNestedPath(): void
    {
        $expected = '(IS_ARRAY(doc.attributes.specs) ? doc.attributes.specs : [])';
        $actual   = aqlSafeArray('doc.attributes.specs');

        $this->assertEquals($expected, $actual);
    }
}