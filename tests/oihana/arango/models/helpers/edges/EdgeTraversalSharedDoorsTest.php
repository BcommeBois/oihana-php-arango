<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\exceptions\BindException;
use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\options\TraversalOption;
use oihana\arango\db\enums\options\TraversalOrder;
use oihana\arango\db\enums\options\TraversalUniqueVertices;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\edgeTraversalOptions;
use function oihana\arango\models\helpers\edges\resolveEdgeDepthRange;
use function oihana\arango\models\helpers\edges\resolveEdgeVertexScope;

/**
 * Coverage for the three doors an edge definition is read through by **both** the
 * list ({@see \oihana\arango\models\helpers\edges\buildEdgeSubquery()}) and the count
 * ({@see \oihana\arango\models\helpers\edges\buildEdgeCountVariable()}):
 * the depth range, the row scope, and the traversal options.
 *
 * They exist as shared helpers for one reason: the count used to read the same
 * declaration differently from the list — ignoring the depth range and the options —
 * and produced a number the rows contradicted. Testing them here, once, pins the
 * interpretation both builders now inherit.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class EdgeTraversalSharedDoorsTest extends TestCase
{
    // ---------------------------------------------------------------- resolveEdgeDepthRange()

    public function testNoDepthDeclaredYieldsANullPair() :void
    {
        $this->assertSame( [ null , null ] , resolveEdgeDepthRange( [] ) ) ;
    }

    /** `AQL::MAX_DEPTH` alone defaults the lower bound to 1 — the natural `1..N`. */
    public function testMaxDepthAloneDefaultsTheLowerBoundToOne() :void
    {
        $this->assertSame( [ 1 , 5 ] , resolveEdgeDepthRange( [ AQL::MAX_DEPTH => 5 ] ) ) ;
    }

    public function testAnExplicitRangeIsReturnedAsIs() :void
    {
        $this->assertSame( [ 2 , 4 ] , resolveEdgeDepthRange( [ AQL::MIN_DEPTH => 2 , AQL::MAX_DEPTH => 4 ] ) ) ;
    }

    /**
     * `AQL::MIN_DEPTH` alone is refused: ArangoDB requires a bounded range, and an
     * unbounded walk over a self-referential edge risks a runaway cycle.
     */
    public function testMinDepthAloneIsRefused() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        resolveEdgeDepthRange( [ AQL::MIN_DEPTH => 2 ] ) ;
    }

    // ---------------------------------------------------------------- resolveEdgeVertexScope()

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNoScopeDeclaredYieldsANullPair() :void
    {
        $this->assertSame( [ null , null ] , resolveEdgeVertexScope( [] , 'v' ) ) ;
    }

    /**
     * The predicate is compiled against the vertex reference it is handed.
     * @return void
     *
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhereIsCompiledAgainstTheGivenVertexRef() :void
    {
        $this->assertSame
        (
            [ 'v.id NOT IN @hidden' , null ] ,
            resolveEdgeVertexScope( [ AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ] , 'v' )
        ) ;
    }

    /**
     * `true` negates the filter — one declaration, so the two cannot drift apart.
     * @return void
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testPruneTrueNegatesTheFilter() :void
    {
        $this->assertSame
        (
            [ 'v.id NOT IN @hidden' , '!(v.id NOT IN @hidden)' ] ,
            resolveEdgeVertexScope
            (
                [ AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] , AQL::PRUNE => true ] ,
                'v'
            )
        ) ;
    }

    /**
     * A condition is compiled, not read as a boolean. Read as one it would be truthy
     * and silently swapped for the negated `AQL::WHERE` — the declared stop condition
     * would vanish without a word.
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testPruneAcceptsItsOwnCondition() :void
    {
        $this->assertSame
        (
            [ null , 'v.archived == true' ] ,
            resolveEdgeVertexScope( [ AQL::PRUNE => [ 'archived' , true ] ] , 'v' )
        ) ;
    }

    /**
     * `false` means OFF, exactly like an absent key. The key accepts a boolean, so a
     * host writes `AQL::PRUNE => $cutDescendants` — and a false flag must not blow up
     * the query build. It used to raise, with a message naming neither the key nor
     * the mistake.
     *
     * @return void
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testPruneFalseMeansNoPruning() :void
    {
        $this->assertSame
        (
            [ 'v.id NOT IN @hidden' , null ] ,
            resolveEdgeVertexScope
            (
                [ AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] , AQL::PRUNE => false ] ,
                'v'
            )
        ) ;
    }

    /** `false` alone is inert too — no scope at all, no exception. */
    public function testPruneFalseAloneIsInert() :void
    {
        $this->assertSame( [ null , null ] , resolveEdgeVertexScope( [ AQL::PRUNE => false ] , 'v' ) ) ;
    }

    /**
     * A genuinely unusable value still fails loud: `0` is neither the boolean toggle
     * nor a condition, so it is not quietly read as "off".
     *
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnUnusableTruthyValueStillFailsLoud() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        resolveEdgeVertexScope( [ AQL::PRUNE => 0 ] , 'v' ) ;
    }

    /** Both keys at once, each with its own condition — the pair is independent. */
    public function testWhereAndAnExplicitPruneCoexist() :void
    {
        $this->assertSame
        (
            [ "v.status == 'active'" , 'v.archived == true' ] ,
            resolveEdgeVertexScope
            (
                [ AQL::WHERE => [ 'status' , 'active' ] , AQL::PRUNE => [ 'archived' , true ] ] ,
                'v'
            )
        ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testPruneTrueWithoutWhereIsRefused() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        resolveEdgeVertexScope( [ AQL::PRUNE => true ] , 'v' ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAMalformedWhereIsRefused() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        resolveEdgeVertexScope( [ AQL::WHERE => [] ] , 'v' ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAMalformedPruneIsRefused() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        resolveEdgeVertexScope( [ AQL::PRUNE => [] ] , 'v' ) ;
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnUnsafeAttributeNameIsRefused() :void
    {
        $this->expectException( ValidationException::class ) ;

        resolveEdgeVertexScope( [ AQL::WHERE => [ 'a" || true || "' , 'x' ] ] , 'v' ) ;
    }

    // ---------------------------------------------------------------- edgeTraversalOptions()

    /**
     * `uniqueVertices: global` is the part that decides *how many* rows come back: a
     * vertex reachable by two paths (a diamond, or a duplicated edge) is yielded once
     * instead of once per path. The list always had it, the count did not.
     */
    public function testTheOptionsPinBreadthFirstAndGlobalUniqueVertices() :void
    {
        $this->assertSame
        (
            [
                TraversalOption::ORDER           => TraversalOrder::BFS ,
                TraversalOption::UNIQUE_VERTICES => TraversalUniqueVertices::GLOBAL ,
            ] ,
            edgeTraversalOptions()
        ) ;
    }
}
