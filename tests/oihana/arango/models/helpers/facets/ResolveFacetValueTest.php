<?php

namespace tests\oihana\arango\models\helpers\facets;

use oihana\arango\models\enums\filters\FilterParam;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\helpers\AltChain;

use function oihana\arango\models\helpers\facets\resolveFacetValue;

/**
 * Coverage for {@see resolveFacetValue()} — the `{op, val, alt}` request object
 * read on top of the facet definition's defaults, shared by
 * {@see \oihana\arango\models\traits\aql\facets\HasFacetSimpleConditions} and
 * {@see \oihana\arango\models\traits\aql\facets\HasFacetIn}.
 *
 * @package tests\oihana\arango\models\helpers\facets
 * @author  Marc Alcaraz
 */
final class ResolveFacetValueTest extends TestCase
{
    public function testBareScalarKeepsTheConfiguredDefaults() :void
    {
        $this->assertSame( [ 'eq' , null , 'alice' ] , resolveFacetValue( 'alice' , 'eq' , null ) ) ;
    }

    /**
     * A list is a multi-value bare value, not the request object: it must not be
     * mistaken for a `{op, val, alt}` map.
     */
    public function testListIsAMultiValueNotARequestObject() :void
    {
        $this->assertSame
        (
            [ 'any.in' , null , [ 'alice' , 'bob' ] ] ,
            resolveFacetValue( [ 'alice' , 'bob' ] , 'any.in' , null )
        ) ;
    }

    /**
     * The request wins over the declaration — and its chain comes back **marked**,
     * so the engine binds its parameters instead of writing them into the query.
     */
    public function testRequestObjectOverridesOperatorAndMarksItsAlt() :void
    {
        [ $op , $alt , $value ] = resolveFacetValue
        (
            [
                FilterParam::OP  => 'like'  ,
                FilterParam::ALT => 'lower' ,
                FilterParam::VAL => 'al'    ,
            ] ,
            'eq' ,
            null
        ) ;

        $this->assertSame( 'like' , $op    ) ;
        $this->assertSame( 'al'   , $value ) ;

        $this->assertInstanceOf( AltChain::class , $alt ) ;
        $this->assertFalse( $alt->trusted ) ;
        $this->assertSame( 'lower' , $alt->chain ) ;
    }

    /**
     * A declared chain the request does not override stays **bare**: the consumer's
     * own code may name an expression, and interpolating it is the point.
     */
    public function testADeclaredAltIsLeftBareWhenTheRequestDoesNotOverrideIt() :void
    {
        [ , $alt ] = resolveFacetValue( [ FilterParam::VAL => 'al' ] , 'eq' , 'lower' ) ;

        $this->assertSame( 'lower' , $alt ) ;
    }

    /**
     * Only the keys actually present override: the rest keeps the definition's.
     */
    public function testAbsentKeysFallBackOnTheDefaults() :void
    {
        $this->assertSame
        (
            [ 'eq' , 'trim' , 'alice' ] ,
            resolveFacetValue( [ FilterParam::VAL => 'alice' ] , 'eq' , 'trim' )
        ) ;
    }

    /**
     * No `val` at all: nothing to compare, the caller drops the facet. The signal
     * is a null *return*, never a null value.
     */
    public function testRequestObjectWithoutValueIsAbandoned() :void
    {
        $this->assertNull( resolveFacetValue( [ FilterParam::OP => 'like' ] , 'eq' , null ) ) ;
        $this->assertNull( resolveFacetValue( [ FilterParam::ALT => 'lower' ] , 'eq' , null ) ) ;
    }

    /**
     * An explicit `val: null` is a value, not an absence — the presence test is
     * `array_key_exists()`, not `isset()`.
     */
    public function testExplicitNullValueIsHonoured() :void
    {
        $this->assertSame
        (
            [ 'ne' , null , null ] ,
            resolveFacetValue( [ FilterParam::OP => 'ne' , FilterParam::VAL => null ] , 'eq' , null )
        ) ;
    }

    /**
     * The request object may carry any value shape, including a nested list.
     */
    public function testRequestObjectCarriesAnyValueShape() :void
    {
        $this->assertSame
        (
            [ 'all.in' , null , [ 'a' , 'b' ] ] ,
            resolveFacetValue( [ FilterParam::OP => 'all.in' , FilterParam::VAL => [ 'a' , 'b' ] ] , 'any.in' , null )
        ) ;
    }

    public function testEmptyArrayIsAListAndTravelsUntouched() :void
    {
        $this->assertSame( [ 'eq' , null , [] ] , resolveFacetValue( [] , 'eq' , null ) ) ;
    }
}
