<?php

namespace tests\oihana\arango\db\functions;

use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\functions\arrays\append;
use function oihana\arango\db\functions\arrays\count;
use function oihana\arango\db\functions\arrays\countDistinct;
use function oihana\arango\db\functions\arrays\first;
use function oihana\arango\db\functions\arrays\last;
use function oihana\arango\db\functions\arrays\nth;
use function oihana\arango\db\functions\arrays\pluck;
use function oihana\arango\db\functions\arrays\position;
use function oihana\arango\db\functions\arrays\push;
use function oihana\arango\db\functions\arrays\removeValue;
use function oihana\arango\db\functions\arrays\removeValues;
use function oihana\arango\db\functions\arrays\reverse;
use function oihana\arango\db\functions\arrays\shift;
use function oihana\arango\db\functions\arrays\slice;
use function oihana\arango\db\functions\arrays\sorted;
use function oihana\arango\db\functions\arrays\sortedUnique;
use function oihana\arango\db\functions\arrays\unshift;

use function oihana\arango\db\functions\arrays\length;

class ArrayFunctionsTest extends TestCase
{
    public function testAppend(): void
    {
        $this->assertEquals("APPEND(arr,[1, 2],true)", append('arr', '[1, 2]', true));
        $this->assertEquals("APPEND(arr,[1, 2])", append('arr', '[1, 2]'));
    }

    public function testCount(): void
    {
        $this->assertEquals("COUNT(arr)", count('arr'));
    }

    public function testCountDistinct(): void
    {
        $this->assertEquals("COUNT_DISTINCT(arr)", countDistinct('arr'));
    }

    public function testFirst(): void
    {
        $this->assertEquals("FIRST(arr)", first('arr'));
    }

    public function testLast(): void
    {
        $this->assertEquals("LAST(arr)", last('arr'));
    }

    public function testLength(): void
    {
        $this->assertEquals("LENGTH(arr)", length('arr'));
    }

    public function testNth(): void
    {
        $this->assertEquals("NTH(arr,2)", nth('arr', 2));
    }

    /**
     * @return void
     * @throws ValidationException
     */
    public function testPluck(): void
    {
        $this->assertEquals( 'doc.items[* RETURN CURRENT.price]' , pluck( 'doc.items' , 'price' ) ) ;
        $this->assertEquals( 'CURRENT.offers[* RETURN CURRENT.amount]' , pluck( 'CURRENT.offers' , 'amount' ) ) ;
    }

    public function testPluckRejectsUnsafeField(): void
    {
        $this->expectException( ValidationException::class ) ;
        pluck( 'doc.items' , 'price] || true || [' ) ;
    }

    public function testPosition(): void
    {
        $this->assertEquals("POSITION(arr,4,true)", position('arr', 4, true));
        $this->assertEquals("POSITION(arr,4)", position('arr', 4));
    }

    public function testPush(): void
    {
        $this->assertEquals("PUSH(arr,1,true)", push('arr', 1, true));
        $this->assertEquals("PUSH(arr,1)", push('arr', 1));
    }

    public function testRemoveValue(): void
    {
        $this->assertEquals("REMOVE_VALUES(arr,1,1)", removeValue('arr', 1, 1));
        $this->assertEquals("REMOVE_VALUES(arr,1)", removeValue('arr', 1, 0));
    }

    public function testRemoveValues(): void
    {
        $this->assertEquals("REMOVE_VALUES(arr,[1, 2])", removeValues('arr', '[1, 2]'));
    }

    public function testReverse(): void
    {
        $this->assertEquals("REVERSE(arr)", reverse('arr'));
    }

    public function testShift(): void
    {
        $this->assertEquals("SHIFT(arr)", shift('arr'));
    }

    public function testSlice(): void
    {
        $this->assertEquals("SLICE(arr,1,2)", slice('arr', 1, 2));
        $this->assertEquals("SLICE(arr,1)", slice('arr', 1, null));
    }

    public function testSorted(): void
    {
        $this->assertEquals("SORTED(1,2,3)", sorted('1,2,3'));
    }

    public function testSortedUnique(): void
    {
        $this->assertEquals("SORTED_UNIQUE(8,4,2,10,6,2,8,6,4)", sortedUnique('8,4,2,10,6,2,8,6,4'));
    }

    public function testUnshift(): void
    {
        $this->assertEquals("UNSHIFT(arr,1,true)", unshift('arr', 1, true));
        $this->assertEquals("UNSHIFT(arr,1)", unshift('arr', 1));
    }
}
