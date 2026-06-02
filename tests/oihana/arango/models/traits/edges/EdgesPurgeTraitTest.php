<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Purge;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesPurgeTrait}:
 * initializePurge() and the validating `$purge` property hook (only the Purge
 * enum values are accepted; anything else collapses to null).
 */
final class EdgesPurgeTraitTest extends TestCase
{
    public function testInitializePurgeFromArrayKey() :void
    {
        $edges = new MockEdges( 'x' ) ;
        $this->assertSame( $edges , $edges->initializePurge( [ AQL::PURGE => Purge::OUTBOUND ] ) ) ;
        $this->assertSame( Purge::OUTBOUND , $edges->purge ) ;
    }

    public function testInitializePurgeFromString() :void
    {
        $edges = new MockEdges( 'x' ) ;
        $edges->initializePurge( Purge::BOTH ) ;
        $this->assertSame( Purge::BOTH , $edges->purge ) ;
    }

    public function testInitializePurgeWithNullResetsToNull() :void
    {
        $edges = new MockEdges( 'x' ) ;
        $edges->initializePurge( Purge::INBOUND ) ;
        $edges->initializePurge( null ) ;
        $this->assertNull( $edges->purge ) ;
    }

    public function testInitializePurgeFromArrayWithoutKeyIsNull() :void
    {
        $edges = new MockEdges( 'x' ) ;
        $edges->initializePurge( [ 'nope' => 1 ] ) ;
        $this->assertNull( $edges->purge ) ;
    }

    public function testPropertyHookRejectsInvalidValues() :void
    {
        $edges = new MockEdges( 'x' ) ;
        $edges->purge = 'bogus' ;
        $this->assertNull( $edges->purge ) ;
    }
}
