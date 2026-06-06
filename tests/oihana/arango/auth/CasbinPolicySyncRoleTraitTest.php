<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;
use oihana\auth\enums\PermissionEffect;
use oihana\signals\Signal;
use oihana\signals\notices\Payload;

use stdClass;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Characterization coverage for {@see CasbinPolicySyncRoleTrait} — the role
 * branch of the live Casbin RBAC sync.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncRoleTraitTest extends CasbinSyncTestCase
{
    // ---- addRolePermissionPolicy ----------------------------------------

    public function testAddRolePermissionAddsPolicyKeyedOnRoleIdentifier() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' , 'editor' ) ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'article' , 'read' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'domain'           => 'fallback' ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'addRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'role-id' , 'my-api' , 'article' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddRolePermissionUsesSyncDomainAndAllowDefault() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        // permission lacks domain + effect → fall back to sync domain + ALLOW
        $perm = new stdClass() ;
        $perm->object = 'o' ;
        $perm->action = 'a' ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $perm ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'domain'           => 'sync-domain' ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'addRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'role-id' , 'sync-domain' , 'o' , 'a' , PermissionEffect::ALLOW ] ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddRolePermissionIsNoOpWhenRoleOrPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'rolesModel'       => $this->documents( 'roles' ) ,       // r1 unmapped → null subject
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;

        $sync->invoke( 'addRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        // role resolves to a value but permission missing also short-circuits
        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $this->documents( 'permissions' ) , // perm missing
        ]) ;

        $sync2->invoke( 'addRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- addRolePolicyPolicies ------------------------------------------

    public function testAddRolePolicyAddsOnePolicyPerPermission() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy
        ([
            $this->permission( 'my-api' , 'a' , 'read' ) ,
            $this->permission( 'my-api' , 'b' , 'write' ) ,
        ]) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $roles ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'addRolePolicyPolicies' , 'r1' , 'p1' ) ;

        $this->assertSame
        (
            [
                [ 'role-id' , 'my-api' , 'a' , 'read'  , 'allow' ] ,
                [ 'role-id' , 'my-api' , 'b' , 'write' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddRolePolicyIsNoOpWhenRoleUnresolved() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $this->documents( 'roles' ) , // unmapped
            'policiesModel' => $this->documents( 'policies' ) ,
        ]) ;

        $sync->invoke( 'addRolePolicyPolicies' , 'r1' , 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testAddRolePolicyIsNoOpWhenPolicyMissingOrEmpty() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        // policy with no permissions
        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy( [] ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $roles ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'addRolePolicyPolicies' , 'r1' , 'p1' ) ; // empty perms
        $sync->invoke( 'addRolePolicyPolicies' , 'r1' , 'absent' ) ; // policy missing

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- removeRolePermissionPolicy / removeRolePolicyPolicies ----------

    public function testRemoveRolePermissionRemovesPolicy() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'del' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'removeRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'role-id' , 'my-api' , 'x' , 'del' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testRemoveRolePermissionIsNoOpWhenUnresolved() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'rolesModel'       => $this->documents( 'roles' ) , // unmapped
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;

        $sync->invoke( 'removeRolePermissionPolicy' , 'r1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testRemoveRolePolicyRemovesOnePerPermission() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy
        ([
            $this->permission( 'my-api' , 'a' , 'read' ) ,
        ]) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $roles ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'removeRolePolicyPolicies' , 'r1' , 'p1' ) ;

        $this->assertSame
        (
            [ [ 'role-id' , 'my-api' , 'a' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testRemoveRolePolicyIsNoOpWhenRoleUnresolvedOrPolicyEmpty() :void
    {
        $enforcer = new SpyEnforcer() ;

        // unresolved role
        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $this->documents( 'roles' ) ,
            'policiesModel' => $this->documents( 'policies' ) ,
        ]) ;

        $sync->invoke( 'removeRolePolicyPolicies' , 'r1' , 'p1' ) ;

        // resolved role but empty policy
        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy( [] ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'rolesModel'    => $roles ,
            'policiesModel' => $policies ,
        ]) ;

        $sync2->invoke( 'removeRolePolicyPolicies' , 'r1' , 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- registerRoleDelete / onRoleDelete ------------------------------

    public function testRegisterRoleDeleteIsNoOpWithoutEnforcer() :void
    {
        $roles = $this->documents( 'roles' ) ;
        $roles->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'rolesModel' => $roles ]) ;
        $sync->registerRoleDelete( $roles ) ;

        $roles->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->role( 'x' ) ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterRoleDeleteWiresAfterDeleteToDeleteRole() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer , 'rolesModel' => $roles ]) ;
        $sync->registerRoleDelete( $roles ) ;

        $roles->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->role( 'role-del' ) ) ) ;

        $this->assertSame( [ [ 'deleteRole' , [ 'role-del' ] ] ] , $enforcer->calls ) ;
    }

    public function testOnRoleDeleteUsesIdentifierThenNameFallbackAndSkipsKeyless() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        // legacy role: no identifier → falls back to name
        $legacy = new stdClass() ;
        $legacy->name = 'legacy-role' ;

        // array-shaped role with identifier
        $arrayRole = [ 'identifier' => 'arr-role' ] ;

        // numeric identifier → casbinSafeSubject prefixes n_
        $numeric = $this->role( '12345' ) ;

        // keyless → skipped
        $keyless = new stdClass() ;

        $sync->invoke( 'onRoleDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: [ $this->role( 'id-role' ) , $legacy , $arrayRole , $numeric , $keyless ]
        ) ) ;

        $this->assertSame
        (
            [
                [ 'deleteRole' , [ 'id-role' ] ] ,
                [ 'deleteRole' , [ 'legacy-role' ] ] ,
                [ 'deleteRole' , [ 'arr-role' ] ] ,
                [ 'deleteRole' , [ 'n_12345' ] ] ,
            ] ,
            $enforcer->calls
        ) ;
    }

    public function testOnRoleDeleteIsNoOpOnNullData() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->invoke( 'onRoleDelete' , new Payload( type: 'afterDelete' , data: null ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }
}
