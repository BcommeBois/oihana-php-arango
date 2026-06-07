<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\models\traits\edges\EdgesTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Minimal host composing {@see EdgesTrait}.
 */
class EdgesTraitHost
{
    use EdgesTrait ;
}

/**
 * Unit coverage for {@see EdgesTrait}.
 */
#[CoversTrait(EdgesTrait::class)]
class EdgesTraitTest extends TestCase
{
    public function testEdgesDefaultsToNull() :void
    {
        $this->assertNull( new EdgesTraitHost()->edges ) ;
    }

    public function testInitializeEdgesSetsTheDefinitionsFromInit() :void
    {
        $host = new EdgesTraitHost() ;

        $returned = $host->initializeEdges( [ EdgesTraitHost::EDGES => [ 'additionalType' => [ 'model' => 'placeHasType' ] ] ] ) ;

        $this->assertSame( [ 'additionalType' => [ 'model' => 'placeHasType' ] ] , $host->edges ) ;
        $this->assertSame( $host , $returned ) ;
    }

    public function testInitializeEdgesKeepsTheCurrentValueWhenAbsent() :void
    {
        $host = new EdgesTraitHost() ;
        $host->edges = [ 'kept' => true ] ;

        $host->initializeEdges() ;

        $this->assertSame( [ 'kept' => true ] , $host->edges ) ;
    }

    public function testReleaseEdgesResetsToNull() :void
    {
        $host = new EdgesTraitHost() ;
        $host->edges = [ 'x' => 1 ] ;

        $returned = $host->releaseEdges() ;

        $this->assertNull( $host->edges ) ;
        $this->assertSame( $host , $returned ) ;
    }
}
