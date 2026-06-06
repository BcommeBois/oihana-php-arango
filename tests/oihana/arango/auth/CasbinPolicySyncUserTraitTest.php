<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;
use oihana\signals\Signal;
use oihana\signals\notices\Payload;

use stdClass;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Characterization coverage for {@see CasbinPolicySyncUserTrait} — the user
 * branch of the live Casbin RBAC sync (direct permissions, role groupings,
 * cascade-aware user deletion).
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncUserTraitTest extends CasbinSyncTestCase
{
    // ---- addUserPermissionPolicy ----------------------------------------

    public function testAddUserPermissionAddsPolicyKeyedOnIdentifier() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'read' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $users ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'addUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'user-id' , 'my-api' , 'x' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddUserPermissionIsNoOpWhenIdentifierMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $this->documents( 'users' ) , // unmapped → null id
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;

        $sync->invoke( 'addUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testAddUserPermissionIsNoOpWhenPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $users ,
            'permissionsModel' => $this->documents( 'permissions' ) , // perm missing
        ]) ;

        $sync->invoke( 'addUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- addUserRoleGrouping --------------------------------------------

    public function testAddUserRoleGroupingAddsGrouping() :void
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

        $sync->invoke( 'addUserRoleGrouping' , 'u1' , 'r1' ) ;

        $this->assertSame
        (
            [ [ 'addGroupingPolicy' , [ 'user-id' , 'role-id' , 'my-api' ] ] ] ,
            $enforcer->calls
        ) ;
    }

    public function testAddUserRoleGroupingIsNoOpWhenUserOrRoleUnresolved() :void
    {
        $enforcer = new SpyEnforcer() ;

        // user unresolved
        $sync = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'usersModel' => $this->documents( 'users' ) ,
            'rolesModel' => $this->documents( 'roles' ) ,
        ]) ;

        $sync->invoke( 'addUserRoleGrouping' , 'u1' , 'r1' ) ;

        // user resolved but role unresolved
        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'usersModel' => $users ,
            'rolesModel' => $this->documents( 'roles' ) ,
        ]) ;

        $sync2->invoke( 'addUserRoleGrouping' , 'u1' , 'r1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- removeUserPermissionPolicy / removeUserRoleGrouping ------------

    public function testRemoveUserPermissionRemovesPolicy() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'del' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $users ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'removeUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'user-id' , 'my-api' , 'x' , 'del' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testRemoveUserPermissionIsNoOpWhenIdentifierOrPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        // identifier missing
        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $this->documents( 'users' ) ,
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;

        $sync->invoke( 'removeUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        // identifier present, permission missing
        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'usersModel'       => $users ,
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;

        $sync2->invoke( 'removeUserPermissionPolicy' , 'u1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testRemoveUserRoleGroupingRemovesGrouping() :void
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

        $sync->invoke( 'removeUserRoleGrouping' , 'u1' , 'r1' ) ;

        $this->assertSame
        (
            [ [ 'removeGroupingPolicy' , [ 'user-id' , 'role-id' , 'my-api' ] ] ] ,
            $enforcer->calls
        ) ;
    }

    public function testRemoveUserRoleGroupingIsNoOpWhenUserOrRoleUnresolved() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'usersModel' => $this->documents( 'users' ) ,
            'rolesModel' => $this->documents( 'roles' ) ,
        ]) ;

        $sync->invoke( 'removeUserRoleGrouping' , 'u1' , 'r1' ) ;

        // user resolved, role unresolved
        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( 'user-id' ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'   => $enforcer ,
            'usersModel' => $users ,
            'rolesModel' => $this->documents( 'roles' ) ,
        ]) ;

        $sync2->invoke( 'removeUserRoleGrouping' , 'u1' , 'r1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- registerUserDelete / onUserDelete ------------------------------

    public function testRegisterUserDeleteIsNoOpWithoutEnforcer() :void
    {
        $users = $this->documents( 'users' ) ;
        $users->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'usersModel' => $users ]) ;
        $sync->registerUserDelete( $users ) ;

        $users->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->withIdentifier( 'x' ) ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterUserDeleteWiresAfterDeleteToDeleteUser() :void
    {
        $enforcer = new SpyEnforcer() ;

        $users = $this->documents( 'users' ) ;
        $users->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer , 'usersModel' => $users ]) ;
        $sync->registerUserDelete( $users ) ;

        $users->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->withIdentifier( 'user-del' ) ) ) ;

        $this->assertSame( [ [ 'deleteUser' , [ 'user-del' ] ] ] , $enforcer->calls ) ;
    }

    public function testOnUserDeleteUsesIdentifierOnlyAndSkipsIdentifierless() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $arrayUser = [ 'identifier' => 'arr-user' ] ;
        $numeric   = $this->withIdentifier( '98765' ) ; // numeric → n_ prefix
        $idless    = new stdClass() ;                    // no identifier → skipped

        $sync->invoke( 'onUserDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: [ $this->withIdentifier( 'obj-user' ) , $arrayUser , $numeric , $idless ]
        ) ) ;

        $this->assertSame
        (
            [
                [ 'deleteUser' , [ 'obj-user' ] ] ,
                [ 'deleteUser' , [ 'arr-user' ] ] ,
                [ 'deleteUser' , [ 'n_98765' ] ] ,
            ] ,
            $enforcer->calls
        ) ;
    }

    public function testOnUserDeleteIsNoOpOnNullData() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->invoke( 'onUserDelete' , new Payload( type: 'afterDelete' , data: null ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }
}
