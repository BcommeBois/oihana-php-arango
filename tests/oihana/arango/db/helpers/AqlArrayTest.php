<?php

namespace tests\oihana\arango\db\helpers;

use function oihana\arango\db\helpers\aqlArray;
use PHPUnit\Framework\TestCase;

class AqlArrayTest extends TestCase
{
    public function testAqlArray(): void
    {
        $this->assertEquals('[1,2]'       , aqlArray([1, 2]));
        $this->assertEquals('{"a":1}'     , aqlArray((object)['a' => 1]));
        $this->assertEquals('some_string' , aqlArray('some_string'));
        $this->assertEquals('[]'          , aqlArray(123));
    }
}