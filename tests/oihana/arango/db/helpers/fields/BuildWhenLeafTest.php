<?php

namespace tests\oihana\arango\db\helpers\fields;

use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\db\helpers\fields\buildWhenLeaf;

/**
 * Covers the {@see aqlBindRef()} grafts in {@see buildWhenLeaf()}: a bind
 * reference is honored on both the value (right) and the attribute (left) side
 * and is always rendered as a `@name` token, never inlined.
 */
final class BuildWhenLeafTest extends TestCase
{
    // --- value side -------------------------------------------------------

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testValueBindReferenceIsRenderedAsToken(): void
    {
        $leaf = [ 'region' , 'in' , aqlBindRef( 'allowedRegions' ) ] ;
        $this->assertSame( 'item.region IN @allowedRegions' , buildWhenLeaf( $leaf , 'item' ) ) ;
    }

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testValueBindReferenceIsNeverInlined(): void
    {
        // The literal form quotes the value; the reference must not.
        $ref = buildWhenLeaf( [ 'status' , 'eq' , aqlBindRef( 'wanted' ) ] , 'item' ) ;
        $this->assertSame( 'item.status == @wanted' , $ref ) ;
        $this->assertStringNotContainsString( "'@wanted'" , $ref ) ;
    }

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testValueBindReferenceInAssociativeForm(): void
    {
        $leaf =
        [
            FilterParam::KEY => 'region' ,
            FilterParam::OP  => 'in' ,
            FilterParam::VAL => aqlBindRef( 'allowedRegions' ) ,
        ] ;
        $this->assertSame( 'item.region IN @allowedRegions' , buildWhenLeaf( $leaf , 'item' ) ) ;
    }

    // --- attribute / truthy side -----------------------------------------

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testTruthyBindReferenceIsBareToken(): void
    {
        // A bound boolean on its own: no doc prefix, no TO_BOOL wrapping.
        $this->assertSame( '@unrestricted' , buildWhenLeaf( [ aqlBindRef( 'unrestricted' ) ] , 'item' ) ) ;
    }

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testAttributeBindReferenceOnLeftSide(): void
    {
        $leaf = [ aqlBindRef( 'flag' ) , 'eq' , true ] ;
        $this->assertSame( '@flag == true' , buildWhenLeaf( $leaf , 'item' ) ) ;
    }

    // --- regression (no bind reference) -----------------------------------

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testLiteralValueIsStillInlined(): void
    {
        $this->assertSame( "item.region == 'eu-west'" , buildWhenLeaf( [ 'region' , 'eu-west' ] , 'item' ) ) ;
    }

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testTruthyAttributeIsStillToBool(): void
    {
        $this->assertSame( 'TO_BOOL(item.active)' , buildWhenLeaf( [ 'active' ] , 'item' ) ) ;
    }

    /**
     * @throws BindException|UnsupportedOperationException|ValidationException
     */
    public function testUnsafeAttributeStillRejected(): void
    {
        // A plain attribute keeps the injection guard; only a bind reference bypasses it.
        $this->expectException( ValidationException::class ) ;
        buildWhenLeaf( [ 'bad attr' , 'eq' , 1 ] , 'item' ) ;
    }
}
