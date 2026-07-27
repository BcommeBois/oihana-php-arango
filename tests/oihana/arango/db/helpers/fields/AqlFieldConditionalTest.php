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

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafTruthyFromString() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenLeaf( [ 'active' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafEqualityFromPair() : void
    {
        $this->assertSame( "doc.visibility == 'public'" , buildWhenLeaf( [ 'visibility' , 'public' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafExplicitOperatorFromTriple() : void
    {
        $this->assertSame( 'doc.stock > 0' , buildWhenLeaf( [ 'stock' , 'gt' , 0 ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafBooleanAndNullValues() : void
    {
        $this->assertSame( 'doc.owner == true'  , buildWhenLeaf( [ 'owner' , 'eq' , true ] ) ) ;
        $this->assertSame( 'doc.deleted != null' , buildWhenLeaf( [ 'deleted' , 'ne' , null ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafInOperatorWithList() : void
    {
        $this->assertSame( "doc.status IN ['gold','platinum']" , buildWhenLeaf( [ 'status' , 'in' , [ 'gold' , 'platinum' ] ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafAttributeVersusAttribute() : void
    {
        // aqlValue keeps a doc reference raw → compare two attributes.
        $this->assertSame( 'doc.price > doc.minPrice' , buildWhenLeaf( [ 'price' , 'gt' , 'doc.minPrice' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
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

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafAssociativeWithoutValueIsTruthy() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenLeaf( [ FilterParam::KEY => 'active' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
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

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
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

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafUsesCustomDocReference() : void
    {
        $this->assertSame( "edge.role == 'admin'" , buildWhenLeaf( [ 'role' , 'admin' ] , 'edge' ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafEmptyThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenLeaf( [] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafFunctionFormOperatorThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessageIsOrContains( 'infix comparators only' ) ;
        buildWhenLeaf( [ 'name' , 'sw' , 'Jo' ] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testLeafUnsafeAttributeThrows() : void
    {
        $this->expectException( ValidationException::class ) ;
        buildWhenLeaf( [ 'a; REMOVE doc IN c' , 'eq' , 1 ] ) ;
    }

    // ---------------------------------------------------------------- buildWhenCondition (groups)

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionStringShorthand() : void
    {
        $this->assertSame( 'TO_BOOL(doc.active)' , buildWhenCondition( 'active' ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionSingleLeaf() : void
    {
        $this->assertSame( "doc.visibility == 'public'" , buildWhenCondition( [ 'visibility' , 'public' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionAssociativeLeaf() : void
    {
        $this->assertSame
        (
            "doc.status == 'public'" ,
            buildWhenCondition( [ FilterParam::KEY => 'status' , FilterParam::VAL => 'public' ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionImplicitAnd() : void
    {
        $this->assertSame
        (
            "(doc.visibility == 'public' && doc.stock > 0)" ,
            buildWhenCondition( [ [ 'visibility' , 'public' ] , [ 'stock' , 'gt' , 0 ] ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionExplicitAnd() : void
    {
        $this->assertSame
        (
            "(doc.a == 'x' && doc.b == 'y')" ,
            buildWhenCondition( [ 'and' , [ 'a' , 'x' ] , [ 'b' , 'y' ] ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionOr() : void
    {
        $this->assertSame
        (
            "(doc.role == 'admin' || doc.owner == true)" ,
            buildWhenCondition( [ 'or' , [ 'role' , 'admin' ] , [ 'owner' , 'eq' , true ] ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionNot() : void
    {
        $this->assertSame( '!(doc.anonymized == true)' , buildWhenCondition( [ 'not' , [ 'anonymized' , true ] ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionNested() : void
    {
        $this->assertSame
        (
            "((doc.a == 'x' || doc.b == 'y') && doc.active == true)" ,
            buildWhenCondition( [ 'and' , [ 'or' , [ 'a' , 'x' ] , [ 'b' , 'y' ] ] , [ 'active' , true ] ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionNotWrongArityThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessageIsOrContains( "'not' group expects exactly one condition" ) ;
        buildWhenCondition( [ 'not' , [ 'a' , 1 ] , [ 'b' , 2 ] ] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionAndGroupWithoutOperandThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessageIsOrContains( "'and' group expects at least one condition" ) ;
        buildWhenCondition( [ 'and' ] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionOrGroupWithoutOperandThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessageIsOrContains( "'or' group expects at least one condition" ) ;
        buildWhenCondition( [ 'or' ] ) ;
    }

    /**
     * The empty `not` group keeps its own arity message: it is wrong for a
     * reason of its own, not for lacking any operand at all.
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionNotGroupWithoutOperandKeepsItsArityMessage() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessageIsOrContains( "'not' group expects exactly one condition" ) ;
        buildWhenCondition( [ 'not' ] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionEmptyThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenCondition( [] ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionNonArrayNonStringThrows() : void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        buildWhenCondition( 42 ) ;
    }

    // ---------------------------------------------------------------- resolveWhenElse

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseDefaultsToNull() : void
    {
        $this->assertSame( 'null' , resolveWhenElse() ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseLiteralScalar() : void
    {
        $this->assertSame( '0' , resolveWhenElse( 0 ) ) ;
        $this->assertSame( "'unknown'" , resolveWhenElse( 'unknown' ) ) ; // plain string is quoted
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseAttributeReference() : void
    {
        $this->assertSame( 'doc.basePrice' , resolveWhenElse( [ Field::PROPERTY => 'basePrice' ] ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseAttributeUsesCustomDocReference() : void
    {
        $this->assertSame( 'edge.fallback' , resolveWhenElse( [ Field::PROPERTY => 'fallback' ] , 'edge' ) ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testElseUnsafeAttributeThrows() : void
    {
        $this->expectException( ValidationException::class ) ;
        resolveWhenElse( [ Field::PROPERTY => 'a || 1==1' ] ) ;
    }

    // ---------------------------------------------------------------- aqlFieldConditional (assembly)

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionalAssemblesTernary() : void
    {
        $this->assertSame
        (
            "price:doc.visibility == 'public' ? doc.price : null" ,
            aqlFieldConditional( 'price' , 'doc.price' , [ 'visibility' , 'public' ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testConditionalWithElseAttribute() : void
    {
        $this->assertSame
        (
            "price:doc.visibility == 'public' ? doc.price : doc.basePrice" ,
            aqlFieldConditional( 'price' , 'doc.price' , [ 'visibility' , 'public' ] , [ Field::PROPERTY => 'basePrice' ] )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
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
