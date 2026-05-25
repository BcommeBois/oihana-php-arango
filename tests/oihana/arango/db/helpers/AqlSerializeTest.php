<?php

namespace tests\oihana\arango\db\helpers;

use JsonSerializable;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\aqlSerialize;

class AqlSerializeTest extends TestCase
{
    public function testStringIsReturnedAsIs(): void
    {
        $this->assertSame("FOR u IN users RETURN u", aqlSerialize("FOR u IN users RETURN u"));
    }

    public function testBooleansAreSerialized(): void
    {
        $this->assertSame("true", aqlSerialize(true));
        $this->assertSame("false", aqlSerialize(false));
    }

    public function testNumbersAreSerialized(): void
    {
        $this->assertSame("123", aqlSerialize(123));
        $this->assertSame("45.67", aqlSerialize(45.67));
    }

    public function testAssociativeArray(): void
    {
        $input = ['name' => 'John', 'age' => 30];
        $expected = "{name:'John',age:30}";
        $this->assertSame($expected, aqlSerialize($input));
    }

    public function testIndexedArray(): void
    {
        $input = [1, 2, 3];
        $expected = "[1,2,3]";
        $this->assertSame($expected, aqlSerialize($input));
    }

    public function testNestedArray(): void
    {
        $input = ['user' => ['id' => 1, 'tags' => ['php','js']]];
        $expected = "{user:{id:1,tags:['php','js']}}";
        $this->assertSame($expected, aqlSerialize($input));
    }

    public function testSimpleObject(): void
    {
        $input = (object)['name' => 'Eka', 'age' => 47];
        $expected = "{name:'Eka',age:47}";
        $this->assertSame($expected, aqlSerialize($input));
    }

    public function testNestedObject(): void
    {
        $input = (object)['user' => (object)['name'=>'Eka','age'=>47]];
        $expected = "{user:{name:'Eka',age:47}}";
        $this->assertSame($expected, aqlSerialize($input));
    }

    public function testJsonSerializableObject(): void
    {
        $obj = new class implements JsonSerializable
        {
            public function jsonSerialize() :mixed
            {
                return ['foo'=>'bar'];
            }
        };
        $expected = "{foo:'bar'}";
        $this->assertSame($expected, aqlSerialize($obj));
    }

    public function testMixedNestedStructure(): void
    {
        $input = [
            'user' => (object)['id'=>1,'roles'=>['admin','editor']],
            'tags' => ['php','js'],
            'count' => 10
        ];
        $expected = "{user:{id:1,roles:['admin','editor']},tags:['php','js'],count:10}";
        $this->assertSame($expected, aqlSerialize($input));
    }
}