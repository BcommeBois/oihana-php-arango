<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;
use oihana\arango\enums\Arango;
use oihana\auth\enums\EdgeSyncType;
use oihana\auth\enums\PermissionEffect;
use oihana\signals\Signal;
use oihana\signals\notices\Payload;

use stdClass;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use tests\oihana\arango\auth\mocks\FakeDocuments;
use tests\oihana\arango\auth\mocks\FakeEdges;
use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Characterization coverage for {@see CasbinPolicySyncPolicyTrait} — the policy
 * branch of the live Casbin RBAC sync.
 *
 * Covers the two cascade-aware cleanups (`cleanupPermissionDerivedPolicies` /
 * `cleanupPolicyDerivedPolicies`), the `policy_has_permissions` propagation
 * handlers (`addPolicyPermissionPolicy` / `removePolicyPermissionPolicy`), the
 * `beforeDelete` signal wiring (`registerPermissionDelete` /
 * `registerPolicyDelete` → `onPermissionDelete` / `onPolicyDelete`), and the
 * private edge-walk helpers exercised through those public paths.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncPolicyTraitTest extends TestCase
{
    // ---- cleanupPermissionDerivedPolicies -------------------------------

    public function testCleanupPermissionIsNoOpWithoutEnforcer() :void
    {
        $sync = new MockCasbinPolicySync([ 'permissionsModel' => $this->permissions() ]) ;

        // no exception, nothing to assert beyond the no-crash contract
        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertTrue( true ) ;
    }

    public function testCleanupPermissionIsNoOpWithoutPermissionsModel() :void
    {
        $sync = new MockCasbinPolicySync([ 'enforcer' => new SpyEnforcer() ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertTrue( true ) ;
    }

    public function testCleanupPermissionReturnsWhenPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $this->permissions() , // empty → get() returns null
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'missing' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionSwallowsGetFailure() :void
    {
        $enforcer    = new SpyEnforcer() ;
        $permissions = $this->permissions() ;
        $permissions->getThrows = new \RuntimeException( 'boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $permissions ,
        ]) ;

        // must not bubble — the originating delete must not break
        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionPurgesDirectRoleUserServiceSubjects() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'article' , 'read' , PermissionEffect::ALLOW ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-editor' ) ;

        $users = $this->documents( 'users' ) ;
        $users->getResults[ 'u1' ] = $this->withIdentifier( '123' ) ; // numeric → casbinSafeSubject prefixes n_

        $services = $this->documents( 'services' ) ;

        $roleHasPermissions    = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $userHasPermissions    = $this->edges( 'user_has_permissions' ) ;
        $userHasPermissions->listResult = [ $this->edgeFrom( 'users/u1' ) ] ;

        $serviceHasPermissions = $this->edges( 'service_has_permissions' ) ;
        $serviceHasPermissions->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'              => $enforcer ,
            'domain'               => 'fallback' ,
            'permissionsModel'      => $permissions ,
            'rolesModel'            => $roles ,
            'usersModel'            => $users ,
            'servicesModel'         => $services ,
            'roleHasPermissions'    => $roleHasPermissions ,
            'userHasPermissions'    => $userHasPermissions ,
            'serviceHasPermissions' => $serviceHasPermissions ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $removed = $enforcer->callsFor( 'removePolicy' ) ;

        $this->assertSame
        (
            [
                [ 'role-editor'   , 'my-api' , 'article' , 'read' , 'allow' ] ,
                [ 'n_123'         , 'my-api' , 'article' , 'read' , 'allow' ] ,
                [ 'service:s1'    , 'my-api' , 'article' , 'read' , 'allow' ] ,
            ] ,
            $removed
        ) ;
    }

    public function testCleanupPermissionFallsBackToSyncDomainAndDefaultsWhenFieldsNonString() :void
    {
        $enforcer = new SpyEnforcer() ;

        // domain/object/action/effect are non-string → narrowing falls back
        $perm = new stdClass() ;
        $perm->domain = 42 ;
        $perm->object = [ 'x' ] ;
        $perm->action = null ;
        $perm->effect = 7 ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $perm ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-x' ) ;

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'domain'            => 'sync-domain' ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'role-x' , 'sync-domain' , '' , '' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testCleanupPermissionSkipsEdgesWithUnparsableFromOrUnresolvedSubject() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $roles = $this->documents( 'roles' ) ; // r-unknown not mapped → resolveRoleSubject null

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult =
        [
            $this->edgeFrom( null ) ,            // unparsable → skipped
            $this->edgeFrom( 'roles/r-unknown' ), // resolves to null subject → skipped
        ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionSwallowsEdgeListFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listThrows = new \RuntimeException( 'list boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $this->documents( 'roles' ) ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionPurgesIndirectPolicyMaterializedSubjects() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'doc' , 'write' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r9' ] = $this->role( 'role-indirect' ) ;

        // policy_has_permissions: permission perm1 belongs to policy p7
        $policyHasPermissions = $this->edges( 'policy_has_permissions' ) ;
        $policyHasPermissions->listResult = [ $this->edgeFrom( 'policies/p7' ) ] ;

        // service_has_policies: policy p7 attached to service s1
        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        // role_has_policies: policy p7 attached to role r9
        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r9' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'              => $enforcer ,
            'permissionsModel'      => $permissions ,
            'rolesModel'            => $roles ,
            'policyHasPermissions'  => $policyHasPermissions ,
            'serviceHasPolicies'    => $serviceHasPolicies ,
            'roleHasPolicies'       => $roleHasPolicies ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame
        (
            [
                [ 'service:s1'    , 'my-api' , 'doc' , 'write' , 'allow' ] ,
                [ 'role-indirect' , 'my-api' , 'doc' , 'write' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    // ---- cleanupPolicyDerivedPolicies -----------------------------------

    public function testCleanupPolicyIsNoOpWithoutEnforcer() :void
    {
        $sync = new MockCasbinPolicySync([ 'serviceHasPolicies' => $this->edges() ]) ;

        $sync->cleanupPolicyDerivedPolicies( 'p1' ) ;

        $this->assertTrue( true ) ;
    }

    public function testCleanupPolicyIsNoOpWithoutAnyPolicyEdges() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->cleanupPolicyDerivedPolicies( 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPolicyRemovesForAttachedServicesAndRoles() :void
    {
        $enforcer = new SpyEnforcer() ;

        // the policy carries two permissions (used by removeService/RolePolicyPolicies)
        $policy = $this->policy([
            $this->permission( 'my-api' , 'a' , 'read' ) ,
            $this->permission( 'my-api' , 'b' , 'write' ) ,
        ]) ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $policy ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-a' ) ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'policiesModel'      => $policies ,
            'rolesModel'         => $roles ,
            'serviceHasPolicies' => $serviceHasPolicies ,
            'roleHasPolicies'    => $roleHasPolicies ,
        ]) ;

        $sync->cleanupPolicyDerivedPolicies( 'p1' ) ;

        // 2 perms × (1 service + 1 role) = 4 removals
        $this->assertSame
        (
            [
                [ 'service:s1' , 'my-api' , 'a' , 'read'  , 'allow' ] ,
                [ 'service:s1' , 'my-api' , 'b' , 'write' , 'allow' ] ,
                [ 'role-a'     , 'my-api' , 'a' , 'read'  , 'allow' ] ,
                [ 'role-a'     , 'my-api' , 'b' , 'write' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testCleanupPolicySwallowsRoleEdgeListFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listThrows = new \RuntimeException( 'roles boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'        => $enforcer ,
            'policiesModel'   => $this->documents( 'policies' ) ,
            'rolesModel'      => $this->documents( 'roles' ) ,
            'roleHasPolicies' => $roleHasPolicies ,
        ]) ;

        // must not bubble
        $sync->cleanupPolicyDerivedPolicies( 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- addPolicyPermissionPolicy / removePolicyPermissionPolicy -------

    public function testAddPolicyPermissionAddsForServicesAndRoles() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'res' , 'read' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-r' ) ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'serviceHasPolicies' => $serviceHasPolicies ,
            'roleHasPolicies'    => $roleHasPolicies ,
        ]) ;

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        $this->assertSame
        (
            [
                [ 'service:s1' , 'my-api' , 'res' , 'read' , 'allow' ] ,
                [ 'role-r'     , 'my-api' , 'res' , 'read' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddPolicyPermissionSkipsRoleWithUnresolvedSubject() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $roles = $this->documents( 'roles' ) ; // r-unknown unmapped → null subject

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r-unknown' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'        => $enforcer ,
            'permissionsModel'=> $permissions ,
            'rolesModel'      => $roles ,
            'roleHasPolicies' => $roleHasPolicies ,
        ]) ;

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testRemovePolicyPermissionRemovesForServicesAndRoles() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'res' , 'del' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-r' ) ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'serviceHasPolicies' => $serviceHasPolicies ,
            'roleHasPolicies'    => $roleHasPolicies ,
        ]) ;

        $sync->invoke( 'removePolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        $this->assertSame
        (
            [
                [ 'service:s1' , 'my-api' , 'res' , 'del' , 'allow' ] ,
                [ 'role-r'     , 'my-api' , 'res' , 'del' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testPolicyPermissionContextNullWhenNoSubjectsAttached() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'serviceHasPolicies' => $this->edges( 'service_has_policies' ) , // empty
            'roleHasPolicies'    => $this->edges( 'role_has_policies' ) ,    // empty
        ]) ;

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testPolicyPermissionContextNullWhenPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $this->permissions() , // perm absent
            'serviceHasPolicies' => $serviceHasPolicies ,
        ]) ;

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'missing' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- registerPermissionDelete / onPermissionDelete ------------------

    public function testRegisterPermissionDeleteIsNoOpWithoutEnforcer() :void
    {
        $permissions = $this->permissions() ;
        $permissions->beforeDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'permissionsModel' => $permissions ]) ;
        $sync->registerPermissionDelete( $permissions ) ;

        // nothing connected → emitting does nothing / does not crash
        $permissions->beforeDelete->emit( new Payload( type: 'beforeDelete' , context: [ Arango::VALUE => 'perm1' ] ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterPermissionDeleteWiresBeforeDeleteToCleanup() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'read' ) ;
        $permissions->beforeDelete = new Signal() ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-z' ) ;

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        $sync->registerPermissionDelete( $permissions ) ;

        // context carries the keys to purge (scalar form)
        $permissions->beforeDelete->emit( new Payload( type: 'beforeDelete' , context: [ Arango::VALUE => 'perm1' ] ) ) ;

        $this->assertSame
        (
            [ [ 'role-z' , 'my-api' , 'x' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testOnPermissionDeleteHandlesArrayKeysAndSkipsFalsy() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o1' , 'a' ) ;
        $permissions->getResults[ 'perm2' ] = $this->permission( 'd' , 'o2' , 'a' ) ;

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-r' ) ;

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResolver = fn() => [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        $sync->invoke( 'onPermissionDelete' , new Payload
        (
            type: 'beforeDelete' ,
            context: [ Arango::VALUE => [ 'perm1' , '' , null , 'perm2' ] ]
        ) ) ;

        $this->assertSame
        (
            [
                [ 'role-r' , 'd' , 'o1' , 'a' , 'allow' ] ,
                [ 'role-r' , 'd' , 'o2' , 'a' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testOnPermissionDeleteSwallowsCleanupFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        // role resolution throws (rolesModel->get) → bubbles out of the
        // edge walk, up to onPermissionDelete's per-key catch.
        $roles = $this->documents( 'roles' ) ;
        $roles->getThrows = new \RuntimeException( 'role boom' ) ;

        $roleHasPermissions = $this->edges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult = [ $this->edgeFrom( 'roles/r1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'roleHasPermissions' => $roleHasPermissions ,
        ]) ;

        // must not bubble — caught + logged per key
        $sync->invoke( 'onPermissionDelete' , new Payload
        (
            type: 'beforeDelete' ,
            context: [ Arango::VALUE => 'perm1' ]
        ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- registerPolicyDelete / onPolicyDelete --------------------------

    public function testRegisterPolicyDeleteIsNoOpWithoutEnforcer() :void
    {
        $policies = $this->documents( 'policies' ) ;
        $policies->beforeDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'policiesModel' => $policies ]) ;
        $sync->registerPolicyDelete( $policies ) ;

        $policies->beforeDelete->emit( new Payload( type: 'beforeDelete' , context: [ Arango::VALUE => 'p1' ] ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterPolicyDeleteWiresBeforeDeleteToCleanup() :void
    {
        $enforcer = new SpyEnforcer() ;

        $policy   = $this->policy([ $this->permission( 'my-api' , 'a' , 'read' ) ]) ;
        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $policy ;
        $policies->beforeDelete = new Signal() ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'policiesModel'      => $policies ,
            'serviceHasPolicies' => $serviceHasPolicies ,
        ]) ;

        $sync->registerPolicyDelete( $policies ) ;

        $policies->beforeDelete->emit( new Payload( type: 'beforeDelete' , context: [ Arango::VALUE => [ 'p1' ] ] ) ) ;

        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'a' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testOnPolicyDeleteSkipsFalsyKeys() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'policiesModel'      => $this->documents( 'policies' ) ,
            'serviceHasPolicies' => $this->edges( 'service_has_policies' ) ,
        ]) ;

        $sync->invoke( 'onPolicyDelete' , new Payload
        (
            type: 'beforeDelete' ,
            context: [ Arango::VALUE => [ '' , null ] ]
        ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testOnPolicyDeleteSwallowsCleanupFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        // policiesModel->get throws inside removeServicePolicyPolicies →
        // bubbles up to onPolicyDelete's per-key catch.
        $policies = $this->documents( 'policies' ) ;
        $policies->getThrows = new \RuntimeException( 'policy boom' ) ;

        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'policiesModel'      => $policies ,
            'serviceHasPolicies' => $serviceHasPolicies ,
        ]) ;

        $sync->invoke( 'onPolicyDelete' , new Payload
        (
            type: 'beforeDelete' ,
            context: [ Arango::VALUE => 'p1' ]
        ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- remove/load context edge branches ------------------------------

    public function testRemovePolicyPermissionReturnsWhenContextNull() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $this->permissions() , // perm absent → ctx null
            'serviceHasPolicies' => $this->edges( 'service_has_policies' ) ,
        ]) ;

        $sync->invoke( 'removePolicyPermissionPolicy' , 'p1' , 'missing' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testRemovePolicyPermissionSkipsRoleWithUnresolvedSubject() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'r' , 'd' ) ;

        $roles = $this->documents( 'roles' ) ; // r-unknown unmapped → null subject

        // a service keeps the context non-null so the role loop is reached
        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResult = [ $this->edgeFrom( 'roles/r-unknown' ) ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'rolesModel'         => $roles ,
            'serviceHasPolicies' => $serviceHasPolicies ,
            'roleHasPolicies'    => $roleHasPolicies ,
        ]) ;

        $sync->invoke( 'removePolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        // only the service row is removed; the unresolved role is skipped
        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'r' , 'd' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testLoadContextReturnsNullWithoutPermissionsModel() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ; // no permissionsModel

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- purgePoliciesViaPolicy edge branches ---------------------------

    public function testCleanupPermissionSwallowsPolicyHasPermissionsListFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $policyHasPermissions = $this->edges( 'policy_has_permissions' ) ;
        $policyHasPermissions->listThrows = new \RuntimeException( 'pol boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'             => $enforcer ,
            'permissionsModel'     => $permissions ,
            'policyHasPermissions' => $policyHasPermissions ,
            'roleHasPolicies'      => $this->edges( 'role_has_policies' ) ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionSkipsPolicyEdgeWithUnparsableFrom() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        $policyHasPermissions = $this->edges( 'policy_has_permissions' ) ;
        $policyHasPermissions->listResult = [ $this->edgeFrom( null ) ] ; // unparsable → skipped

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'             => $enforcer ,
            'permissionsModel'     => $permissions ,
            'policyHasPermissions' => $policyHasPermissions ,
            'roleHasPolicies'      => $this->edges( 'role_has_policies' ) ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testCleanupPermissionViaPolicySwallowsRoleListFailureAndSkipsBadRoles() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'd' , 'o' , 'a' ) ;

        // perm1 lives on three policies — one whose role listing throws,
        // one with an unparsable role _from, one with an unresolved role.
        $policyHasPermissions = $this->edges( 'policy_has_permissions' ) ;
        $policyHasPermissions->listResult =
        [
            $this->edgeFrom( 'policies/pThrow' ) ,
            $this->edgeFrom( 'policies/pBadKey' ) ,
            $this->edgeFrom( 'policies/pUnresolved' ) ,
        ] ;

        $roles = $this->documents( 'roles' ) ; // rUnknown unmapped

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listResolver = function( array $init )
        {
            $policyId = $init[ Arango::BINDS ][ 'policyId' ] ?? '' ;

            return match( $policyId )
            {
                'policies/pThrow'  => throw new \RuntimeException( 'roles boom' ) ,
                'policies/pBadKey' => [ $this->edgeFrom( null ) ] ,            // parseKey null → skip
                default            => [ $this->edgeFrom( 'roles/rUnknown' ) ], // null subject → skip
            } ;
        } ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'             => $enforcer ,
            'permissionsModel'     => $permissions ,
            'rolesModel'           => $roles ,
            'policyHasPermissions' => $policyHasPermissions ,
            'roleHasPolicies'      => $roleHasPolicies ,
        ]) ;

        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        // every role branch was skipped/swallowed → no enforcer write
        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- resolveRolesForPolicy (via loadPolicyPermissionContext) --------

    public function testAddPolicyPermissionSwallowsRolesForPolicyListFailure() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->permissions() ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'r' , 'read' ) ;

        // a service keeps the context non-null while the role listing throws
        // inside resolveRolesForPolicy → caught, role set becomes empty.
        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult = [ $this->edgeFrom( 'services/s1' ) ] ;

        $roleHasPolicies = $this->edges( 'role_has_policies' ) ;
        $roleHasPolicies->listThrows = new \RuntimeException( 'roles boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => $enforcer ,
            'permissionsModel'   => $permissions ,
            'serviceHasPolicies' => $serviceHasPolicies ,
            'roleHasPolicies'    => $roleHasPolicies ,
        ]) ;

        $sync->invoke( 'addPolicyPermissionPolicy' , 'p1' , 'perm1' ) ;

        // only the service add survives; the failed role listing is swallowed
        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'r' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    // ---- helpers --------------------------------------------------------

    private function documents( string $collection ) :FakeDocuments
    {
        return new FakeDocuments( $collection ) ;
    }

    private function edgeFrom( ?string $from ) :stdClass
    {
        $edge = new stdClass() ;
        $edge->_from = $from ;
        return $edge ;
    }

    private function edges( string $collection = 'role_has_permissions' ) :FakeEdges
    {
        return new FakeEdges( $collection ) ;
    }

    private function permission( string $domain , string $object , string $action , ?string $effect = null ) :stdClass
    {
        $perm = new stdClass() ;
        $perm->domain = $domain ;
        $perm->object = $object ;
        $perm->action = $action ;

        if( $effect !== null )
        {
            $perm->effect = $effect ;
        }

        return $perm ;
    }

    private function permissions() :FakeDocuments
    {
        return new FakeDocuments( 'permissions' ) ;
    }

    private function policy( array $permissions ) :stdClass
    {
        $policy = new stdClass() ;
        $policy->permissions = $permissions ;
        return $policy ;
    }

    private function role( string $identifier , ?string $name = null ) :stdClass
    {
        $role = new stdClass() ;
        $role->identifier = $identifier ;

        if( $name !== null )
        {
            $role->name = $name ;
        }

        return $role ;
    }

    private function withIdentifier( string $identifier ) :stdClass
    {
        $doc = new stdClass() ;
        $doc->identifier = $identifier ;
        return $doc ;
    }
}
