<?php

namespace tests\oihana\arango\integration ;

use oihana\arango\clients\Database ;

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

    protected static function seed( Database $db ) :void
    {
        $things = $db->collection( self::COLLECTION ) ;
        $things->create() ;

        // Embedded complex arrays (Facet::ARRAY_COMPLEX): each element carries a
        // nested `breeding.alternateName`.
        $things->insert( [ '_key' => 't1' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'pig'    ] ] , [ 'breeding' => [ 'alternateName' => 'cattle' ] ] ] ] ) ;
        $things->insert( [ '_key' => 't2' , 'workshops' => [ [ 'breeding' => [ 'alternateName' => 'sheep'  ] ] ] ] ) ;
        $things->insert( [ '_key' => 't3' , 'workshops' => [] ] ) ;
    }

    /**
     * Builds the facet FILTER fragment with the real builder, runs it inside a
     * full FOR..FILTER..RETURN query, and returns the matched `_key`s (sorted).
     *
     * @param array<string,mixed> $binds
     *
     * @return list<string>
     */
    private function keys( string $filter , array $binds ) :array
    {
        $aql    = 'FOR doc IN ' . self::COLLECTION . ' FILTER ' . $filter . ' RETURN doc._key' ;
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
        $this->assertSame( [ 't1' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testArrayComplexMultipleValuesAreOred() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => [ 'pig' , 'sheep' ] ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testArrayComplexNegativeIsExistentialNotEqual() :void
    {
        // `-pig` => keep docs having AT LEAST ONE element whose alternateName != pig.
        // t1 (pig+cattle) qualifies via cattle; t2 (sheep) qualifies; t3 (empty) does not.
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => '-pig' ] , $binds ) ;
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( $filter , $binds ) ) ;
    }

    public function testArrayComplexEmptyArrayPropertyNeverMatches() :void
    {
        $binds = [] ;
        $filter = $this->stub()->callArrayComplex( 'workshops' , [ 'breeding.alternateName' => 'anything' ] , $binds ) ;
        $this->assertNotContains( 't3' , $this->keys( $filter , $binds ) ) ;
    }
}
