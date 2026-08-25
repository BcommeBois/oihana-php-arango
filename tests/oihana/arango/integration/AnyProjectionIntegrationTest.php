<?php

namespace tests\oihana\arango\integration;

use UnexpectedValueException;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\Attributes\Group;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\buildEdgeSubquery;

/**
 * Live validation of {@see Traversal::ANY} on a **projected** relation — the one
 * direction the unit suite can only freeze as a string.
 *
 * `ANY` had never reached a server through this path: the only live `ANY` of the
 * suite goes through `countVertices()`, a different builder. So the guarantee
 * that a self-referential relation "keeps projecting as before" was, until this
 * case, an assertion about AQL text rather than about an answer.
 *
 * The seed is built so a green run means something. Each direction must produce
 * a **different** set, and `ANY` must produce one that **neither** oriented
 * direction can:
 *
 * ```
 * a → b        b reached OUTBOUND only
 * a ⇄ c        c reached either way — the doubling case
 * d → a        d reached INBOUND only
 * ```
 *
 * Without the `d → a` edge, `ANY` and `OUTBOUND` answer the same two vertices
 * and an `ANY` silently behaving like `OUTBOUND` would still pass.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class AnyProjectionIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_any_projection_it' ;

    private const string TERMS = 'terms' ;
    private const string EDGES = 'term_links' ;

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

        foreach ( [ [ 'a' , 'b' ] , [ 'a' , 'c' ] , [ 'c' , 'a' ] , [ 'd' , 'a' ] ] as [ $from , $to ] )
        {
            $db->edgeCollection( self::EDGES )->insert
            ([
                '_from' => self::TERMS . '/' . $from ,
                '_to'   => self::TERMS . '/' . $to ,
            ]) ;
        }
    }

    /**
     * The vertices `terms/a` reaches in the given direction, through the real
     * edge sub-query the projection emits.
     *
     * @param string $direction The declared `AQL::DIRECTION`.
     *
     * @return array<int,string>
     *
     * @throws ArangoException
     */
    private function linked( string $direction ) :array
    {
        $edges     = new MockEdges( self::EDGES ) ;
        $edges->to = null ; // a scalar PROPERTY projection needs no target model

        $subQuery = buildEdgeSubquery( 'linked' ,
        [
            AQL::MODEL       => $edges ,
            AQL::DIRECTION   => $direction ,
            Arango::PROPERTY => 'id' ,
        ] , 'doc' ) ;

        $aql  = 'FOR doc IN ' . self::TERMS . ' FILTER doc._key == "a" RETURN ' . $subQuery ;
        $rows = iterator_to_array( self::$db->query( $aql ) , false ) ;

        $ids = $rows[ 0 ] ?? [] ;
        sort( $ids ) ;

        return $ids ;
    }

    /**
     * The two oriented directions, the baseline the `ANY` case is read against.
     *
     * @throws ArangoException
     */
    public function testEachOrientedDirectionReachesItsOwnSide() :void
    {
        $this->assertSame( [ 'b' , 'c' ] , $this->linked( Traversal::OUTBOUND ) ) ;
        $this->assertSame( [ 'c' , 'd' ] , $this->linked( Traversal::INBOUND  ) ) ;
    }

    /**
     * `ANY` walks both ways: the server accepts the sub-traversal, and it answers
     * the union — `d`, which only an inbound edge reaches, next to `b`, which only
     * an outbound one does.
     *
     * @throws ArangoException
     */
    public function testAnyReachesBothSidesAtOnce() :void
    {
        $this->assertSame( [ 'b' , 'c' , 'd' ] , $this->linked( Traversal::ANY ) ) ;
    }

    /**
     * The control the case carries: three vertices is an answer **neither**
     * oriented direction can produce, so a green run cannot be an `ANY` quietly
     * behaving like one of them. Without this, the assertion above would still
     * pass on a seed where the two sides overlap.
     *
     * @throws ArangoException
     */
    public function testTheAnyAnswerIsReachableByNoOrientedDirection() :void
    {
        $any = $this->linked( Traversal::ANY ) ;

        $this->assertNotSame( $any , $this->linked( Traversal::OUTBOUND ) ) ;
        $this->assertNotSame( $any , $this->linked( Traversal::INBOUND  ) ) ;
        $this->assertCount( 3 , $any ) ;
    }

    /**
     * ⚠ The asymmetry worth measuring rather than assuming: `c` is linked to `a`
     * in **both** directions, so `ANY` traverses two edges to it — and it comes
     * back **once**. The edge builder always emits `uniqueVertices: "global"`,
     * which deduplicates the walk.
     *
     * A linked **facet** carries no such option, which is why its documentation
     * warns that `ANY` reaches a doubly-linked vertex twice and that
     * `Facet::DISTINCT` earns its keep there. The same keyword, two behaviours:
     * the projection is the protected one.
     *
     * @throws ArangoException
     */
    public function testADoublyLinkedVertexIsProjectedOnce() :void
    {
        $any = $this->linked( Traversal::ANY ) ;

        $this->assertSame( [ 'c' ] , array_values( array_filter( $any , fn( $id ) => $id === 'c' ) ) ) ;
    }

    /**
     * The refusal, next to the answers it protects: the same `ANY`, on a relation
     * whose two ends are different collections, has no single model to project
     * with — so it never reaches the server at all.
     */
    public function testAnyOverTwoDifferentCollectionsNeverReachesTheServer() :void
    {
        $edges = new MockEdges( self::EDGES ) ;

        foreach ( [ 'from' => self::TERMS , 'to' => 'documents' ] as $end => $collection )
        {
            $vertex = new MockDocuments( $collection ) ;
            $vertex->initializeDeleteSignals() ;
            $edges->$end = $vertex ;
        }

        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeSubquery( 'linked' ,
        [
            AQL::MODEL     => $edges ,
            AQL::DIRECTION => Traversal::ANY ,
            AQL::FIELDS    => [ 'id' => [] ] ,
        ] , 'doc' ) ;
    }
}
