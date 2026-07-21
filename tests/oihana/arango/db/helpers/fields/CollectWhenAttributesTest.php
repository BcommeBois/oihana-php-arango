<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\models\enums\filters\FilterParam;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\db\helpers\fields\collectWhenAttributes;

/**
 * Unit coverage for {@see collectWhenAttributes()} — the attribute collector that
 * mirrors the Field::WHEN / Field::WHERE grammar of {@see buildWhenCondition()},
 * used to gate the fields a conditional projection reads (T5).
 */
final class CollectWhenAttributesTest extends TestCase
{
    public function testStringIsATruthinessLeaf() :void
    {
        $this->assertSame( [ 'active' ] , collectWhenAttributes( 'active' ) ) ;
    }

    public function testListLeafReturnsItsLeftAttribute() :void
    {
        $this->assertSame( [ 'price' ] , collectWhenAttributes( [ 'price' , 'eq' , 100 ] ) ) ;
    }

    public function testAssociativeLeafReturnsItsKeyAttribute() :void
    {
        $this->assertSame( [ 'price' ] , collectWhenAttributes( [ FilterParam::KEY => 'price' , FilterParam::OP => 'gt' , FilterParam::VAL => 0 ] ) ) ;
    }

    public function testAndGroupCollectsEveryOperand() :void
    {
        $this->assertSame
        (
            [ 'a' , 'b' ] ,
            collectWhenAttributes( [ 'and' , [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] ) ,
        ) ;
    }

    public function testOrGroupOverStringLeaves() :void
    {
        $this->assertSame( [ 'a' , 'b' ] , collectWhenAttributes( [ 'or' , 'a' , 'b' ] ) ) ;
    }

    public function testNotGroupRecursesIntoItsCondition() :void
    {
        $this->assertSame( [ 'archived' ] , collectWhenAttributes( [ 'not' , [ 'archived' , 'eq' , true ] ] ) ) ;
    }

    public function testImplicitAndGroupOfLeaves() :void
    {
        $this->assertSame
        (
            [ 'a' , 'b' ] ,
            collectWhenAttributes( [ [ 'a' , 'eq' , 1 ] , [ 'b' , 'eq' , 2 ] ] ) ,
        ) ;
    }

    public function testBindReferenceLeftSideIsSkipped() :void
    {
        // A bound value on the left (a runtime bind) references no document field.
        $this->assertSame( [] , collectWhenAttributes( [ aqlBindRef( 'unrestricted' ) ] ) ) ;
        $this->assertSame( [] , collectWhenAttributes( [ FilterParam::KEY => aqlBindRef( 'x' ) , FilterParam::VAL => 1 ] ) ) ;
    }

    public function testEmptyOrNonArrayYieldsNothing() :void
    {
        $this->assertSame( [] , collectWhenAttributes( [] ) ) ;
        $this->assertSame( [] , collectWhenAttributes( null ) ) ;
        $this->assertSame( [] , collectWhenAttributes( 42 ) ) ;
    }
}
