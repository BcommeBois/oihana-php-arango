<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\exceptions\BindException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\exceptions\UnsupportedOperationException;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\buildEdgeCountVariable;

/**
 * Characterization coverage for {@see buildEdgeCountVariable()} — builds a
 * `LET name = LENGTH( FOR <name>_v IN [min..max] <dir> startVertex edgeCollection
 * [PRUNE …] OPTIONS { … } [FILTER …] RETURN <name>_v )` count expression. The inner
 * loop variable is derived from the LET name (never the shared `vertex`) so the count
 * composes inside a vertex traversal without collision.
 *
 * The recurring theme of the ranged / scoped cases: **the count must agree with the
 * list**. Everything the definition says about *which* vertices are walked — the
 * depth range, the row scope, the traversal options — has to be read here exactly as
 * {@see \oihana\arango\models\helpers\edges\buildEdgeSubquery()} reads it, otherwise
 * the number lands beside rows that contradict it.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgeCountVariableTest extends TestCase
{
    /**
     * The traversal options the count now emits, exactly like the list. They are not
     * cosmetic: `uniqueVertices: global` is what stops a vertex reachable by two
     * paths — a diamond, or a plainly duplicated edge — from being counted twice.
     */
    private const string OPTIONS = 'OPTIONS {"order":"bfs","uniqueVertices":"global"}' ;

    /**
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testBuildsOutboundCountByDefault() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND doc user_has_roles OPTIONS {"order":"bfs","uniqueVertices":"global"} RETURN rolesCount_v))' ,
            buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => $edges ] )
        ) ;
    }

    /**
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testHonorsDirectionStartVertexAndUniqueName() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $this->assertSame
        (
            'LET cnt = (LENGTH(FOR cnt_v IN INBOUND v user_has_roles OPTIONS {"order":"bfs","uniqueVertices":"global"} RETURN cnt_v))' ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [ AQL::MODEL => $edges , AQL::DIRECTION => Traversal::INBOUND , AQL::UNIQUE => 'cnt' ] ,
                'v'
            )
        ) ;
    }

    /**
     * Regression: when the count is projected through a vertex traversal
     * (Edges::getVertices()), the outer loop is already named `vertex`. The inner
     * count loop must use a distinct variable, otherwise ArangoDB raises
     * "variable 'vertex' is assigned multiple times". Here the start vertex is the
     * outer `vertex` and the inner loop must NOT reuse it.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testInnerLoopDoesNotCollideWithAnOuterVertexLoop() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $aql = buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => $edges ] , AQL::VERTEX ) ;

        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND vertex user_has_roles OPTIONS {"order":"bfs","uniqueVertices":"global"} RETURN rolesCount_v))' ,
            $aql
        ) ;

        // Two distinct FOR loop variables, none reusing the shared `vertex` loop name.
        $this->assertStringNotContainsString( 'FOR vertex IN' , $aql ) ;
    }

    // ------------------------------------------------------------------ AQL::WHERE (the count must agree with the list)

    /**
     * The count honours the same predicate as the list, compiled against the inner
     * loop variable. A count ignoring it would announce "5" beside a list showing
     * 3 — the divergence is the bug, not the filtering.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhereFiltersTheCountedVertices() :void
    {
        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND doc user_has_roles ' .
            self::OPTIONS . ' FILTER rolesCount_v.id NOT IN @hiddenTerms RETURN rolesCount_v))' ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [
                    AQL::MODEL => new MockEdges( 'user_has_roles' ) ,
                    AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
                ]
            )
        ) ;
    }

    /**
     * A literal predicate works the same — nothing about the count is bind-specific.
     *
     * @return void
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhereAcceptsALiteralPredicateInTheCount() :void
    {
        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND doc user_has_roles ' .
            self::OPTIONS . ' ' . "FILTER rolesCount_v.status == 'active' RETURN rolesCount_v))" ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , AQL::WHERE => [ 'status' , 'active' ] ]
            )
        ) ;
    }

    /**
     * Without the key, no `FILTER` is emitted — nothing else moves.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutWhereNoFilterIsEmitted() :void
    {
        $this->assertSame
        (
            'LET rolesCount = (LENGTH(FOR rolesCount_v IN OUTBOUND doc user_has_roles ' .
            self::OPTIONS . ' RETURN rolesCount_v))' ,
            buildEdgeCountVariable
            (
                'rolesCount' ,
                [ AQL::MODEL => new MockEdges( 'user_has_roles' ) , AQL::WHERE => null ]
            )
        ) ;
    }

    /**
     * A malformed descriptor fails loud here too — never a silently unfiltered count.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testMalformedWhereThrowsInTheCount() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockEdges( 'e' ) , AQL::WHERE => [] ] ) ;
    }

    // ------------------------------------------------------------------ the depth range (the count counted the wrong thing)

    /**
     * The bug this closes: the count ignored the declared range entirely, so a
     * definition shared with a `Filter::EDGES` list — the registry's string shortcut
     * is the idiomatic way to share one — produced a list of the whole descent beside
     * a count of the direct children. Measured live as 4 rows under a count of 2.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheDeclaredDepthRangeIsCounted() :void
    {
        $this->assertSame
        (
            'LET descendantsCount = (LENGTH(FOR descendantsCount_v IN 1..5 OUTBOUND doc term_narrower ' .
            self::OPTIONS . ' RETURN descendantsCount_v))' ,
            buildEdgeCountVariable
            (
                'descendantsCount' ,
                [ AQL::MODEL => new MockEdges( 'term_narrower' ) , AQL::MAX_DEPTH => 5 ]
            )
        ) ;
    }

    /**
     * An explicit lower bound is honoured — the count reads the same pair as the list.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnExplicitLowerBoundIsCounted() :void
    {
        $this->assertStringContainsString
        (
            'IN 2..4 OUTBOUND doc term_narrower' ,
            buildEdgeCountVariable
            (
                'descendantsCount' ,
                [ AQL::MODEL => new MockEdges( 'term_narrower' ) , AQL::MIN_DEPTH => 2 , AQL::MAX_DEPTH => 4 ]
            )
        ) ;
    }

    /**
     * `AQL::MIN_DEPTH` alone is refused here exactly as it is on the list — the
     * refusal rule lives in the shared helper, so the two cannot disagree about
     * which declarations are legal.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testMinDepthAloneIsRefusedInTheCountToo() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockEdges( 'e' ) , AQL::MIN_DEPTH => 2 ] ) ;
    }

    /**
     * No depth declared → no range emitted, the count stays a single-level walk.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutADepthRangeTheCountStaysSingleLevel() :void
    {
        $this->assertStringContainsString
        (
            'IN OUTBOUND doc user_has_roles' ,
            buildEdgeCountVariable( 'rolesCount' , [ AQL::MODEL => new MockEdges( 'user_has_roles' ) ] )
        ) ;
    }

    // ------------------------------------------------------------------ AQL::PRUNE (now that the count walks deep)

    /**
     * Once the count honours the depth range, it must stop where the list stops —
     * otherwise it would count the descendants of a vertex the list pruned away, and
     * the number would again contradict the rows.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheCountPrunesWhereTheListPrunes() :void
    {
        $this->assertSame
        (
            'LET descendantsCount = (LENGTH(FOR descendantsCount_v IN 1..5 OUTBOUND doc term_narrower ' .
            'PRUNE !(descendantsCount_v.id NOT IN @hidden) ' . self::OPTIONS . ' ' .
            'FILTER descendantsCount_v.id NOT IN @hidden RETURN descendantsCount_v))' ,
            buildEdgeCountVariable
            (
                'descendantsCount' ,
                [
                    AQL::MODEL     => new MockEdges( 'term_narrower' ) ,
                    AQL::MAX_DEPTH => 5 ,
                    AQL::WHERE     => [ 'id' , 'nin' , aqlBindRef( 'hidden' ) ] ,
                    AQL::PRUNE     => true ,
                ]
            )
        ) ;
    }

    /**
     * A stop condition of its own reaches the count as well.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheCountAcceptsAStandalonePruneCondition() :void
    {
        $this->assertStringContainsString
        (
            'PRUNE descendantsCount_v.archived == true ' . self::OPTIONS ,
            buildEdgeCountVariable
            (
                'descendantsCount' ,
                [
                    AQL::MODEL     => new MockEdges( 'term_narrower' ) ,
                    AQL::MAX_DEPTH => 5 ,
                    AQL::PRUNE     => [ 'archived' , true ] ,
                ]
            )
        ) ;
    }

    /**
     * `true` with nothing to negate is a wiring error on this path too.
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testPruneTrueWithoutWhereThrowsInTheCount() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockEdges( 'e' ) , AQL::PRUNE => true ] ) ;
    }

    /**
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testThrowsWhenModelIsNotEdges() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ;
    }

    /**
     * @return void
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testThrowsWhenCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeCountVariable( 'x' , [ AQL::MODEL => new MockEdges( '' ) ] ) ;
    }
}
