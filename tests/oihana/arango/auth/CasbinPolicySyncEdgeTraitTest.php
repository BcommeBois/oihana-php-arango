<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;
use oihana\auth\enums\EdgeSyncType;
use oihana\signals\Signal;
use oihana\signals\notices\Payload;

use stdClass;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Characterization coverage for {@see CasbinPolicySyncEdgeTrait} — the pure
 * dispatcher routing an edge model's `afterInsert` / `afterDelete` signals to
 * the per-domain `add*` / `remove*` handlers by {@see EdgeSyncType}.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncEdgeTraitTest extends CasbinSyncTestCase
{
    /**
     * Every routable edge sync type — used to drive the dispatch arms.
     *
     * @return string[]
     */
    private function syncTypes() :array
    {
        return
        [
            EdgeSyncType::POLICY_PERMISSION ,
            EdgeSyncType::ROLE_PERMISSION ,
            EdgeSyncType::ROLE_POLICY ,
            EdgeSyncType::SERVICE_PERMISSION ,
            EdgeSyncType::SERVICE_POLICY ,
            EdgeSyncType::USER_PERMISSION ,
            EdgeSyncType::USER_ROLE ,
        ] ;
    }

    // ---- register -------------------------------------------------------

    public function testRegisterIsNoOpWithoutEnforcer() :void
    {
        $edges = $this->edges( 'role_has_permissions' ) ;
        $edges->afterInsert = new Signal() ;
        $edges->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync() ;
        $sync->register( $edges , EdgeSyncType::ROLE_PERMISSION ) ;

        // nothing connected → emitting is inert
        $edges->afterInsert->emit( new Payload( type: 'afterInsert' , data: $this->edge( 'roles/r1' , 'permissions/p1' ) ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterWiresInsertAndDeleteSignals() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'read' ) ;

        $edges = $this->edges( 'role_has_permissions' ) ;
        $edges->afterInsert = new Signal() ;
        $edges->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->register( $edges , EdgeSyncType::ROLE_PERMISSION ) ;

        $edges->afterInsert->emit( new Payload
        (
            type: 'afterInsert' ,
            data: $this->edge( 'roles/r1' , 'permissions/perm1' )
        ) ) ;

        $edges->afterDelete->emit( new Payload
        (
            type: 'afterDelete' ,
            data: $this->edge( 'roles/r1' , 'permissions/perm1' )
        ) ) ;

        $this->assertSame
        (
            [
                [ 'addPolicy'    , [ 'role-id' , 'my-api' , 'x' , 'read' , 'allow' ] ] ,
                [ 'removePolicy' , [ 'role-id' , 'my-api' , 'x' , 'read' , 'allow' ] ] ,
            ] ,
            $enforcer->calls
        ) ;
    }

    // ---- onEdgeInsert ---------------------------------------------------

    public function testOnEdgeInsertRoutesUserRoleToGrouping() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'domain'     => 'my-api' ,
            'usersModel' => $users ,
            'rolesModel' => $roles ,
        ]) ;

        $sync->invoke( 'onEdgeInsert' , new Payload
        (
            type: 'afterInsert' ,
            data: $this->edge( 'users/u1' , 'roles/r1' )
        ) , EdgeSyncType::USER_ROLE ) ;

        $this->assertSame
        (
            [ [ 'addGroupingPolicy' , [ 'user-id' , 'role-id' , 'my-api' ] ] ] ,
            $enforcer->calls
        ) ;
    }

    public function testOnEdgeInsertIsNoOpOnNullDataOrMissingEndpoints() :void
    {
        $enforcer = new SpyEnforcer() ;
        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->invoke( 'onEdgeInsert' , new Payload( type: 'afterInsert' , data: null ) , EdgeSyncType::ROLE_PERMISSION ) ;
        $sync->invoke( 'onEdgeInsert' , new Payload( type: 'afterInsert' , data: $this->edge( null , 'permissions/p' ) ) , EdgeSyncType::ROLE_PERMISSION ) ;
        $sync->invoke( 'onEdgeInsert' , new Payload( type: 'afterInsert' , data: $this->edge( 'roles/r' , null ) ) , EdgeSyncType::ROLE_PERMISSION ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testOnEdgeInsertCoversEveryDispatchArmAndUnknownType() :void
    {
        $enforcer = new SpyEnforcer() ;

        // only the enforcer is wired → every handler short-circuits on its
        // missing model, but every match arm is exercised.
        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        foreach( $this->syncTypes() as $type )
        {
            $sync->invoke( 'onEdgeInsert' , new Payload
            (
                type: 'afterInsert' ,
                data: $this->edge( 'from/x' , 'to/y' )
            ) , $type ) ;
        }

        // unknown type → default arm (null)
        $sync->invoke( 'onEdgeInsert' , new Payload
        (
            type: 'afterInsert' ,
            data: $this->edge( 'from/x' , 'to/y' )
        ) , 'unknown_type' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- onEdgeDelete ---------------------------------------------------

    public function testOnEdgeDeleteRoutesUserRoleToGroupingRemoval() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'domain'     => 'my-api' ,
            'usersModel' => $users ,
            'rolesModel' => $roles ,
        ]) ;

        // delete payload as a list (cascade shape)
        $sync->invoke( 'onEdgeDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: [ $this->edge( 'users/u1' , 'roles/r1' ) ]
        ) , EdgeSyncType::USER_ROLE ) ;

        $this->assertSame
        (
            [ [ 'removeGroupingPolicy' , [ 'user-id' , 'role-id' , 'my-api' ] ] ] ,
            $enforcer->calls
        ) ;
    }

    public function testOnEdgeDeleteIsNoOpOnNullDataOrMissingEndpoints() :void
    {
        $enforcer = new SpyEnforcer() ;
        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->invoke( 'onEdgeDelete' , new Payload( type: 'afterDelete' , data: null ) , EdgeSyncType::ROLE_PERMISSION ) ;

        // an edge missing an endpoint is skipped
        $sync->invoke( 'onEdgeDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: [ $this->edge( null , 'permissions/p' ) , $this->edge( 'roles/r' , null ) ]
        ) , EdgeSyncType::ROLE_PERMISSION ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testOnEdgeDeleteCoversEveryDispatchArmAndUnknownTypeWithArrayShape() :void
    {
        $enforcer = new SpyEnforcer() ;
        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        foreach( $this->syncTypes() as $type )
        {
            // array-shaped edge to also exercise the array `_from` / `_to` reads
            $sync->invoke( 'onEdgeDelete' , new Payload
            (
                type: 'afterDelete' ,
                data: [ [ '_from' => 'from/x' , '_to' => 'to/y' ] ]
            ) , $type ) ;
        }

        $sync->invoke( 'onEdgeDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: $this->edge( 'from/x' , 'to/y' )
        ) , 'unknown_type' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }
}
