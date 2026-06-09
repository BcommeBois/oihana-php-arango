<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\traits\PrepareFacetCountsTrait;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

class PrepareFacetCountsHost
{
    use PrepareFacetCountsTrait ;

    public function call( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        return $this->prepareFacetCounts( $request , $args , $params ) ;
    }
}

/**
 * Unit coverage for {@see PrepareFacetCountsTrait}: parses `?facetCounts=` CSV
 * into the dimension list.
 */
class PrepareFacetCountsTraitTest extends TestCase
{
    private function host() :PrepareFacetCountsHost
    {
        return new PrepareFacetCountsHost() ;
    }

    private function request( array $query ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( 'GET' , '/' )->withQueryParams( $query ) ;
    }

    public function testNullWhenNothingRequested() :void
    {
        $this->assertNull( $this->host()->call( null ) ) ;
        $this->assertNull( $this->host()->call( $this->request( [] ) ) ) ;
    }

    public function testCsvIsParsedAndTrimmed() :void
    {
        $params  = [] ;
        $request = $this->request( [ Arango::FACET_COUNTS => 'category, status , ' ] ) ;

        $this->assertSame( [ 'category' , 'status' ] , $this->host()->call( $request , [] , $params ) ) ;
        $this->assertSame( 'category, status , ' , $params[ Arango::FACET_COUNTS ] ) ;
    }

    public function testPredefinedArgsAsBase() :void
    {
        $this->assertSame(
            [ 'category' ] ,
            $this->host()->call( null , [ Arango::FACET_COUNTS => [ 'category' ] ] )
        ) ;
        $this->assertSame(
            [ 'a' , 'b' ] ,
            $this->host()->call( null , [ Arango::FACET_COUNTS => 'a,b' ] )
        ) ;
    }

    public function testRequestOverridesArgs() :void
    {
        $request = $this->request( [ Arango::FACET_COUNTS => 'status' ] ) ;
        $this->assertSame( [ 'status' ] , $this->host()->call( $request , [ Arango::FACET_COUNTS => [ 'category' ] ] ) ) ;
    }

    public function testNonArrayArgsIgnored() :void
    {
        $this->assertNull( $this->host()->call( null , [ Arango::FACET_COUNTS => 123 ] ) ) ;
    }
}
