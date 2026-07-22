<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\traits\PrepareMetaOnlyTrait;
use oihana\arango\enums\Arango;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

use PHPUnit\Framework\TestCase;

/**
 * `PrepareMetaOnlyTrait` pulls in `MetaOnlyTrait`, so the host also exposes the
 * `$metaOnly` state property that acts as the base default of `prepareMetaOnly()`.
 */
class PrepareMetaOnlyHost
{
    use PrepareMetaOnlyTrait ;

    public function call( ?Request $request , array $args = [] , ?array &$params = null ) :bool
    {
        return $this->prepareMetaOnly( $request , $args , $params ) ;
    }
}

/**
 * Unit coverage for {@see PrepareMetaOnlyTrait}: parses `?metaOnly=` into the
 * metadata-only boolean flag.
 */
class PrepareMetaOnlyTraitTest extends TestCase
{
    private function host() :PrepareMetaOnlyHost
    {
        return new PrepareMetaOnlyHost() ;
    }

    private function request( array $query ) :Request
    {
        return new ServerRequestFactory()->createServerRequest( 'GET' , '/' )->withQueryParams( $query ) ;
    }

    public function testFalseWhenNothingRequested() :void
    {
        $this->assertFalse( $this->host()->call( null ) ) ;
        $this->assertFalse( $this->host()->call( $this->request( [] ) ) ) ;
    }

    public function testTruthyBooleanForms() :void
    {
        foreach ( [ 'true' , '1' , 'yes' , 'on' ] as $truthy )
        {
            $params  = [] ;
            $request = $this->request( [ Arango::META_ONLY => $truthy ] ) ;
            $this->assertTrue( $this->host()->call( $request , [] , $params ) , "value: $truthy" ) ;
            $this->assertSame( $truthy , $params[ Arango::META_ONLY ] ) ;
        }
    }

    public function testFalsyStringForms() :void
    {
        foreach ( [ 'false' , '0' , 'no' , 'off' ] as $falsy )
        {
            $request = $this->request( [ Arango::META_ONLY => $falsy ] ) ;
            $this->assertFalse( $this->host()->call( $request ) , "value: $falsy" ) ;
        }
    }

    public function testPredefinedArgsAsBase() :void
    {
        $this->assertTrue ( $this->host()->call( null , [ Arango::META_ONLY => true  ] ) ) ;
        $this->assertTrue ( $this->host()->call( null , [ Arango::META_ONLY => '1'   ] ) ) ;
        $this->assertFalse( $this->host()->call( null , [ Arango::META_ONLY => false ] ) ) ;
    }

    public function testRequestOverridesArgs() :void
    {
        $request = $this->request( [ Arango::META_ONLY => 'true' ] ) ;
        $this->assertTrue( $this->host()->call( $request , [ Arango::META_ONLY => false ] ) ) ;
    }

    public function testEmptyRequestValueKeepsArgsBase() :void
    {
        // An empty string in the query does not override the predefined args base.
        $request = $this->request( [ Arango::META_ONLY => '' ] ) ;
        $this->assertTrue( $this->host()->call( $request , [ Arango::META_ONLY => true ] ) ) ;
    }

    // ---- controller default (MetaOnlyTrait::$metaOnly as base) ----------

    public function testControllerDefaultActsAsBase() :void
    {
        // $this->metaOnly is the base when neither the args nor the request carry the flag.
        $host = $this->host() ;
        $this->assertFalse( $host->call( null ) ) ; // property default is false

        $host->metaOnly = true ;
        $this->assertTrue( $host->call( null ) ) ;
        $this->assertTrue( $host->call( $this->request( [] ) ) ) ;
    }

    public function testArgsOverrideTheControllerDefault() :void
    {
        $host = $this->host() ;
        $host->metaOnly = true ;
        $this->assertFalse( $host->call( null , [ Arango::META_ONLY => false ] ) ) ;
    }

    public function testRequestOverridesTheControllerDefault() :void
    {
        $host = $this->host() ;
        $host->metaOnly = true ;
        $this->assertFalse( $host->call( $this->request( [ Arango::META_ONLY => 'false' ] ) ) ) ;
    }
}
