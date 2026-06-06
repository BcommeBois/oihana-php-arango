<?php

namespace tests\oihana\arango\auth;

use DI\Container;

use oihana\arango\auth\CasbinPolicySync;

use PHPUnit\Framework\Attributes\CoversClass;

use tests\oihana\arango\auth\mocks\FakeDocuments;
use tests\oihana\arango\auth\mocks\FakeEdges;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Coverage for the {@see CasbinPolicySync} coordinator constructor — the
 * `$init` / `$container` wiring chain that resolves the Enforcer + every
 * Arango model / edge collection the per-domain sync traits rely on.
 *
 * Builds the real class (no DI container) by passing concrete doubles in the
 * `$init` array, then drives a public cleanup path to prove the dependencies
 * were wired through onto the trait handlers.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncTest extends CasbinSyncTestCase
{
    public function testConstructorWiresEnforcerModelsAndEdgesFromInit() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = new FakeDocuments( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'article' , 'read' ) ;

        $roles = new FakeDocuments( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $roleHasPermissions = new FakeEdges( 'role_has_permissions' ) ;
        $roleHasPermissions->listResult = [ $this->edge( 'roles/r1' , 'permissions/perm1' ) ] ;

        // the enforcer is resolved by service name through the container
        // (resolveDependency only accepts a ?string id), every Arango model
        // / edge is passed as a direct instance in $init.
        $container = new Container() ;
        $container->set( 'enforcer.service' , $enforcer ) ;

        $sync = new CasbinPolicySync
        (
            [
                CasbinPolicySync::DOMAIN  => 'my-api' ,
                'enforcer'                => 'enforcer.service' ,
                'logger'                  => null ,
                'rolesModel'              => $roles ,
                'permissionsModel'        => $permissions ,
                'policiesModel'           => new FakeDocuments( 'policies' ) ,
                'servicesModel'           => new FakeDocuments( 'services' ) ,
                'usersModel'              => new FakeDocuments( 'users' ) ,
                'roleHasPermissions'      => $roleHasPermissions ,
                'roleHasPolicies'         => new FakeEdges( 'role_has_policies' ) ,
                'policyHasPermissions'    => new FakeEdges( 'policy_has_permissions' ) ,
                'userHasPermissions'      => new FakeEdges( 'user_has_permissions' ) ,
                'serviceHasPermissions'   => new FakeEdges( 'service_has_permissions' ) ,
                'serviceHasPolicies'      => new FakeEdges( 'service_has_policies' ) ,
            ] ,
            $container
        ) ;

        // the constructor must have wired permissionsModel + rolesModel +
        // roleHasPermissions + enforcer onto the policy-trait cleanup path.
        $sync->cleanupPermissionDerivedPolicies( 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'role-id' , 'my-api' , 'article' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testConstructorToleratesEmptyInit() :void
    {
        // empty init → enforcer + every model stay null, domain defaults to ''
        $sync = new CasbinPolicySync( [] , null ) ;

        // no exception, nullable deps stay null → public cleanup is a no-op
        $sync->cleanupPermissionDerivedPolicies( 'whatever' ) ;

        $this->assertInstanceOf( CasbinPolicySync::class , $sync ) ;
    }
}
