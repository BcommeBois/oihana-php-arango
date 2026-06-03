<?php

namespace tests\oihana\arango\integration ;

use oihana\arango\clients\Database ;
use oihana\arango\db\enums\AQL ;

use tests\oihana\arango\models\traits\aql\FacetTraitStub ;

use PHPUnit\Framework\Attributes\Group ;

/**
 * Live validation of the facet builders: each facet FILTER fragment is
 * embedded in a real `FOR doc IN <collection> FILTER <fragment> RETURN doc._key`
 * query and executed against a seeded, disposable ArangoDB database. This
 * proves the generated AQL actually parses AND filters as intended — something
 * the unit suite (which only freezes the AQL string) cannot.
 *
 * @see FacetTraitStub The same builder host used by the unit suite.
 */
#[Group( 'integration' )]
class FacetIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_facets_it' ;

    private const string COLLECTION = 'things' ;

    private const int EDGE_TYPE = 3 ;

    protected static function seed( Database $db ) :void
    {
        // --- Facet::ARRAY_COMPLEX : embedded complex arrays -----------------
        // Each element of `workshops` carries a nested `breeding.alternateName`.
        $things = $db->collection( self::COLLECTION ) ;
        $things->create() ;
        $things->insert( [ '_key' => 't1' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'pig'    ] ] , [ 'breeding' => [ 'alternateName' => 'cattle' ] ] ] ] ) ;
        $things->insert( [ '_key' => 't2' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'sheep'  ] ] ] ] ) ;
        $things->insert( [ '_key' => 't3' , 'workshops' => [] ] ) ;

        // --- Facet::EDGE : INBOUND graph (place)-[orgs_places]->(org) --------
        $orgs = $db->collection( 'orgs' ) ;
        $orgs->create() ;
        foreach ( [ 'o1' , 'o2' , 'o3' , 'o4' ] as $k ) { $orgs->insert( [ '_key' => $k ] ) ; }

        $places = $db->collection( 'places' ) ;
        $places->create() ;
        foreach ( [ '1234' , '5678' , 'pB' ] as $k ) { $places->insert( [ '_key' => $k ] ) ; }

        $edges = $db->collection( 'orgs_places' ) ;
        $edges->create( [ 'type' => self::EDGE_TYPE ] ) ;
        // o1→1234 ; o2→pB ; o3→1234 & 5678 ; o4→5678
        $edges->insert( [ '_from' => 'places/1234' , '_to' => 'orgs/o1' ] ) ;
        $edges->insert( [ '_from' => 'places/pB'   , '_to' => 'orgs/o2' ] ) ;
        $edges->insert( [ '_from' => 'places/1234' , '_to' => 'orgs/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/5678' , '_to' => 'orgs/o3' ] ) ;
        $edges->insert( [ '_from' => 'places/5678' , '_to' => 'orgs/o4' ] ) ;
    }

    /**
     * Builds the facet FILTER fragment with the real builder, runs it inside a
     * full FOR..FILTER..RETURN query over `$collection`, and returns the matched
     * `_key`s (sorted).
     *
     * @param array<string,mixed> $binds
     *
     * @return list<string>
     */
    private function keys( string $collection , string $filter , array $binds ) :array
    {
        $aql    = 'FOR doc IN ' . $collection . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $cursor = self::$db->query( $aql , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    private function stub() :FacetTraitStub
    {
        $stub = new FacetTraitStub() ;
        $stub->collection = self::COLLECTION ;
        return $stub ;
    }

    // ---------------------------------------------------------------- ARRAY_COMPLEX

    public function testArrayComplexScalarMatchesContainingDocument() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'pig' ] , $binds ) ;
        $this->assertSame( [ 't1' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexMultipleValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => [ 'pig' , 'sheep' ] ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexNegativeIsExistentialNotEqual() :void
    {
        // `-pig` => keep docs having AT LEAST ONE element whose alternateName != pig.
        // t1 (pig+cattle) qualifies via cattle; t2 (sheep) qualifies; t3 (empty) does not.
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => '-pig' ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    public function testArrayComplexEmptyArrayPropertyNeverMatches() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'anything' ] , $binds ) ;
        $this->assertNotContains( 't3' , $this->keys( self::COLLECTION , $filter , $binds ) ) ;
    }

    // ---------------------------------------------------------------- EDGE

    public function testEdgeSingleValueMatchesLinkedDocuments() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , 1234 , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o1' , 'o3' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeMultipleValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '1234,5678' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o1' , 'o3' , 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeNegativeValueExcludesLinkedDocuments() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o2' , 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }

    public function testEdgeMixedPositiveAndNegative() :void
    {
        // linked to 5678 AND not linked to 1234 => only o4
        $binds = [] ;
        $filter = $this->stub()->callEdge( 'location' , '5678,-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ;
        $this->assertSame( [ 'o4' ] , $this->keys( 'orgs' , $filter , $binds ) ) ;
    }
}
