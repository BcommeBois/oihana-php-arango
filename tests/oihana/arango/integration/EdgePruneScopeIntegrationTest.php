<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\Group;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\buildEdgeSubquery;

/**
 * Live validation of `AQL::WHERE` + `AQL::PRUNE` on a **ranged** edge relation —
 * the one guarantee the unit suite cannot give, because it hinges on how the
 * server walks the graph rather than on the AQL text.
 *
 * `AQL::WHERE` filters the traversal's OUTPUT. On a `1..N` traversal the walk
 * still descends THROUGH a masked vertex, so its own descendants keep being
 * projected: hiding a category does not hide what hangs below it. `AQL::PRUNE`
 * stops the walk. This test seeds the two shapes side by side and proves both
 * halves against a real arangod, including with the traversal options the edge
 * builder always emits (`order: bfs`, `uniqueVertices: global`) — pruning is
 * documented to interact with those, so it is measured rather than assumed.
 *
 * ```
 * a → b(masked) → c      the descent that must be cut
 * a → e         → f      the sibling descent that must survive
 * ```
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class EdgePruneScopeIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_edge_prune_it' ;

    private const string TERMS = 'terms' ;
    private const string EDGES = 'term_narrower' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection    ( self::TERMS )->create() ;
        $db->edgeCollection( self::EDGES )->create() ;

        foreach ( [ 'a' , 'b' , 'c' , 'e' , 'f' ] as $key )
        {
            $db->collection( self::TERMS )->insert( [ '_key' => $key , 'id' => $key ] ) ;
        }

        foreach ( [ [ 'a' , 'b' ] , [ 'b' , 'c' ] , [ 'a' , 'e' ] , [ 'e' , 'f' ] ] as [ $from , $to ] )
        {
            $db->edgeCollection( self::EDGES )->insert
            ([
                '_from' => self::TERMS . '/' . $from ,
                '_to'   => self::TERMS . '/' . $to ,
            ]) ;
        }
    }

    /**
     * Builds the real edge sub-query for the given definition, wraps it in a
     * minimal query anchored on `terms/a`, runs it, and returns the projected ids.
     *
     * @param array $extra Definition keys merged over the ranged base.
     *
     * @return array<int,string>
     *
     * @throws ArangoException
     */
    private function descendants( array $extra ) :array
    {
        $edges     = new MockEdges( self::EDGES ) ;
        $edges->to = null ; // a scalar PROPERTY projection needs no target model

        $subQuery = buildEdgeSubquery( 'descendants' ,
        [
            AQL::MODEL       => $edges ,
            AQL::MAX_DEPTH   => 5 ,
            Arango::PROPERTY => 'id' ,
            ...$extra ,
        ] , 'doc' ) ;

        $aql = 'FOR doc IN ' . self::TERMS . ' FILTER doc._key == "a" RETURN ' . $subQuery ;

        // The bind is supplied only when the query really references it — ArangoDB
        // rejects a declared-but-unreferenced bind, which is exactly what the model
        // layer prunes for us in the nominal flow (`prepareAndExecute()`).
        $binds = str_contains( $aql , '@hidden' ) ? [ 'hidden' => [ 'b' ] ] : [] ;

        $rows = iterator_to_array( self::$db->query( $aql , $binds ) , false ) ;

        $ids = $rows[ 0 ] ?? [] ;
        sort( $ids ) ;

        return $ids ;
    }

    /**
     * The whole descent, unrestricted — the baseline the two other cases are read
     * against.
     *
     * @throws ArangoException
     */
    public function testWithoutAnyScopeTheWholeDescentIsProjected() :void
    {
        $this->assertSame( [ 'b' , 'c' , 'e' , 'f' ] , $this->descendants( [] ) ) ;
    }

    /**
     * ⚠ The gap `AQL::PRUNE` exists to close: `b` is correctly hidden, but `c` — its
     * child — is still projected, because a `FILTER` removes a vertex from the
     * result without stopping the walk that goes through it.
     *
     * @throws ArangoException
     */
    public function testWhereAloneHidesTheVertexButNotItsDescent() :void
    {
        $this->assertSame
        (
            [ 'c' , 'e' , 'f' ] , // `c` leaks — no `b`, but its child is there
            $this->descendants( [ AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ] )
        ) ;
    }

    /**
     * With `AQL::PRUNE => true` the branch is cut at `b`: `c` is gone, and the
     * sibling descent `e → f` is untouched — pruning must not over-reach.
     *
     * @throws ArangoException
     */
    public function testPruneCutsTheMaskedBranchAndSparesItsSibling() :void
    {
        $this->assertSame
        (
            [ 'e' , 'f' ] ,
            $this->descendants
            ([
                AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ,
                AQL::PRUNE => true ,
            ])
        ) ;
    }

    /**
     * A stop condition of its own: the walk stops at `b` — so `c` never comes — but
     * nothing is filtered out, so `b` itself is still projected. This is the
     * distinction between the two keys, measured rather than described.
     *
     * @throws ArangoException
     */
    public function testAStandalonePruneStopsTheWalkWithoutHidingTheVertex() :void
    {
        $this->assertSame
        (
            [ 'b' , 'e' , 'f' ] , // `b` kept, its descent cut
            $this->descendants( [ AQL::PRUNE => [ 'id' , 'in' , aqlBindRef( 'hidden' ) ] ] )
        ) ;
    }
}
