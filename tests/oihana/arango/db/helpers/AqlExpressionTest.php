<?php

namespace tests\oihana\arango\db\helpers;

use oihana\exceptions\UnsupportedOperationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\aqlExpression;

class AqlExpressionTest extends TestCase
{
    /**
     * @return array<string, array{0:string|array|null,1:string|null}>
     */
    public static function provideValidValues(): array
    {
        return
        [
            'raw string'                     => [ 'FOR u IN users RETURN u', 'FOR u IN users RETURN u' ] ,
            'simple associative array'       => [ [ 'name' => 'John', 'age' => 30 ] , "{name:'John',age:30}" ] ,
            'numeric array [key,value]'      => [ [[ 'status' , 'active' ] ], "{status:'active'}" ] ,
            'empty array'                    => [ []   , '{}' ] ,
            'null value'                     => [ null , null ] ,
            'nested arrays'                  => [ ['user' => ['name' => 'John']], "{user:{name:'John'}}" ] ,
            'raw expression in string array' => [ ['LET a = 1 RETURN a'], '{LET a = 1 RETURN a}' ] ,
        ];
    }

    /**
     * @throws UnsupportedOperationException
     */
    #[DataProvider('provideValidValues')]
    public function testAqlExpressionWithValidValues( string|array|null $value, ?string $expected ): void
    {
        $this->assertSame( $expected , aqlExpression( $value ) );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testAqlExpressionWithInvalidValues(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        aqlExpression(['wrong' => tmpfile()]);
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testStringIsReturnedAsIs(): void
    {
        $query = "FOR u IN users RETURN u";
        $this->assertSame($query, aqlExpression($query));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testEmptyArrayReturnsEmptyDocument(): void
    {
        $this->assertSame('{}', aqlExpression([]));
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testNullReturnsNull(): void
    {
        $this->assertNull(aqlExpression(null));
    }
}