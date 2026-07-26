<?php

namespace tests\oihana\arango\cache;

use oihana\arango\cache\InvalidatesOnWriteTrait;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;

use stdClass;

use tests\oihana\arango\cache\mocks\InvalidableSpy;
use tests\oihana\arango\cache\mocks\InvalidatesOnWriteHost;

#[CoversTrait( InvalidatesOnWriteTrait::class )]
#[AllowMockObjectsWithoutExpectations]
final class InvalidatesOnWriteTraitTest extends TestCase
{
    /**
     * Builds a container serving the given id → service map.
     *
     * @param array<string,mixed> $services
     * @param int|null            $lookups  Receives the number of `has()` + `get()` calls.
     */
    private function makeContainer( array $services , ?int &$lookups = null ) : ContainerInterface
    {
        $lookups = 0 ;

        $container = $this->createMock( ContainerInterface::class ) ;

        $container->method( 'has' )->willReturnCallback( function( string $id ) use ( $services , &$lookups )
        {
            $lookups ++ ;
            return array_key_exists( $id , $services ) ;
        } ) ;

        $container->method( 'get' )->willReturnCallback( function( string $id ) use ( $services , &$lookups )
        {
            $lookups ++ ;
            return $services[ $id ] ;
        } ) ;

        return $container ;
    }

    // =========================================================================
    // No declaration
    // =========================================================================

    public function testNoInvalidatesConnectsNoSignalAtAll() : void
    {
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations( [] , $this->makeContainer([]) ) ;

        $this->assertFalse( $host->afterInsert?->connected() ) ;
        $this->assertFalse( $host->afterUpdate?->connected() ) ;
        $this->assertFalse( $host->afterDelete?->connected() ) ;
    }

    public function testAnInvalidatesOfAnUnsupportedTypeIsIgnored() : void
    {
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations( [ Arango::INVALIDATES => 42 ] , $this->makeContainer([]) ) ;

        $this->assertFalse( $host->afterInsert?->connected() ) ;
    }

    // =========================================================================
    // One service, the three write signals
    // =========================================================================

    public function testOneServiceIsInvalidatedOnInsert() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.cache' ] ] ,
            $this->makeContainer([ 'service.cache' => $spy ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $spy->calls ) ;
    }

    public function testOneServiceIsInvalidatedOnUpdateAndDelete() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.cache' ] ] ,
            $this->makeContainer([ 'service.cache' => $spy ])
        ) ;

        $host->afterUpdate?->emit() ;
        $this->assertSame( 1 , $spy->calls , 'Expected the update signal to invalidate.' ) ;

        $host->afterDelete?->emit() ;
        $this->assertSame( 2 , $spy->calls , 'Expected the delete signal to invalidate.' ) ;
    }

    // =========================================================================
    // Several services
    // =========================================================================

    public function testEveryDeclaredServiceIsInvalidated() : void
    {
        $first  = new InvalidableSpy() ;
        $second = new InvalidableSpy() ;

        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.first' , 'service.second' ] ] ,
            $this->makeContainer([ 'service.first' => $first , 'service.second' => $second ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $first->calls ) ;
        $this->assertSame( 1 , $second->calls ) ;
    }

    // =========================================================================
    // Tolerated declarations
    // =========================================================================

    public function testAServiceMissingFromTheContainerIsIgnored() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.unknown' , 'service.cache' ] ] ,
            $this->makeContainer([ 'service.cache' => $spy ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $spy->calls , 'Expected the unknown id to be skipped without breaking the rest of the list.' ) ;
    }

    public function testANonStringServiceIdIsIgnored() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 42 , 'service.cache' ] ] ,
            $this->makeContainer([ 'service.cache' => $spy ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $spy->calls ) ;
    }

    public function testAServiceNotImplementingInvalidableIsIgnored() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.plain' , 'service.cache' ] ] ,
            $this->makeContainer([ 'service.plain' => new stdClass() , 'service.cache' => $spy ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $spy->calls , 'Expected the non-Invalidable service to be skipped without breaking the rest of the list.' ) ;
    }

    public function testAPlainStringIsAcceptedInsteadOfAnArray() : void
    {
        $spy  = new InvalidableSpy() ;
        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => 'service.cache' ] ,
            $this->makeContainer([ 'service.cache' => $spy ])
        ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 1 , $spy->calls ) ;
    }

    // =========================================================================
    // Lazy resolution
    // =========================================================================

    public function testTheContainerIsNotTouchedUntilAWriteHappens() : void
    {
        $spy       = new InvalidableSpy() ;
        $container = $this->makeContainer( [ 'service.cache' => $spy ] , $lookups ) ;

        $host = new InvalidatesOnWriteHost() ;

        $host->initializeInvalidations( [ Arango::INVALIDATES => [ 'service.cache' ] ] , $container ) ;

        $this->assertSame( 0 , $lookups , 'Expected no container lookup at wiring time — the dependent usually depends on this very model.' ) ;

        $host->afterInsert?->emit() ;

        $this->assertSame( 2 , $lookups , 'Expected the has()/get() couple only once a write actually fired.' ) ;
    }

    // =========================================================================
    // Fluent API
    // =========================================================================

    public function testInitializeInvalidationsIsFluent() : void
    {
        $host = new InvalidatesOnWriteHost() ;

        $this->assertSame( $host , $host->initializeInvalidations( [] , $this->makeContainer([]) ) ) ;
        $this->assertSame( $host , $host->initializeInvalidations( [ Arango::INVALIDATES => 'service.cache' ] , $this->makeContainer([]) ) ) ;
    }

    // =========================================================================
    // Signals never initialized
    // =========================================================================

    public function testWiringIsSafeWhenTheSignalsWereNeverInitialized() : void
    {
        $host = new InvalidatesOnWriteHost( initializeSignals: false ) ;

        $host->initializeInvalidations
        (
            [ Arango::INVALIDATES => [ 'service.cache' ] ] ,
            $this->makeContainer([ 'service.cache' => new InvalidableSpy() ])
        ) ;

        $this->assertNull( $host->afterInsert ) ;
    }
}
