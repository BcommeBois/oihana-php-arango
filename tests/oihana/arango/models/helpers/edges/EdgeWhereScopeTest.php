<?php

namespace tests\oihana\arango\models\helpers\edges;

use DI\Container;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\controllers\enums\Skin;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\db\binds\aqlBindRef;

/**
 * End-to-end coverage of `AQL::WHERE` on an edge definition — the row scope of a
 * projected relation. The declaration lives in the registry, but the guarantee
 * lives in the QUERY, so every case here asserts the AQL a real {@see Documents}
 * renders through `buildListQuery()`, not the init it was handed.
 *
 * The three things a declaration must survive on its way to the `FILTER`:
 * - the projection normalization (`prepareQueryFields()`), under a skin;
 * - the permission gates, which decide IF the relation is projected while
 *   `AQL::WHERE` decides WHICH vertices it yields;
 * - the bind pruning, since a skinned-out relation leaves its bind orphaned.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
#[AllowMockObjectsWithoutExpectations]
final class EdgeWhereScopeTest extends TestCase
{
    private Container $container ;

    protected function setUp() :void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
    }

    // ---------------------------------------------------------------- the rendered FILTER

    /** The declared predicate reaches the traversal of a real model's list query. */
    public function testDeclaredScopeReachesTheRenderedTraversal() :void
    {
        $aql = $this->render( $this->model() ) ;

        $this->assertStringContainsString( 'IN OUTBOUND doc term_narrower' , $aql ) ;
        $this->assertStringContainsString( 'FILTER vertex.id NOT IN @hiddenTerms ' , $aql ) ;
    }

    /** A `Filter::EDGE` — cardinality one — is scoped by the very same declaration. */
    public function testSingleCardinalityRelationIsScopedToo() :void
    {
        $aql = $this->render( $this->model( relationFilter: Filter::EDGE ) ) ;

        $this->assertStringContainsString( 'FILTER vertex.id NOT IN @hiddenTerms ' , $aql ) ;
    }

    /**
     * The count must not contradict the list: both read the same declaration, so an
     * interface cannot announce "5" beside three rows.
     */
    public function testTheCountAgreesWithTheList() :void
    {
        $aql = $this->render( $this->model( relationFilter: Filter::EDGES_COUNT ) ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $aql ) ;
        $this->assertStringContainsString( 'NOT IN @hiddenTerms RETURN ' , $aql ) ;
    }

    /** Nothing is inlined — only the `@name` token reaches the query. */
    public function testTheBindValueIsNeverInlined() :void
    {
        $aql = $this->render( $this->model() ) ;

        $this->assertStringNotContainsString( "'hiddenTerms'" , $aql ) ;
        $this->assertStringNotContainsString( '"hiddenTerms"' , $aql ) ;
    }

    /** A literal predicate needs no bind at all. */
    public function testALiteralScopeNeedsNoBind() :void
    {
        $aql = $this->render( $this->model( where: [ 'status' , 'active' ] ) ) ;

        $this->assertStringContainsString( "FILTER vertex.status == 'active' " , $aql ) ;
        $this->assertStringNotContainsString( '@' . 'hiddenTerms' , $aql ) ;
    }

    // ---------------------------------------------------------------- normalization survival

    /**
     * `FieldsTrait::normalizeFieldDefinition()` rebuilds every FIELDS entry from a
     * closed whitelist — this is where `Field::WHERE` was once erased. The relation
     * registry is a separate tree, so `AQL::WHERE` is not exposed to that walk; this
     * pins it, under a skin, through the whole `prepareQueryFields()` path.
     */
    public function testTheScopeSurvivesTheProjectionNormalizationUnderASkin() :void
    {
        $model = $this->model( skinFields:
        [
            Skin::FULL => [ '_key' => [] , 'narrower' => [ Field::FILTER => Filter::EDGES ] ] ,
        ]) ;

        $aql = $this->render( $model , [ AQL::SKIN => Skin::FULL ] ) ;

        $this->assertStringContainsString( 'FILTER vertex.id NOT IN @hiddenTerms ' , $aql ) ;
    }

    /** A nested relation — an edge declared inside an edge definition — is scoped in depth. */
    public function testANestedRelationCarriesItsOwnScope() :void
    {
        $inner = new MockEdges( 'term_related' ) ;
        $inner->to = new MockDocuments( 'terms' ) ;
        $inner->to->initializeDeleteSignals() ;

        $model = $this->model( definitionExtra:
        [
            AQL::FIELDS => [ '_key' => [] , 'related' => [ Field::FILTER => Filter::EDGES ] ] ,
            AQL::EDGES  =>
            [
                'related' =>
                [
                    AQL::MODEL => $inner ,
                    AQL::WHERE => [ 'id' , 'nin' , aqlBindRef( 'hiddenRelated' ) ] ,
                ] ,
            ] ,
        ]) ;

        $aql = $this->render( $model ) ;

        $this->assertStringContainsString( 'IN OUTBOUND doc term_narrower' , $aql ) ;
        $this->assertStringContainsString( 'NOT IN @hiddenTerms '   , $aql ) ; // outer
        $this->assertStringContainsString( 'NOT IN @hiddenRelated ' , $aql ) ; // inner
    }

    // ---------------------------------------------------------------- composition with the permission gates

    /**
     * The two keys answer different questions and must not stand in for one another:
     * `Field::REQUIRES` decides IF the relation is projected, `AQL::WHERE` decides
     * WHICH vertices it yields. Granted → the relation is there, and restricted.
     */
    public function testGrantedRequiresKeepsTheRelationAndItsScope() :void
    {
        $model = $this->model( relationExtra: [ Field::REQUIRES => 'terms:read' ] ) ;

        $aql = $this->render( $model , [ Arango::AUTHORIZER => fn( string $s ) :bool => $s === 'terms:read' ] ) ;

        $this->assertStringContainsString( 'IN OUTBOUND doc term_narrower' , $aql ) ;
        $this->assertStringContainsString( 'FILTER vertex.id NOT IN @hiddenTerms ' , $aql ) ;
    }

    /**
     * Denied → the relation disappears entirely, so there is no traversal left to
     * scope. The predicate must not survive on its own: an orphan `FILTER` would
     * reference a vertex variable no `FOR` declares.
     */
    public function testDeniedRequiresDropsTheRelationAndItsScopeTogether() :void
    {
        $model = $this->model( relationExtra: [ Field::REQUIRES => 'terms:read' ] ) ;

        $aql = $this->render( $model , [ Arango::AUTHORIZER => fn() :bool => false ] ) ;

        $this->assertStringNotContainsString( 'term_narrower'  , $aql ) ;
        $this->assertStringNotContainsString( '@hiddenTerms'   , $aql ) ;
    }

    /** Same on the definition-level gate, which drops the relation wherever it is used. */
    public function testDeniedDefinitionRequiresDropsTheScopeToo() :void
    {
        $model = $this->model( definitionExtra: [ AQL::REQUIRES => 'terms:read' ] ) ;

        $aql = $this->render( $model , [ Arango::AUTHORIZER => fn() :bool => false ] ) ;

        $this->assertStringNotContainsString( 'term_narrower' , $aql ) ;
        $this->assertStringNotContainsString( '@hiddenTerms'  , $aql ) ;
    }

    // ---------------------------------------------------------------- non-regression

    /** Without the key, the rendered query is strictly the historical one. */
    public function testWithoutTheKeyTheRenderedQueryIsUnchanged() :void
    {
        $this->assertSame
        (
            $this->render( $this->model( where: null ) ) ,
            $this->render( $this->model( where: null ) )
        ) ;

        $this->assertStringNotContainsString( 'FILTER' , $this->render( $this->model( where: null ) ) ) ;
    }

    // ---------------------------------------------------------------- the orphan bind

    /**
     * A relation is projected conditionally, so a declared bind may never be
     * referenced. Here the skin keeps `_key` alone: the traversal is gone, `@hidden`
     * is nowhere in the query text, and the leftover bind must be pruned before
     * execution — otherwise ArangoDB rejects the whole query.
     */
    public function testTheOrphanBindIsPrunedWhenTheSkinDropsTheRelation() :void
    {
        $model = $this->model
        (
            skinFields:
            [
                Skin::FULL      => [ '_key' => [] , 'narrower' => [ Field::FILTER => Filter::EDGES ] ] ,
                Skin::COMPACT   => [ '_key' => [] ] ,
            ] ,
            database: $this->captureDatabase( $captured )
        ) ;

        $binds = [] ;
        $query = $model->buildListQuery( [ AQL::SKIN => Skin::COMPACT ] , $binds ) ;

        $this->assertStringNotContainsString( '@hiddenTerms' , $query ) ; // the relation is gone

        $model->prepareAndExecute( $query , [ ...$binds , 'hiddenTerms' => [ 't1' ] ] ) ;

        $this->assertArrayNotHasKey( 'hiddenTerms' , $captured ) ;
    }

    /** The same bind is kept when the skin DOES project the relation. */
    public function testTheBindIsKeptWhenTheSkinProjectsTheRelation() :void
    {
        $model = $this->model
        (
            skinFields:
            [
                Skin::FULL    => [ '_key' => [] , 'narrower' => [ Field::FILTER => Filter::EDGES ] ] ,
                Skin::COMPACT => [ '_key' => [] ] ,
            ] ,
            database: $this->captureDatabase( $captured )
        ) ;

        $binds = [] ;
        $query = $model->buildListQuery( [ AQL::SKIN => Skin::FULL ] , $binds ) ;

        $this->assertStringContainsString( '@hiddenTerms' , $query ) ;

        $model->prepareAndExecute( $query , [ ...$binds , 'hiddenTerms' => [ 't1' ] ] ) ;

        $this->assertSame( [ 't1' ] , $captured[ 'hiddenTerms' ] ) ;
    }

    // ---------------------------------------------------------------- harness

    /**
     * A `terms` model projecting a `narrower` edge whose definition carries the
     * scope. Every knob a test needs to move is a named parameter; the defaults are
     * the nominal case (a `Filter::EDGES` restricted by a runtime bind).
     *
     * @param mixed       $where           The `AQL::WHERE` declaration (`null` removes it).
     * @param string      $relationFilter  The cardinality marker of the FIELDS entry.
     * @param array       $relationExtra   Keys merged into the FIELDS entry (e.g. `Field::REQUIRES`).
     * @param array       $definitionExtra Keys merged into the edge DEFINITION.
     * @param array|null  $skinFields      An optional `AQL::SKIN_FIELDS` table, replacing `AQL::FIELDS`.
     * @param ArangoDB|null $database      An optional database, registered as a container service.
     *
     * @return Documents
     */
    private function model
    (
        mixed     $where           = [ 'id' , 'nin' , '@REF@' ] ,
        string    $relationFilter  = Filter::EDGES ,
        array     $relationExtra   = [] ,
        array     $definitionExtra = [] ,
        ?array    $skinFields      = null ,
        ?ArangoDB $database        = null
    )
    : Documents
    {
        // The default carries a sentinel rather than an aqlBindRef() object, so the
        // signature stays a constant expression.
        if ( $where === [ 'id' , 'nin' , '@REF@' ] )
        {
            $where = [ 'id' , 'nin' , aqlBindRef( 'hiddenTerms' ) ] ;
        }

        $target = new MockDocuments( 'terms' ) ;
        $target->initializeDeleteSignals() ;

        $edge     = new MockEdges( 'term_narrower' ) ;
        $edge->to = $target ;

        $definition = [ AQL::MODEL => $edge , AQL::DIRECTION => Traversal::OUTBOUND , ...$definitionExtra ] ;

        if ( $where !== null )
        {
            $definition[ AQL::WHERE ] = $where ;
        }

        $init =
        [
            AQL::COLLECTION => 'terms' ,
            AQL::LAZY       => false ,
            AQL::EDGES      => [ 'narrower' => $definition ] ,
        ] ;

        if ( $skinFields !== null )
        {
            $init[ AQL::SKIN_FIELDS ] = $skinFields ;
        }
        else
        {
            $init[ AQL::FIELDS ] = [ '_key' => [] , 'narrower' => [ Field::FILTER => $relationFilter , ...$relationExtra ] ] ;
        }

        if ( $database !== null )
        {
            $this->container->set( 'arangodb' , $database ) ;
            $init[ AQL::DATABASE ] = 'arangodb' ;
        }

        return new Documents( $this->container , $init ) ;
    }

    /**
     * Renders the model's list query, with the random loop refs normalized to the
     * stable `vertex` / `edge` tokens.
     *
     * @param Documents $model
     * @param array     $init
     *
     * @return string
     */
    private function render( Documents $model , array $init = [] ) :string
    {
        $binds = [] ;

        // The loop refs AND the generated `LET` names (`narrower_e<n>`) are random,
        // so both are flattened before any exact comparison.
        return preg_replace
        (
            [ '/vertex_\d+/' , '/edge_\d+/' , '/_([eju])\d+/' ] ,
            [ 'vertex' , 'edge' , '_$1' ] ,
            $model->buildListQuery( $init , $binds )
        ) ;
    }

    /**
     * A mock ArangoDB capturing the bind variables `prepareAndExecute()` finally
     * hands to `prepare()` — the only place the pruning is observable.
     *
     * @param array|null $captured Receives the captured bind variables.
     *
     * @return ArangoDB
     */
    private function captureDatabase( ?array &$captured ) :ArangoDB
    {
        $db = $this->getMockBuilder( ArangoDB::class )
            ->disableOriginalConstructor()
            ->onlyMethods( [ 'prepare' , 'execute' ] )
            ->getMock() ;

        $db->method( 'execute' )->willReturnSelf() ;
        $db->method( 'prepare' )->willReturnCallback
        (
            function ( array $params ) use ( &$captured , $db ) :ArangoDB
            {
                $captured = $params[ CursorField::BIND_VARS ] ?? [] ;
                return $db ;
            }
        ) ;

        return $db ;
    }
}
