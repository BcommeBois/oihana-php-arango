<?php

namespace tests\oihana\arango\models\traits\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\edges\EdgesCountTrait}:
 * the `_from`/`_to` filtered countEdges() and the directional vertex-traversal
 * counters (countVertices + the Any/Inbound/Outbound wrappers).
 */
final class EdgesCountTraitTest extends TestCase
{
    /**
     * Replaces the random `edgeCollection_<hex>` bind name with a stable token.
     *
     * @param string $query The query to normalise.
     *
     * @return string The normalised query.
     */
    private function normalize( string $query ) :string
    {
        return preg_replace( '/edgeCollection_[0-9a-f]+/' , 'edgeCollection_X' , $query ) ;
    }
    public function testCountEdgesWithFromAndTo() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 7 ;

        $this->assertSame( 7 , $edges->countEdges( 'users/1' , 'roles/2' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from && doc._to == @to RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }

    public function testCountEdgesFromOnly() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 3 ;

        $this->assertSame( 3 , $edges->countEdges( 'users/1' ) ) ;
        $this->assertSame
        (
            'RETURN LENGTH(FOR doc IN @@collection FILTER doc._from == @from RETURN 1)' ,
            $edges->lastQuery ,
        ) ;
    }

    public function testCountVerticesBuildsDirectionalTraversalCount() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 4 ;

        $this->assertSame( 4 , $edges->countVertices( Traversal::INBOUND , 'roles/2' ) ) ;
        $this->assertSame
        (
            'FOR vertex IN INBOUND @startVertex @@edgeCollection_X COLLECT WITH COUNT INTO length RETURN length' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
        $this->assertSame( 'roles/2' , $edges->lastBinds[ 'startVertex' ] ) ;
    }

    public function testCountInboundVerticesUsesInboundDirection() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 2 ;

        $this->assertSame( 2 , $edges->countInboundVertices( 'roles/2' ) ) ;
        $this->assertStringContainsString( 'IN INBOUND @startVertex' , $edges->lastQuery ) ;
    }

    public function testCountOutboundVerticesUsesOutboundDirection() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 5 ;

        $this->assertSame( 5 , $edges->countOutboundVertices( 'users/1' ) ) ;
        $this->assertStringContainsString( 'IN OUTBOUND @startVertex' , $edges->lastQuery ) ;
    }

    public function testCountAnyVerticesUsesAnyDirection() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 9 ;

        $this->assertSame( 9 , $edges->countAnyVertices( 'users/1' ) ) ;
        $this->assertStringContainsString( 'IN ANY @startVertex' , $edges->lastQuery ) ;
    }

    // ---------- WITH clause (anonymous traversal counts, cluster-safe)

    public function testCountVerticesPrependsWithFromCollectionInbound() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->from = ( new MockDocuments( 'users' ) )->initializeDeleteSignals() ;
        $edges->to   = ( new MockDocuments( 'roles' ) )->initializeDeleteSignals() ;
        $edges->firstResult = 4 ;

        $this->assertSame( 4 , $edges->countVertices( Traversal::INBOUND , 'roles/2' ) ) ;
        $this->assertSame
        (
            'WITH users FOR vertex IN INBOUND @startVertex @@edgeCollection_X COLLECT WITH COUNT INTO length RETURN length' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testCountVerticesPrependsWithToCollectionOutbound() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->from = ( new MockDocuments( 'users' ) )->initializeDeleteSignals() ;
        $edges->to   = ( new MockDocuments( 'roles' ) )->initializeDeleteSignals() ;
        $edges->firstResult = 5 ;

        $edges->countVertices( Traversal::OUTBOUND , 'users/1' ) ;
        $this->assertStringStartsWith
        (
            'WITH roles FOR vertex IN OUTBOUND @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testCountVerticesPrependsWithBothCollectionsAny() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->from = ( new MockDocuments( 'users' ) )->initializeDeleteSignals() ;
        $edges->to   = ( new MockDocuments( 'roles' ) )->initializeDeleteSignals() ;
        $edges->firstResult = 9 ;

        $edges->countVertices( Traversal::ANY , 'users/1' ) ;
        $this->assertStringStartsWith
        (
            'WITH users, roles FOR vertex IN ANY @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testCountVerticesWithNamedGraphOmitsWith() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->from = ( new MockDocuments( 'users' ) )->initializeDeleteSignals() ;
        $edges->to   = ( new MockDocuments( 'roles' ) )->initializeDeleteSignals() ;
        $edges->firstResult = 1 ;

        $edges->countVertices( Traversal::OUTBOUND , 'users/1' , [ AQL::GRAPH => 'social' ] ) ;
        $this->assertStringStartsWith( 'FOR vertex IN OUTBOUND @startVertex' , $edges->lastQuery ) ;
    }

    public function testCountVerticesExplicitWithOverridesDerivation() :void
    {
        $edges = new MockEdges( 'follows' ) ;
        $edges->firstResult = 1 ;

        $edges->countVertices( Traversal::OUTBOUND , 'users/1' , [ AQL::WITH => [ 'alpha' , 'beta' ] ] ) ;
        $this->assertStringStartsWith
        (
            'WITH alpha, beta FOR vertex IN OUTBOUND @startVertex' ,
            $this->normalize( $edges->lastQuery ) ,
        ) ;
    }

    public function testCountVerticesWithoutVertexModelsOmitsWith() :void
    {
        $edges = new MockEdges( 'follows' ) ; // from / to stay null
        $edges->firstResult = 1 ;

        $edges->countVertices( Traversal::INBOUND , 'roles/2' ) ;
        $this->assertStringStartsWith( 'FOR vertex IN INBOUND @startVertex' , $edges->lastQuery ) ;
    }
}
