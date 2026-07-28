<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\Group;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\buildEdgeCountVariable;
use function oihana\arango\models\helpers\edges\buildEdgeSubquery;

/**
 * Live proof that a `Filter::EDGES_COUNT` and a `Filter::EDGES` reading the **same**
 * definition return consistent answers — the count being the number of rows the list
 * yields, never a different number.
 *
 * Three declarations used to break that, each for its own reason, and none of them is
 * visible in the AQL text alone: they depend on how the server walks the graph. Hence
 * a live test rather than a rendered-string one.
 *
 * ```
 * a ─┬─ b ── d       `d` reachable by TWO paths (a diamond)
 *    ├─ c ── d
 *    └─ c            the `a → c` edge exists TWICE
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class EdgeCountAgreesWithListIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_count_agrees_it' ;

    private const string TERMS = 'terms' ;
    private const string EDGES = 'term_narrower' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection    ( self::TERMS )->create() ;
        $db->edgeCollection( self::EDGES )->create() ;

        foreach ( [ 'a' , 'b' , 'c' , 'd' ] as $key )
        {
            $db->collection( self::TERMS )->insert( [ '_key' => $key , 'id' => $key ] ) ;
        }

        // The duplicated `a → c` and the diamond through `d` are both deliberate.
        $links =
        [
            [ 'a' , 'b' ] , [ 'a' , 'c' ] , [ 'a' , 'c' ] ,
            [ 'b' , 'd' ] , [ 'c' , 'd' ] ,
        ] ;

        foreach ( $links as [ $from , $to ] )
        {
            $db->edgeCollection( self::EDGES )->insert
            ([
                '_from' => self::TERMS . '/' . $from ,
                '_to'   => self::TERMS . '/' . $to ,
            ]) ;
        }
    }

    /**
     * Runs the real list builder and the real count builder over one definition, and
     * returns `[ <projected ids> , <count> ]` as the server answers them.
     *
     * @param array $definition The edge definition both builders read.
     *
     * @return array{0: array<int,string>, 1: int}
     *
     * @throws ArangoException
     */
    private function listAndCount( array $definition ) :array
    {
        $edges     = new MockEdges( self::EDGES ) ;
        $edges->to = null ; // a scalar PROPERTY projection needs no target model

        $definition = [ AQL::MODEL => $edges , Arango::PROPERTY => 'id' , ...$definition ] ;

        $subQuery = buildEdgeSubquery      ( 'descendants'      , $definition , 'doc' ) ;
        $countLet = buildEdgeCountVariable ( 'descendantsCount' , $definition , 'doc' ) ;

        $aql = 'FOR doc IN ' . self::TERMS . ' FILTER doc._key == "a" '
             . $countLet
             . ' RETURN { ids: ' . $subQuery . ' , count: descendantsCount }' ;

        $binds = str_contains( $aql , '@hidden' ) ? [ 'hidden' => [ 'c' ] ] : [] ;

        $row = iterator_to_array( self::$db->query( $aql , $binds ) , false )[ 0 ] ;

        $ids = (array) $row[ 'ids' ] ;
        sort( $ids ) ;

        return [ $ids , $row[ 'count' ] ] ;
    }

    /**
     * Depth 1 with a duplicated edge. The list de-duplicates `c`; the count used to
     * emit no traversal options, so it walked one path per edge and answered 3.
     *
     * @throws ArangoException
     */
    public function testADuplicatedEdgeIsNotCountedTwice() :void
    {
        [ $ids , $count ] = $this->listAndCount( [] ) ;

        $this->assertSame( [ 'b' , 'c' ] , $ids ) ;
        $this->assertSame( 2 , $count ) ; // was 3
    }

    /**
     * The declared range, over the diamond plus the duplicated edge. The list yields
     * the whole descent once each; the count used to ignore the range entirely and
     * answer the number of direct children — measured against the two other numbers
     * this shape produced before, 6 with the range but no options, 2 with neither.
     *
     * @throws ArangoException
     */
    public function testTheDeclaredDepthRangeIsCountedExactlyOncePerVertex() :void
    {
        [ $ids , $count ] = $this->listAndCount( [ AQL::MAX_DEPTH => 5 ] ) ;

        $this->assertSame( [ 'b' , 'c' , 'd' ] , $ids ) ;
        $this->assertSame( 3 , $count ) ; // was 2 (no range) — and 6 with a range but no options
    }

    /**
     * An explicit lower bound: only the vertices at depth 2 and beyond. Both sides
     * read the same pair, so both answer on the same rows.
     *
     * @throws ArangoException
     */
    public function testAnExplicitLowerBoundIsHonouredBySides() :void
    {
        [ $ids , $count ] = $this->listAndCount( [ AQL::MIN_DEPTH => 2 , AQL::MAX_DEPTH => 5 ] ) ;

        $this->assertSame( [ 'd' ] , $ids ) ;
        $this->assertSame( 1 , $count ) ;
    }

    /**
     * The row scope, on a ranged relation: `c` is masked and pruned, so its descent is
     * cut. `d` survives because the diamond still reaches it through `b` — proof the
     * prune does not over-reach — and the count says exactly what the list shows.
     *
     * @throws ArangoException
     */
    public function testTheScopeAndThePruneAreCountedTheSameWayAsTheyAreListed() :void
    {
        [ $ids , $count ] = $this->listAndCount
        ([
            AQL::MAX_DEPTH => 5 ,
            AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ,
            AQL::PRUNE     => true ,
        ]) ;

        $this->assertSame( [ 'b' , 'd' ] , $ids ) ;
        $this->assertSame( 2 , $count ) ;
    }
}
