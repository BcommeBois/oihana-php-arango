<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\traits\PrepareBoundsTrait;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

class PrepareBoundsHost
{
    use PrepareBoundsTrait ;

    public function call( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        return $this->prepareBounds( $request , $args , $params ) ;
    }
}

/**
 * Unit coverage for {@see PrepareBoundsTrait}: parses `?bounds=` CSV into the
 * bound-field list.
 */
class PrepareBoundsTraitTest extends TestCase
{
    private function host() :PrepareBoundsHost
    {
        return new PrepareBoundsHost() ;
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
        $request = $this->request( [ Arango::BOUNDS => 'width, height , ' ] ) ;

        $this->assertSame( [ 'width' , 'height' ] , $this->host()->call( $request , [] , $params ) ) ;
        $this->assertSame( 'width, height , ' , $params[ Arango::BOUNDS ] ) ;
    }

    public function testPredefinedArgsAsBase() :void
    {
        $this->assertSame(
            [ 'width' ] ,
            $this->host()->call( null , [ Arango::BOUNDS => [ 'width' ] ] )
        ) ;
        $this->assertSame(
            [ 'width' , 'height' ] ,
            $this->host()->call( null , [ Arango::BOUNDS => 'width,height' ] )
        ) ;
    }

    public function testRequestOverridesArgs() :void
    {
        $request = $this->request( [ Arango::BOUNDS => 'height' ] ) ;
        $this->assertSame( [ 'height' ] , $this->host()->call( $request , [ Arango::BOUNDS => [ 'width' ] ] ) ) ;
    }

    public function testNonArrayArgsIgnored() :void
    {
        $this->assertNull( $this->host()->call( null , [ Arango::BOUNDS => 123 ] ) ) ;
    }
}
