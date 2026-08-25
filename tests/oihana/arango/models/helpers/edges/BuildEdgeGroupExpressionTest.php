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

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;
use function oihana\arango\models\helpers\edges\buildEdgeGroupExpression;

/**
 * Characterization coverage for {@see buildEdgeGroupExpression()} — the grouping
 * dimension of a relation, compiled as
 * `FIRST( FOR <key>_v IN [min..max] <dir> startVertex edgeCollection [PRUNE …]
 * OPTIONS { … } [FILTER …] RETURN <key>_v.<path> )`.
 *
 * Two themes run through these cases.
 *
 * **`FIRST()` is load-bearing, not cosmetic.** It is what makes the dimension a
 * scalar, and therefore what confines this helper to singular relations. Kept as
 * an array, the dimension would group by the *combination* of related values;
 * unwound before the `COLLECT`, it would count a multi-vertex document once per
 * vertex and inflate every other aggregate of the same pass. The caller refuses
 * plural relations for that reason; here we pin that the wrapper is always emitted.
 *
 * **The dimension must agree with the list.** Everything the definition says
 * about *which* vertices are walked — the depth range, the row scope, the
 * traversal options — is read exactly as the projection reads it. Read
 * differently, the group label would sit beside rows that contradict it.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgeGroupExpressionTest extends TestCase
{
    /**
     * The traversal options the dimension emits, exactly like the list —
     * `uniqueVertices: global` in particular, so a vertex reachable by two paths
     * is not walked twice.
     */
    private const string OPTIONS = 'OPTIONS {"order":"bfs","uniqueVertices":"global"}' ;

    /**
     * The ordinary shape: an `OUTBOUND` traversal from `doc`, projecting the
     * named field of the related vertex, wrapped in `FIRST()`.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testBuildsAnOutboundDimensionByDefault() :void
    {
        $this->assertSame
        (
            'FIRST(FOR author_v IN OUTBOUND doc articles_authors ' . self::OPTIONS . ' RETURN author_v.name)' ,
            buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] , 'name' ) ,
        ) ;
    }

    /**
     * The direction and the start vertex are read from the declaration, so a
     * relation pointing **at** the document is grouped the right way round.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testHonoursTheDirectionAndTheStartVertex() :void
    {
        $this->assertSame
        (
            'FIRST(FOR author_v IN INBOUND vertex articles_authors ' . self::OPTIONS . ' RETURN author_v.name)' ,
            buildEdgeGroupExpression
            (
                'author' ,
                [ AQL::MODEL => new MockEdges( 'articles_authors' ) , AQL::DIRECTION => Traversal::INBOUND ] ,
                'name' ,
                'vertex' ,
            ) ,
        ) ;
    }

    /**
     * `ANY` walks both ways — the declaration is passed through, not interpreted.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testHonoursAnyDirection() :void
    {
        $this->assertStringContainsString
        (
            'FOR author_v IN ANY doc articles_authors' ,
            buildEdgeGroupExpression
            (
                'author' ,
                [ AQL::MODEL => new MockEdges( 'articles_authors' ) , AQL::DIRECTION => Traversal::ANY ] ,
                'name' ,
            ) ,
        ) ;
    }

    /**
     * The loop variable is derived from the **dimension key**, so two dimensions
     * of the same query cannot walk into each other's variable.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheLoopVariableIsDerivedFromTheDimensionKey() :void
    {
        $author = buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] , 'name' ) ;
        $editor = buildEdgeGroupExpression( 'editor' , [ AQL::MODEL => new MockEdges( 'articles_editors' ) ] , 'name' ) ;

        $this->assertStringContainsString( 'FOR author_v IN' , $author ) ;
        $this->assertStringContainsString( 'FOR editor_v IN' , $editor ) ;
    }

    /**
     * A nested path reaches inside the related document, the array form and the
     * dotted string compiling identically.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testANestedPathReachesInsideTheRelatedDocument() :void
    {
        $definition = [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] ;

        $this->assertStringContainsString
        (
            'RETURN author_v.address.city)' ,
            buildEdgeGroupExpression( 'author' , $definition , [ 'address' , 'city' ] ) ,
        ) ;

        $this->assertSame
        (
            buildEdgeGroupExpression( 'author' , $definition , 'address.city' ) ,
            buildEdgeGroupExpression( 'author' , $definition , [ 'address' , 'city' ] ) ,
        ) ;
    }

    /**
     * `FIRST()` is always the wrapper: it is what makes the dimension a scalar,
     * and therefore what a `COLLECT` can group on.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheExpressionIsAlwaysScalar() :void
    {
        $expression = buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] , 'name' ) ;

        $this->assertStringStartsWith( 'FIRST(' , $expression ) ;
        $this->assertStringEndsWith( ')' , $expression ) ;
    }

    /**
     * The row scope of the declaration narrows the walked vertices, exactly as it
     * narrows the projected ones.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhereNarrowsTheWalkedVertices() :void
    {
        $this->assertSame
        (
            'FIRST(FOR author_v IN OUTBOUND doc articles_authors ' . self::OPTIONS
            . ' FILTER author_v.id NOT IN @hiddenTerms RETURN author_v.name)' ,
            buildEdgeGroupExpression
            (
                'author' ,
                [
                    AQL::MODEL => new MockEdges( 'articles_authors' ) ,
                    AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ,
                ] ,
                'name' ,
            ) ,
        ) ;
    }

    /**
     * A literal predicate works the same — nothing here is bind-specific.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhereAcceptsALiteralPredicate() :void
    {
        $this->assertStringContainsString
        (
            "FILTER author_v.status == 'active' RETURN author_v.name" ,
            buildEdgeGroupExpression
            (
                'author' ,
                [ AQL::MODEL => new MockEdges( 'articles_authors' ) , AQL::WHERE => [ 'status' , 'active' ] ] ,
                'name' ,
            ) ,
        ) ;
    }

    /**
     * Without the key, no `FILTER` is emitted — nothing else moves.
     *
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
        $this->assertStringNotContainsString
        (
            'FILTER' ,
            buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] , 'name' ) ,
        ) ;
    }

    /**
     * A malformed row scope is refused here too: the dimension reads the same
     * declaration as the list, refusals included.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testAMalformedWhereIsRefused() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;

        buildEdgeGroupExpression
        (
            'author' ,
            [ AQL::MODEL => new MockEdges( 'articles_authors' ) , AQL::WHERE => [] ] ,
            'name' ,
        ) ;
    }

    /**
     * The declared depth range is walked, so a hierarchy is grouped at the depth
     * the model says — not at the first level by accident.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheDeclaredDepthRangeIsWalked() :void
    {
        $this->assertStringContainsString
        (
            'FOR term_v IN 1..5 OUTBOUND doc term_narrower' ,
            buildEdgeGroupExpression
            (
                'term' ,
                [ AQL::MODEL => new MockEdges( 'term_narrower' ) , AQL::MAX_DEPTH => 5 ] ,
                'name' ,
            ) ,
        ) ;
    }

    /**
     * An explicit lower bound is honoured too — the same pair the list reads.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnExplicitLowerBoundIsHonoured() :void
    {
        $this->assertStringContainsString
        (
            'FOR term_v IN 2..4 OUTBOUND doc term_narrower' ,
            buildEdgeGroupExpression
            (
                'term' ,
                [ AQL::MODEL => new MockEdges( 'term_narrower' ) , AQL::MIN_DEPTH => 2 , AQL::MAX_DEPTH => 4 ] ,
                'name' ,
            ) ,
        ) ;
    }

    /**
     * Without a declared range the traversal stays single-level, as before.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutADepthRangeTheTraversalStaysSingleLevel() :void
    {
        $this->assertStringContainsString
        (
            'FOR author_v IN OUTBOUND doc articles_authors' ,
            buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( 'articles_authors' ) ] , 'name' ) ,
        ) ;
    }

    /**
     * The dimension prunes where the list prunes — on a hierarchy, filtering
     * without pruning walks branches the list already cut.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheDimensionPrunesWhereTheListPrunes() :void
    {
        $expression = buildEdgeGroupExpression
        (
            'term' ,
            [
                AQL::MODEL => new MockEdges( 'term_narrower' ) ,
                AQL::WHERE => [ 'status' , 'active' ] ,
                AQL::PRUNE => true ,
            ] ,
            'name' ,
        ) ;

        $this->assertStringContainsString( 'PRUNE' , $expression ) ;
        $this->assertStringContainsString( 'FILTER term_v.status' , $expression ) ;
    }

    /**
     * A `PRUNE` with nothing to prune on is a mis-declaration, refused here as
     * it is on the list.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     */
    public function testPruneWithoutWhereIsRefused() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeGroupExpression
        (
            'term' ,
            [ AQL::MODEL => new MockEdges( 'term_narrower' ) , AQL::PRUNE => true ] ,
            'name' ,
        ) ;
    }

    /**
     * The declaration must name a real relation: anything else cannot be walked.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testThrowsWhenTheModelIsNotAnEdgesInstance() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeGroupExpression( 'author' , [ AQL::MODEL => 'articles_authors' ] , 'name' ) ;
    }

    /**
     * An edge model without a collection has no traversal source.
     *
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testThrowsWhenTheCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildEdgeGroupExpression( 'author' , [ AQL::MODEL => new MockEdges( '' ) ] , 'name' ) ;
    }
}
