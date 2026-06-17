<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\enums\Field;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\fields\aqlFieldConditional;
use function oihana\arango\db\helpers\fields\buildWhenCondition;
use function oihana\arango\db\helpers\fields\buildWhenLeaf;
use function oihana\arango\db\helpers\fields\resolveWhenElse;

final class AqlFieldConditionalTest extends TestCase
{
    // ---------------------------------------------------------------- buildWhenLeaf

    public function testLeafTruthyFromString() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenLeaf( [ 'active' ] ) ) ;
    }

    public function testLeafEqualityFromPair() : void
    {
        $this->assertSame( "doc.visibility == 'public'" , buildWhenLeaf( [ 'visibility' , 'public' ] ) ) ;
    }

    public function testLeafExplicitOperatorFromTriple() : void
    {
        $this->assertSame( 'doc.stock > 0' , buildWhenLeaf( [ 'stock' , 'gt' , 0 ] ) ) ;
    }

    public function testLeafBooleanAndNullValues() : void
    {
        $this->assertSame( 'doc.owner == true'  , buildWhenLeaf( [ 'owner' , 'eq' , true ] ) ) ;
        $this->assertSame( 'doc.deleted != null' , buildWhenLeaf( [ 'deleted' , 'ne' , null ] ) ) ;
    }

    public function testLeafInOperatorWithList() : void
    {
        $this->assertSame( "doc.status IN ['gold','platinum']" , buildWhenLeaf( [ 'status' , 'in' , [ 'gold' , 'platinum' ] ] ) ) ;
    }

    public function testLeafAttributeVersusAttribute() : void
    {
        // aqlValue keeps a doc reference raw → compare two attributes.
        $this->assertSame( 'doc.price > doc.minPrice' , buildWhenLeaf( [ 'price' , 'gt' , 'doc.minPrice' ] ) ) ;
    }

    public function testLeafAssociativeForm() : void
    {
        $leaf =
        [
            FilterParam::KEY => 'status' ,
            FilterParam::OP  => 'eq' ,
            FilterParam::VAL => 'public' ,
        ] ;
        $this->assertSame( "doc.status == 'public'" , buildWhenLeaf( $leaf ) ) ;
    }

    public function testLeafAssociativeWithoutValueIsTruthy() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenLeaf( [ FilterParam::KEY => 'active' ] ) ) ;
    }

    public function testLeafAltWrapsLeftSideOnly() : void
    {
        $leaf =
        [
            FilterParam::KEY => 'status' ,
            FilterParam::VAL => 'public' ,
            FilterParam::ALT => 'lower' ,
        ] ;
        $this->assertSame( "LOWER(doc.status) == 'public'" , buildWhenLeaf( $leaf ) ) ;
    }

    public function testLeafAltMirrorsBothSides() : void
    {
        $leaf =
        [
            FilterParam::KEY => 'status' ,
            FilterParam::VAL => 'PUBLIC' ,
            FilterParam::ALT => [ 'key' => 'lower' , 'val' => true ] ,
        ] ;
        $this->assertSame( "LOWER(doc.status) == LOWER('PUBLIC')" , buildWhenLeaf( $leaf ) ) ;
    }

    public function testLeafUsesCustomDocReference() : void
    {
        $this->assertSame( "edge.role == 'admin'" , buildWhenLeaf( [ 'role' , 'admin' ] , 'edge' ) ) ;
    }

    public function testLeafEmptyThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenLeaf( [] ) ;
    }

    public function testLeafFunctionFormOperatorThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( 'infix comparators only' ) ;
        buildWhenLeaf( [ 'name' , 'sw' , 'Jo' ] ) ;
    }

    public function testLeafUnsafeAttributeThrows() : void
    {
        $this->expectException( ValidationException::class ) ;
        buildWhenLeaf( [ 'a; REMOVE doc IN c' , 'eq' , 1 ] ) ;
    }

    // ---------------------------------------------------------------- buildWhenCondition (groups)

    public function testConditionStringShorthand() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenCondition( 'active' ) ) ;
    }

    public function testConditionSingleLeaf() : void
    {
        $this->assertSame( "doc.visibility == 'public'" , buildWhenCondition( [ 'visibility' , 'public' ] ) ) ;
    }

    public function testConditionAssociativeLeaf() : void
    {
        $this->assertSame
        (
            "doc.status == 'public'" ,
            buildWhenCondition( [ FilterParam::KEY => 'status' , FilterParam::VAL => 'public' ] )
        ) ;
    }

    public function testConditionImplicitAnd() : void
    {
        $this->assertSame
        (
            "(doc.visibility == 'public' && doc.stock > 0)" ,
            buildWhenCondition( [ [ 'visibility' , 'public' ] , [ 'stock' , 'gt' , 0 ] ] )
        ) ;
    }

    public function testConditionExplicitAnd() : void
    {
        $this->assertSame
        (
            "(doc.a == 'x' && doc.b == 'y')" ,
            buildWhenCondition( [ 'and' , [ 'a' , 'x' ] , [ 'b' , 'y' ] ] )
        ) ;
    }

    public function testConditionOr() : void
    {
        $this->assertSame
        (
            "(doc.role == 'admin' || doc.owner == true)" ,
            buildWhenCondition( [ 'or' , [ 'role' , 'admin' ] , [ 'owner' , 'eq' , true ] ] )
        ) ;
    }

    public function testConditionNot() : void
    {
        $this->assertSame( '!(doc.anonymized == true)' , buildWhenCondition( [ 'not' , [ 'anonymized' , true ] ] ) ) ;
    }

    public function testConditionNested() : void
    {
        $this->assertSame
        (
            "((doc.a == 'x' || doc.b == 'y') && doc.active == true)" ,
            buildWhenCondition( [ 'and' , [ 'or' , [ 'a' , 'x' ] , [ 'b' , 'y' ] ] , [ 'active' , true ] ] )
        ) ;
    }

    public function testConditionNotWrongArityThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( "'not' group expects exactly one condition" ) ;
        buildWhenCondition( [ 'not' , [ 'a' , 1 ] , [ 'b' , 2 ] ] ) ;
    }

    public function testConditionEmptyThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenCondition( [] ) ;
    }

    public function testConditionNonArrayNonStringThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenCondition( 42 ) ;
    }

    // ---------------------------------------------------------------- resolveWhenElse

    public function testElseDefaultsToNull() : void
    {
        $this->assertSame( 'null' , resolveWhenElse() ) ;
    }

    public function testElseLiteralScalar() : void
    {
        $this->assertSame( '0' , resolveWhenElse( 0 ) ) ;
        $this->assertSame( "'unknown'" , resolveWhenElse( 'unknown' ) ) ; // plain string is quoted
    }

    public function testElseAttributeReference() : void
    {
        $this->assertSame( 'doc.basePrice' , resolveWhenElse( [ Field::PROPERTY => 'basePrice' ] ) ) ;
    }

    public function testElseAttributeUsesCustomDocReference() : void
    {
        $this->assertSame( 'edge.fallback' , resolveWhenElse( [ Field::PROPERTY => 'fallback' ] , 'edge' ) ) ;
    }

    public function testElseUnsafeAttributeThrows() : void
    {
        $this->expectException( ValidationException::class ) ;
        resolveWhenElse( [ Field::PROPERTY => 'a || 1==1' ] ) ;
    }

    // ---------------------------------------------------------------- aqlFieldConditional (assembly)

    public function testConditionalAssemblesTernary() : void
    {
        $this->assertSame
        (
            "price:doc.visibility == 'public' ? doc.price : null" ,
            aqlFieldConditional( 'price' , 'doc.price' , [ 'visibility' , 'public' ] )
        ) ;
    }

    public function testConditionalWithElseAttribute() : void
    {
        $this->assertSame
        (
            "price:doc.visibility == 'public' ? doc.price : doc.basePrice" ,
            aqlFieldConditional( 'price' , 'doc.price' , [ 'visibility' , 'public' ] , [ Field::PROPERTY => 'basePrice' ] )
        ) ;
    }

    public function testConditionalWithAlteredThenBranch() : void
    {
        // The caller pre-builds the `then` expression (here an alt chain).
        $this->assertSame
        (
            'slug:doc.published == true ? LOWER(TRIM(doc.title)) : null' ,
            aqlFieldConditional( 'slug' , 'LOWER(TRIM(doc.title))' , [ 'published' , 'eq' , true ] )
        ) ;
    }
}
