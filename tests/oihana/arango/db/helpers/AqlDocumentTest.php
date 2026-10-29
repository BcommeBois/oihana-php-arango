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
        // 'i + 1' is not auto-detected as AQL : only RAW_KEYS keeps it raw.
        $input = [
            '_key'   => 'i + 1',
            'name'   => 'test',
            'foobar' => true
        ];
        $this->assertSame
        (
            "{_key:i + 1,name:'test',foobar:true}" ,
            aqlDocument( $input , [ AQL::RAW_KEYS => [ '_key' ] ] )
        );

        $this->assertSame
        (
            "{ _key:i + 1, name:'test', foobar:true }" ,
            aqlDocument( $input , [ AQL::RAW_KEYS => [ '_key' ] , AQL::USE_SPACE => true ] )
        );
    }

    /**
     * RAW_KEYS keeps the VALUE raw, never the key : a key that is not an
     * identifier is still quoted.
     * @throws UnsupportedOperationException
     */
    public function testRawKeysStillEscapeTheKey()
    {
        $this->assertSame
        (
            "{'my key':i + 1}" ,
            aqlDocument( [ 'my key' => 'i + 1' ] , [ AQL::RAW_KEYS => [ 'my key' ] ] )
        );
    }

    /**
     * RAW_KEYS must be a list : a bare string is ignored.
     * @throws UnsupportedOperationException
     */
    public function testRawKeysAsStringIsIgnored()
    {
        $this->assertSame
        (
            "{_key:'i + 1'}" ,
            aqlDocument( [ '_key' => 'i + 1' ] , [ AQL::RAW_KEYS => '_key' ] )
        );
    }

    /**
     * RAW_VALUES lists VALUES (exact strings), not keys.
     * @throws UnsupportedOperationException
     */
    public function testRawValuesMatchValuesNotKeys()
    {
        $input = [ 'a' => 'i + 1' , 'b' => 'i + 1' , 'c' => 'i + 2' ] ;

        $this->assertSame
        (
            "{a:i + 1,b:i + 1,c:'i + 2'}" ,
            aqlDocument( $input , [ AQL::RAW_VALUES => [ 'i + 1' ] ] )
        );

        $this->assertSame
        (
            "{a:'i + 1',b:'i + 1',c:'i + 2'}" ,
            aqlDocument( $input , [ AQL::RAW_VALUES => [ 'a' , 'b' , 'c' ] ] ) ,
            'A key listed in RAW_VALUES must not keep its value raw.'
        );
    }

    /**
     * RAW_VALUES reaches the values of a nested document ; RAW_KEYS does not.
     * @throws UnsupportedOperationException
     */
    public function testRawValuesReachNestedDocuments()
    {
        $this->assertSame
        (
            "{o:{k:i + 1}}" ,
            aqlDocument( [ 'o' => [ 'k' => 'i + 1' ] ] , [ AQL::RAW_VALUES => [ 'i + 1' ] ] )
        );
    }

    /**
     * A parenthesized subquery is not auto-detected : it is quoted, unless its
     * key is declared in RAW_KEYS.
     * @throws UnsupportedOperationException
     */
    public function testSubqueryIsQuotedUnlessDeclaredRaw()
    {
        $input = [ 'items' => '(FOR x IN things RETURN x)' ] ;

        $this->assertSame( "{items:'(FOR x IN things RETURN x)'}" , aqlDocument( $input ) ) ;

        $this->assertSame
        (
            "{items:(FOR x IN things RETURN x)}" ,
            aqlDocument( $input , [ AQL::RAW_KEYS => [ 'items' ] ] )
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