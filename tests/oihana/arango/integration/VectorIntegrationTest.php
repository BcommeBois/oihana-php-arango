<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\VectorIndex;
use oihana\arango\clients\exceptions\ArangoException;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\operations\aqlVectorSearch;

/**
 * Live validation of the {@see \oihana\arango\db\operations\aqlVectorSearch()}
 * builder and the underlying `APPROX_NEAR_*` functions.
 *
 * A small set of 4-dimensional unit vectors is indexed with a cosine
 * {@see VectorIndex}; the builder must rank the document whose embedding points
 * in the same direction as the query vector first. Asserting the *ranking*
 * (not just that the query parses) proves the metric ⇄ sort-direction wiring is
 * correct on a real server.
 *
 * Skipped when no ArangoDB is reachable, **or** when the server was not started
 * with the experimental vector index feature — in that case `seed()` fails to
 * create the index and {@see IntegrationTestCase} skips the whole class.
 *
 * @group integration
 */
#[Group( 'integration' )]
final class VectorIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_vector_it' ;

    private const string COLLECTION = 'embeddings' ;

    /**
     * Seeds four orthogonal unit vectors then builds a cosine vector index.
     *
     * Documents are inserted **before** the index so Faiss can train on them.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $items = $db->collection( self::COLLECTION ) ;
        $items->create() ;

        $items->insert( [ 'label' => 'A' , 'embedding' => [ 1.0 , 0.0 , 0.0 , 0.0 ] ] ) ;
        $items->insert( [ 'label' => 'B' , 'embedding' => [ 0.0 , 1.0 , 0.0 , 0.0 ] ] ) ;
        $items->insert( [ 'label' => 'C' , 'embedding' => [ 0.0 , 0.0 , 1.0 , 0.0 ] ] ) ;
        $items->insert( [ 'label' => 'D' , 'embedding' => [ 0.0 , 0.0 , 0.0 , 1.0 ] ] ) ;

        // Throws when the experimental vector index feature is disabled → class skips.
        $items->createIndex
        (
            new VectorIndex
            (
                fields : [ 'embedding' ] ,
                params :
                [
                    'dimension' => 4 ,
                    'metric'    => 'cosine' ,
                    'nLists'    => 1 ,
                ] ,
            )
        ) ;
    }

    /**
     * Runs an ANN query and returns the matched `label` values, nearest first.
     *
     * @param array<int,float> $query
     * @return array<int,string>
     * @throws ArangoException
     */
    private function search( array $query , int $limit , string $metric = 'cosine' ) :array
    {
        $aql = aqlVectorSearch
        (
            collection : self::COLLECTION ,
            attribute  : 'embedding' ,
            vector     : '@query' ,
            limit      : $limit ,
            metric     : $metric ,
        ) ;

        $rows = iterator_to_array( self::$db->query( $aql , [ 'query' => $query ] ) , false ) ;

        return array_map( fn( $r ) => is_array( $r ) ? $r[ 'label' ] : $r->label , $rows ) ;
    }

    public function testCosineNearestNeighbourRanksByDirection() :void
    {
        // A query close to A's direction must return A first.
        $labels = $this->search( [ 0.9 , 0.1 , 0.0 , 0.0 ] , 1 ) ;
        $this->assertSame( [ 'A' ] , $labels ) ;
    }

    public function testCosineHonoursLimit() :void
    {
        $labels = $this->search( [ 0.0 , 0.0 , 0.9 , 0.1 ] , 2 ) ;
        $this->assertCount( 2 , $labels ) ;
        $this->assertSame( 'C' , $labels[ 0 ] ) ; // C is the closest direction.
    }
}
