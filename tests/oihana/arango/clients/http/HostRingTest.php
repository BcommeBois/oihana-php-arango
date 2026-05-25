<?php

namespace tests\oihana\arango\clients\http ;

use InvalidArgumentException ;

use oihana\arango\clients\http\HostRing ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see HostRing} — round-robin failover ring with ArangoDB legacy
 * scheme normalisation.
 */
#[CoversClass( HostRing::class )]
class HostRingTest extends TestCase
{
    // =========================================================================
    // Construction
    // =========================================================================

    public function testThrowsOnEmptyEndpointList() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;

        new HostRing( [] ) ;
    }

    public function testEndpointsAreNormalisedAtConstruction() :void
    {
        $ring = new HostRing( [ 'tcp://primary:8529' , 'ssl://failover:8529' ] ) ;

        $this->assertSame
        (
            [ 'http://primary:8529' , 'https://failover:8529' ] ,
            $ring->endpoints ,
        ) ;
    }

    public function testSizeReturnsEndpointCount() :void
    {
        $this->assertSame( 1 , ( new HostRing( [ 'http://a:8529' ] ) )->size() ) ;
        $this->assertSame( 3 , ( new HostRing( [ 'http://a:8529' , 'http://b:8529' , 'http://c:8529' ] ) )->size() ) ;
    }

    // =========================================================================
    // Cursor behaviour
    // =========================================================================

    public function testCurrentDoesNotAdvanceCursor() :void
    {
        $ring = new HostRing( [ 'http://a:8529' , 'http://b:8529' ] ) ;

        $this->assertSame( 'http://a:8529' , $ring->current() ) ;
        $this->assertSame( 'http://a:8529' , $ring->current() ) ;
        $this->assertSame( 'http://a:8529' , $ring->current() ) ;
    }

    public function testNextRotatesAroundAtEndOfList() :void
    {
        $ring = new HostRing( [ 'http://a:8529' , 'http://b:8529' , 'http://c:8529' ] ) ;

        $this->assertSame( 'http://a:8529' , $ring->current() ) ;
        $this->assertSame( 'http://b:8529' , $ring->next() ) ;
        $this->assertSame( 'http://c:8529' , $ring->next() ) ;
        $this->assertSame( 'http://a:8529' , $ring->next() ) ; // wraps around
        $this->assertSame( 'http://b:8529' , $ring->next() ) ;
    }

    public function testNextOnSingletonReturnsSameEndpoint() :void
    {
        $ring = new HostRing( [ 'http://only:8529' ] ) ;

        $this->assertSame( 'http://only:8529' , $ring->next() ) ;
        $this->assertSame( 'http://only:8529' , $ring->next() ) ;
    }

    // =========================================================================
    // Scheme normalisation
    // =========================================================================

    public function testNormalizesTcpScheme() :void
    {
        $this->assertSame( 'http://host:8529' , HostRing::normalize( 'tcp://host:8529' ) ) ;
    }

    public function testNormalizesSslAndTlsSchemes() :void
    {
        $this->assertSame( 'https://host:8529' , HostRing::normalize( 'ssl://host:8529' ) ) ;
        $this->assertSame( 'https://host:8529' , HostRing::normalize( 'tls://host:8529' ) ) ;
    }

    public function testKeepsHttpAndHttpsAsIs() :void
    {
        $this->assertSame( 'http://host:8529'  , HostRing::normalize( 'http://host:8529'  ) ) ;
        $this->assertSame( 'https://host:8529' , HostRing::normalize( 'https://host:8529' ) ) ;
    }

    public function testKeepsUnknownSchemesAsIs() :void
    {
        $this->assertSame( 'unix:///tmp/arangod.sock' , HostRing::normalize( 'unix:///tmp/arangod.sock' ) ) ;
    }

    public function testNormalizationIsCaseInsensitiveOnScheme() :void
    {
        $this->assertSame( 'http://host:8529'  , HostRing::normalize( 'TCP://host:8529' ) ) ;
        $this->assertSame( 'https://host:8529' , HostRing::normalize( 'SSL://host:8529' ) ) ;
    }
}
