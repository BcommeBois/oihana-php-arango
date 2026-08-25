<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\enums\FacetParam;
use oihana\arango\controllers\traits\PrepareFacetCountsLimitTrait;
use oihana\arango\enums\Arango;
use oihana\arango\exceptions\RequestValidationException;

use oihana\enums\http\HttpStatusCode;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

class PrepareFacetCountsLimitHost
{
    use PrepareFacetCountsLimitTrait ;

    public function call( ?Request $request , array $args = [] , ?array &$params = null ) :int|false|null
    {
        return $this->prepareFacetCountsLimit( $request , $args , $params ) ;
    }
}

/**
 * Unit coverage for {@see PrepareFacetCountsLimitTrait}: translates
 * `?facetCountsLimit=` into the model-level contract — an integer, `false` for
 * every bucket, or null when the facet declaration decides.
 */
class PrepareFacetCountsLimitTraitTest extends TestCase
{
    private function host() :PrepareFacetCountsLimitHost
    {
        return new PrepareFacetCountsLimitHost() ;
    }

    private function request( array $query ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( 'GET' , '/' )->withQueryParams( $query ) ;
    }

    public function testNullWhenNothingRequested() :void
    {
        // null is not "no limit": it hands the decision back to the declaration.
        $this->assertNull( $this->host()->call( null ) ) ;
        $this->assertNull( $this->host()->call( $this->request( [] ) ) ) ;
    }

    public function testNumericStringBecomesAnInteger() :void
    {
        // The wire carries text; the model contract is an int.
        $this->assertSame( 25 , $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => '25' ] ) ) ) ;
    }

    public function testTheKeywordBecomesFalse() :void
    {
        // "all" never reaches the model: it is translated into the model-level
        // way of saying "explicitly unlimited".
        $this->assertFalse( $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => FacetParam::ALL ] ) ) ) ;
        $this->assertFalse( $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => ' ALL ' ] ) ) , 'The keyword is case- and space-insensitive.' ) ;
    }

    public function testTheRequestOverridesTheBaseOption() :void
    {
        $args = [ Arango::FACET_COUNTS_LIMIT => 10 ] ;

        $this->assertSame( 10 , $this->host()->call( null , $args ) , 'Without a request, the base option stands.' ) ;
        $this->assertSame( 3  , $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => '3' ] ) , $args ) ) ;
        $this->assertFalse( $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => 'all' ] ) , $args ) ) ;
    }

    public function testTheValueIsEchoedInTheParams() :void
    {
        $params = [] ;
        $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => '25' ] ) , [] , $params ) ;

        $this->assertSame( [ Arango::FACET_COUNTS_LIMIT => '25' ] , $params ) ;
    }

    public function testAnEmptyValueIsIgnored() :void
    {
        // `?facetCountsLimit=` with nothing after it is not a refusal — the
        // parameter is simply absent, and the declaration decides.
        $this->assertNull( $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => '' ] ) ) ) ;
    }

    /**
     * What the caller wrote cannot be honoured, so the caller is told — with a
     * 400 rather than the 500 a mis-declaration earns. `0` is refused like the
     * rest: it reads as "no bucket" and would mean "every bucket".
     */
    public function testUnreadableValuesAreRefusedWithABadRequest() :void
    {
        foreach ( [ '0' , '-5' , '2.5' , 'ten' , 'none' , 'true' ] as $value )
        {
            try
            {
                $this->host()->call( $this->request( [ Arango::FACET_COUNTS_LIMIT => $value ] ) ) ;
                $this->fail( 'The value "' . $value . '" must be refused.' ) ;
            }
            catch ( RequestValidationException $exception )
            {
                $this->assertSame( HttpStatusCode::BAD_REQUEST , $exception->getCode() , 'A faulty request is a 400, not a 500.' ) ;
                $this->assertStringContainsString( Arango::FACET_COUNTS_LIMIT , $exception->getMessage() , 'The refusal names the parameter to fix.' ) ;
                $this->assertStringContainsString( FacetParam::ALL , $exception->getMessage() , 'And it names the keyword for every bucket.' ) ;
            }
        }
    }
}
