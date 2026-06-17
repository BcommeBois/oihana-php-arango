<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\InvertedIndex;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\options\views\SearchAliasView;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live validation of the `search-alias` substrate (Lot G1a): an `inverted`
 * index declared per collection, aggregated by a `search-alias` view, queried
 * with a single federated `SEARCH` spanning both collections.
 *
 * Proves end-to-end that `View::createSearchAlias()` + `SearchAliasView` produce
 * a view that actually parses, indexes and returns cross-collection results on a
 * real server — the foundation of the federated search engine (Chantier C).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class SearchAliasViewIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_search_alias_it' ;

    private const string CUSTOMERS = 'it_sa_customers' ;
    private const string PRODUCTS  = 'it_sa_products' ;
    private const string VIEW      = 'it_sa_global' ;
    private const string INDEX     = 'inv_search' ;

    /**
     * Seeds two collections, each with an `inverted` index on `tag` (identity
     * analyzer → exact match), then a `search-alias` view aggregating both.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::CUSTOMERS )->create() ;
        $db->collection( self::PRODUCTS )->create() ;

        $index = new InvertedIndex( fields: [ 'tag' ] , name: self::INDEX , analyzer: 'identity' ) ;
        $db->collection( self::CUSTOMERS )->createIndex( $index ) ;
        $db->collection( self::PRODUCTS )->createIndex( $index ) ;

        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c1' , 'tag' => 'shared' ] ) ;
        $db->collection( self::CUSTOMERS )->insert( [ '_key' => 'c2' , 'tag' => 'other'  ] ) ;
        $db->collection( self::PRODUCTS  )->insert( [ '_key' => 'p1' , 'tag' => 'shared' ] ) ;

        $view = new SearchAliasView( self::VIEW , [ self::CUSTOMERS => self::INDEX , self::PRODUCTS => self::INDEX ] ) ;
        $db->view( self::VIEW )->createSearchAlias( $view->getIndexes() ) ;
    }

    public function testFederatedSearchSpansBothCollections() :void
    {
        // A single SEARCH over the search-alias view returns the matching
        // documents from BOTH collections, ordered by _id.
        $ids = $this->waitForSearch( 'shared' , [ self::CUSTOMERS . '/c1' , self::PRODUCTS . '/p1' ] ) ;

        $this->assertSame( [ self::CUSTOMERS . '/c1' , self::PRODUCTS . '/p1' ] , $ids ) ;
    }

    /**
     * Polls the federated SEARCH until it returns the expected handles
     * (inverted-index eventual consistency).
     *
     * @param string             $tag
     * @param array<int, string> $expected
     *
     * @return array<int, string>
     *
     * @throws ArangoException
     */
    private function waitForSearch( string $tag , array $expected ) :array
    {
        $aql = 'FOR d IN ' . self::VIEW . ' SEARCH d.tag == @tag SORT d._id RETURN d._id' ;

        $ids = [] ;
        for ( $attempt = 0 ; $attempt < 150 ; $attempt++ )
        {
            $ids = array_values( iterator_to_array( self::$db->query( $aql , [ 'tag' => $tag ] ) ) ) ;

            if ( $ids === $expected )
            {
                return $ids ;
            }

            usleep( 100_000 ) ; // 100 ms
        }

        return $ids ;
    }
}
