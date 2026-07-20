<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\UserMaxLevelResolver;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\signals\Signal;
use oihana\signals\notices\Payload;

use RuntimeException;
use Throwable;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use stdClass;

/**
 * Unit coverage for the three live entry points of
 * {@see UserMaxLevelResolver} :
 *
 * - {@see UserMaxLevelResolver::recompute()}     : single key + batch.
 * - {@see UserMaxLevelResolver::recomputeForRole()} : INBOUND from role.
 * - {@see UserMaxLevelResolver::backfillAll()}   : full graph walk.
 *
 * Plus the three signal listeners that fan out to the methods above —
 * we exercise the listener payload extraction (single edge object,
 * array of edges from a cascade `deleteEdges`, and role payload).
 *
 * The test does not hit ArangoDB ; the models are stubbed so we can
 * assert the AQL string + bind variables that would have been issued.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass( UserMaxLevelResolver::class )]
class UserMaxLevelResolverTest extends TestCase
{
    /** @var array<int,array{string,array<string,mixed>}> */
    private array $usersCalls = [] ;

    /** The stub models built by {@see createResolver()}, exposed for wiring/emit tests. */
    private Documents $usersModel ;
    private Edges     $userHasRolesModel ;
    private Documents $rolesModel ;

    public function testBackfillAllIssuesGraphWalkAndCountsResults() :void
    {
        $resolver = $this->createResolver( resultRows: [ 1 , 1 , 1 , 1 , 1 ] ) ;

        $count = $resolver->backfillAll() ;

        $this->assertSame( 5 , $count ) ;
        $this->assertCount( 1 , $this->usersCalls ) ;

        [ $query , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertStringContainsString( 'FOR u IN users'                       , $query ) ;
        $this->assertStringContainsString( 'OUTBOUND u user_has_roles'            , $query ) ;
        $this->assertStringContainsString( 'maxLevel: LENGTH(levels) > 0 ? MAX(levels) : 0' , $query ) ;
        $this->assertStringContainsString( 'UPDATE u WITH'                        , $query ) ;
        $this->assertSame( [] , $binds ) ;
    }

    public function testRecomputeWithSingleKeyEmitsBatchedQuery() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->recompute( '72488862' ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ $query , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertStringContainsString( 'FILTER u._key IN @keys' , $query ) ;
        $this->assertSame( [ 'keys' => [ '72488862' ] ] , $binds ) ;
    }

    public function testRecomputeWithMixedKeyAndIdInputDeduplicates() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->recompute([ 'users/abc' , 'abc' , 'def' , null , '' , 'def' ]) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertSame( [ 'abc' , 'def' ] , $binds[ 'keys' ] ) ;
    }

    public function testRecomputeIsNoOpOnEmptyInput() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->recompute( [] ) ;
        $resolver->recompute( '' ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testRecomputeForRoleIssuesInboundQuery() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->recomputeForRole( 'role-admin' ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ $query , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertStringContainsString( 'FOR u IN INBOUND CONCAT(@rolesCol, "/", @roleKey) user_has_roles' , $query ) ;
        $this->assertSame
        (
            [ 'rolesCol' => 'roles' , 'roleKey' => 'role-admin' ] ,
            $binds
        ) ;
    }

    public function testEdgeInsertedListenerExtractsKeyFromIdString() :void
    {
        $resolver = $this->createResolver() ;

        $edge        = new stdClass() ;
        $edge->_from = 'users/72488862' ;
        $edge->_to   = 'roles/admin' ;

        $resolver->onUserHasRolesEdgeInserted( new Payload( type: 'afterInsert' , data: $edge ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertSame( [ '72488862' ] , $binds[ 'keys' ] ) ;
    }

    public function testEdgeDeletedListenerHandlesArrayShapeFromCascade() :void
    {
        $resolver = $this->createResolver() ;

        $edges = [] ;

        foreach( [ 'users/u1' , 'users/u2' , 'users/u1' ] as $from )
        {
            $edge        = new stdClass() ;
            $edge->_from = $from ;
            $edges[]     = $edge ;
        }

        $resolver->onUserHasRolesEdgeDeleted( new Payload( type: 'afterDelete' , data: $edges ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertSame( [ 'u1' , 'u2' ] , $binds[ 'keys' ] ) ;
    }

    public function testEdgeListenerIsNoOpOnNullPayload() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->onUserHasRolesEdgeInserted( new Payload( type: 'afterInsert' , data: null ) ) ;
        $resolver->onUserHasRolesEdgeDeleted( new Payload( type: 'afterDelete' , data: null ) ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testRoleUpdatedListenerForwardsRoleKey() :void
    {
        $resolver = $this->createResolver() ;

        $role       = new stdClass() ;
        $role->_key = 'role-admin' ;
        $role->name = 'admin' ;

        $resolver->onRoleUpdated( new Payload( type: 'afterUpdate' , data: $role ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ $query , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertStringContainsString( 'INBOUND CONCAT(@rolesCol, "/", @roleKey) user_has_roles' , $query ) ;
        $this->assertSame( 'role-admin' , $binds[ 'roleKey' ] ) ;
    }

    public function testRoleUpdatedListenerIsNoOpOnMissingKey() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->onRoleUpdated( new Payload( type: 'afterUpdate' , data: null ) ) ;
        $resolver->onRoleUpdated( new Payload( type: 'afterUpdate' , data: new stdClass() ) ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testOperationsAreNoOpWhenModelsMissing() :void
    {
        $resolver = new UserMaxLevelResolver( null , null , null , null ) ;

        $this->assertSame( 0 , $resolver->backfillAll() ) ;
        $resolver->recompute( 'abc' ) ;
        $resolver->recomputeForRole( 'role-admin' ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testOperationsAreNoOpWhenCollectionIsNotAString() :void
    {
        $resolver = $this->createResolver() ;

        // a model present but with a non-string collection short-circuits each op
        $this->usersModel->collection = null ;

        $this->assertSame( 0 , $resolver->backfillAll() ) ;
        $resolver->recompute( 'abc' ) ;
        $resolver->recomputeForRole( 'role-admin' ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testRecomputeNormalizesObjectAndArrayEntries() :void
    {
        $resolver = $this->createResolver() ;

        $object        = new stdClass() ;
        $object->_key  = 'obj-key' ;

        $resolver->recompute([ $object , [ '_from' => 'users/arr-key' ] ]) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertSame( [ 'obj-key' , 'arr-key' ] , $binds[ 'keys' ] ) ;
    }

    public function testEdgeDeletedListenerHandlesArrayOfArrayShapedEdges() :void
    {
        $resolver = $this->createResolver() ;

        $resolver->onUserHasRolesEdgeDeleted( new Payload
        (
            type : 'afterDelete' ,
            data : [ [ '_from' => 'users/u1' ] , [ '_from' => 'users/u2' ] ]
        ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;

        [ , $binds ] = $this->usersCalls[ 0 ] ;

        $this->assertSame( [ 'u1' , 'u2' ] , $binds[ 'keys' ] ) ;
    }

    public function testEdgeListenerIsNoOpWhenNoUsableKeyIsExtracted() :void
    {
        $resolver = $this->createResolver() ;

        // a non-empty payload whose edges carry no usable `_from`
        $resolver->onUserHasRolesEdgeInserted( new Payload( type: 'afterInsert' , data: new stdClass() ) ) ;
        $resolver->onUserHasRolesEdgeDeleted( new Payload( type: 'afterDelete' , data: [ new stdClass() ] ) ) ;

        $this->assertCount( 0 , $this->usersCalls ) ;
    }

    public function testRegisterWiresTheThreeSignalsToTheirHandlers() :void
    {
        $resolver = $this->createResolver() ;

        $this->userHasRolesModel->afterInsert = new Signal() ;
        $this->userHasRolesModel->afterDelete = new Signal() ;
        $this->rolesModel->afterUpdate        = new Signal() ;

        $resolver->register() ;

        $inserted        = new stdClass() ;
        $inserted->_from = 'users/u-insert' ;
        $this->userHasRolesModel->afterInsert->emit( new Payload( type: 'afterInsert' , data: $inserted ) ) ;

        $deleted        = new stdClass() ;
        $deleted->_from = 'users/u-delete' ;
        $this->userHasRolesModel->afterDelete->emit( new Payload( type: 'afterDelete' , data: $deleted ) ) ;

        $role       = new stdClass() ;
        $role->_key = 'role-x' ;
        $this->rolesModel->afterUpdate->emit( new Payload( type: 'afterUpdate' , data: $role ) ) ;

        $this->assertCount( 3 , $this->usersCalls ) ;
        $this->assertSame( [ 'u-insert' ] , $this->usersCalls[ 0 ][ 1 ][ 'keys' ] ) ;
        $this->assertSame( [ 'u-delete' ] , $this->usersCalls[ 1 ][ 1 ][ 'keys' ] ) ;
        $this->assertSame( 'role-x'      , $this->usersCalls[ 2 ][ 1 ][ 'roleKey' ] ) ;
    }

    public function testRoleUpdatedListenerSwallowsRecomputeFailure() :void
    {
        $resolver = $this->createResolver( throwOnResult: new RuntimeException( 'boom' ) ) ;

        $role       = new stdClass() ;
        $role->_key = 'role-admin' ;

        // must not bubble up — the originating write must not break
        $resolver->onRoleUpdated( new Payload( type: 'afterUpdate' , data: $role ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;
    }

    public function testEdgeListenerSwallowsRecomputeFailure() :void
    {
        $resolver = $this->createResolver( throwOnResult: new RuntimeException( 'boom' ) ) ;

        $edge        = new stdClass() ;
        $edge->_from = 'users/u1' ;

        $resolver->onUserHasRolesEdgeInserted( new Payload( type: 'afterInsert' , data: $edge ) ) ;

        $this->assertCount( 1 , $this->usersCalls ) ;
    }

    /**
     * Builds a {@see UserMaxLevelResolver} wired with stub
     * {@see Documents} / {@see Edges} models. Captures every call to
     * `usersModel->getResult` into {@see self::$usersCalls} so each
     * test can assert on the AQL string + binds that would have been
     * issued. Returns a controlled `$resultRows` array on every call
     * so {@see UserMaxLevelResolver::backfillAll()} can be tested
     * end-to-end.
     *
     * @param array<int,int> $resultRows Rows the stub returns from
     *        `getResult` — `count($resultRows)` is what
     *        {@see UserMaxLevelResolver::backfillAll()} will report.
     * @param Throwable|null $throwOnResult When set, `getResult` throws it
     *        instead of returning, to exercise the listeners' catch paths.
     */
    private function createResolver( array $resultRows = [] , ?Throwable $throwOnResult = null ) :UserMaxLevelResolver
    {
        $usersModel = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'getResult' ])
            ->getMock() ;

        $usersModel->collection = 'users' ;

        $usersModel->method( 'getResult' )->willReturnCallback
        (
            function( string $query , array $binds = [] ) use ( $resultRows , $throwOnResult ) :array
            {
                $this->usersCalls[] = [ $query , $binds ] ;
                if ( $throwOnResult !== null )
                {
                    throw $throwOnResult ;
                }
                return $resultRows ;
            }
        ) ;

        $userHasRolesModel = $this->getMockBuilder( Edges::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'list' ])
            ->getMock() ;

        $userHasRolesModel->collection = 'user_has_roles' ;

        $rolesModel = $this->getMockBuilder( Documents::class )
            ->disableOriginalConstructor()
            ->onlyMethods([ 'get' ])
            ->getMock() ;

        $rolesModel->collection = 'roles' ;

        $this->usersModel        = $usersModel ;
        $this->userHasRolesModel = $userHasRolesModel ;
        $this->rolesModel        = $rolesModel ;

        return new UserMaxLevelResolver
        (
            usersModel        : $usersModel ,
            userHasRolesModel : $userHasRolesModel ,
            rolesModel        : $rolesModel ,
            logger            : null
        ) ;
    }
}
