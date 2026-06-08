<?php

namespace tests\oihana\arango\db\helpers;

use InvalidArgumentException ;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\aqlDocument;

class AqlDocumentTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testEmptyAndNull()
    {
        $this->assertSame('{}'  , aqlDocument([]) ) ;
        $this->assertSame('{}'  , aqlDocument(null) ) ;
        $this->assertSame('{ }' , aqlDocument( [] ,  [ AQL::USE_SPACE => true ]) ) ;
        $this->assertSame('{ }' , aqlDocument(null,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testStringInput()
    {
        $this->assertSame("{foo: 'bar'}", aqlDocument("foo: 'bar'"));
        $this->assertSame("{ foo: 'bar' }", aqlDocument("foo: 'bar'",  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testSimpleKeyValueArray()
    {
        $input = ['name' => 'Eka', 'age' => 47];
        $this->assertSame("{name:'Eka',age:47}", aqlDocument($input));
        $this->assertSame("{ name:'Eka', age:47 }", aqlDocument($input, [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testBoolNullNumericValues()
    {
        $input = [
            'active' => true,
            'deleted' => false,
            'nickname' => null,
            'count' => 5
        ];
        $this->assertSame("{active:true,deleted:false,nickname:null,count:5}", aqlDocument($input));
        $this->assertSame("{ active:true, deleted:false, nickname:null, count:5 }", aqlDocument($input,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testArrayValues()
    {
        $input = [
            'tags' => ['php', 'js'],
            'scores' => [10, 20, 30]
        ];
        $this->assertSame("{tags:['php','js'],scores:[10,20,30]}", aqlDocument($input));
        $this->assertSame("{ tags:['php','js'], scores:[10,20,30] }", aqlDocument($input,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNestedAssociativeArray()
    {
        $input = [
            'user' => ['name' => 'Eka', 'age' => 47],
            'active' => true
        ];
        $this->assertSame("{user:{name:'Eka',age:47},active:true}", aqlDocument($input));
        $this->assertSame("{ user:{name:'Eka',age:47}, active:true }", aqlDocument($input,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testObjectInput()
    {
        $obj = (object)['name'=>'Eka','age'=>47];
        $this->assertSame("{name:'Eka',age:47}", aqlDocument($obj));
    }

    /**
     * An object nested as a value inside an indexed array is recursively
     * expanded through `aqlDocument()` itself.
     * @throws UnsupportedOperationException
     */
    public function testObjectValueInListIsRecursed()
    {
        $input = [ (object)['name'=>'Eka','age'=>47] ];
        $this->assertSame("{{name:'Eka',age:47}}", aqlDocument($input));
    }

    /**
     * Empty string parts are cleaned out by compile(), so no orphan
     * separator is emitted between the remaining values.
     * @throws UnsupportedOperationException
     */
    public function testEmptyStringPartsAreCleaned()
    {
        $this->assertSame("{a}", aqlDocument(['', 'a']));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testRawExpressions()
    {
        $input = [
            '_key' => "CONCAT('test',i)",
            'name' => 'test',
            'foobar' => true
        ];
        $this->assertSame
        (
            "{_key:CONCAT('test',i),name:'test',foobar:true}" ,
            aqlDocument( $input , [ AQL::RAW_KEYS => '_key' ] )
        );

        $this->assertSame
        (
            "{ _key:CONCAT('test',i), name:'test', foobar:true }" ,
            aqlDocument( $input , [ AQL::RAW_KEYS => '_key' , AQL::USE_SPACE => true ] )
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testMixedIndexedAndAssociative()
    {
        $input = [
            ['name', 'Eka'],
            'age' => 47,
            'tags' => ['php','js']
        ];
        $expected = "{name:'Eka',age:47,tags:['php','js']}";
        $expectedSpace = "{ name:'Eka', age:47, tags:['php','js'] }";
        $this->assertSame($expected, aqlDocument($input));
        $this->assertSame($expectedSpace, aqlDocument($input,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testStringEscaping()
    {
        $input = ["quote" => "O'Reilly", "backslash" => "back\\slash"];
        $expected = "{quote:'O\\'Reilly',backslash:'back\\slash'}";
        $expectedSpace = "{ quote:'O\\'Reilly', backslash:'back\\slash' }";
        $this->assertSame($expected, aqlDocument($input));
        $this->assertSame($expectedSpace, aqlDocument($input,  [ AQL::USE_SPACE => true ]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testInvalidArrayThrows()
    {
        $this->expectException( InvalidArgumentException::class ) ;
        aqlDocument([['onlyOneValue']]);
    }
}