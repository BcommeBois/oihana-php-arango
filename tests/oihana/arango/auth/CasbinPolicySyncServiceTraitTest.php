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
 * Characterization coverage for {@see CasbinPolicySyncServiceTrait} — the M2M
 * service-account branch of the live Casbin RBAC sync. Service subjects are
 * namespaced `service:{_key}`.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncServiceTraitTest extends CasbinSyncTestCase
{
    // ---- addServicePermissionPolicy -------------------------------------

    public function testAddServicePermissionAddsNamespacedPolicy() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'read' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'addServicePermissionPolicy' , 's1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'x' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddServicePermissionIsNoOpWithoutEnforcerOrModel() :void
    {
        // no enforcer
        $sync = new MockCasbinPolicySync([ 'permissionsModel' => $this->documents( 'permissions' ) ]) ;
        $sync->invoke( 'addServicePermissionPolicy' , 's1' , 'perm1' ) ;

        // enforcer but no permissions model
        $enforcer = new SpyEnforcer() ;
        $sync2 = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;
        $sync2->invoke( 'addServicePermissionPolicy' , 's1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testAddServicePermissionIsNoOpWhenPermissionMissing() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $this->documents( 'permissions' ) , // perm missing
        ]) ;

        $sync->invoke( 'addServicePermissionPolicy' , 's1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- addServicePolicyPolicies ---------------------------------------

    public function testAddServicePolicyAddsOnePerPermission() :void
    {
        $enforcer = new SpyEnforcer() ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy
        ([
            $this->permission( 'my-api' , 'a' , 'read' ) ,
            $this->permission( 'my-api' , 'b' , 'write' ) ,
        ]) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'addServicePolicyPolicies' , 's1' , 'p1' ) ;

        $this->assertSame
        (
            [
                [ 'service:s1' , 'my-api' , 'a' , 'read'  , 'allow' ] ,
                [ 'service:s1' , 'my-api' , 'b' , 'write' , 'allow' ] ,
            ] ,
            $enforcer->callsFor( 'addPolicy' )
        ) ;
    }

    public function testAddServicePolicyIsNoOpWithoutEnforcerOrModel() :void
    {
        $sync = new MockCasbinPolicySync([ 'policiesModel' => $this->documents( 'policies' ) ]) ;
        $sync->invoke( 'addServicePolicyPolicies' , 's1' , 'p1' ) ;

        $enforcer = new SpyEnforcer() ;
        $sync2 = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;
        $sync2->invoke( 'addServicePolicyPolicies' , 's1' , 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testAddServicePolicyIsNoOpWhenPolicyMissingOrEmpty() :void
    {
        $enforcer = new SpyEnforcer() ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy( [] ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'addServicePolicyPolicies' , 's1' , 'p1' ) ;       // empty perms
        $sync->invoke( 'addServicePolicyPolicies' , 's1' , 'absent' ) ;   // missing policy

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- removeServicePermissionPolicy / removeServicePolicyPolicies ----

    public function testRemoveServicePermissionRemovesPolicy() :void
    {
        $enforcer = new SpyEnforcer() ;

        $permissions = $this->documents( 'permissions' ) ;
        $permissions->getResults[ 'perm1' ] = $this->permission( 'my-api' , 'x' , 'del' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $permissions ,
        ]) ;

        $sync->invoke( 'removeServicePermissionPolicy' , 's1' , 'perm1' ) ;

        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'x' , 'del' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testRemoveServicePermissionIsNoOpWithoutEnforcerModelOrPermission() :void
    {
        // no enforcer
        $sync = new MockCasbinPolicySync([ 'permissionsModel' => $this->documents( 'permissions' ) ]) ;
        $sync->invoke( 'removeServicePermissionPolicy' , 's1' , 'perm1' ) ;

        // enforcer but permission missing
        $enforcer = new SpyEnforcer() ;
        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'permissionsModel' => $this->documents( 'permissions' ) ,
        ]) ;
        $sync2->invoke( 'removeServicePermissionPolicy' , 's1' , 'perm1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    public function testRemoveServicePolicyRemovesOnePerPermission() :void
    {
        $enforcer = new SpyEnforcer() ;

        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy
        ([
            $this->permission( 'my-api' , 'a' , 'read' ) ,
        ]) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'policiesModel' => $policies ,
        ]) ;

        $sync->invoke( 'removeServicePolicyPolicies' , 's1' , 'p1' ) ;

        $this->assertSame
        (
            [ [ 'service:s1' , 'my-api' , 'a' , 'read' , 'allow' ] ] ,
            $enforcer->callsFor( 'removePolicy' )
        ) ;
    }

    public function testRemoveServicePolicyIsNoOpWithoutEnforcerModelOrEmptyPolicy() :void
    {
        // no enforcer
        $sync = new MockCasbinPolicySync([ 'policiesModel' => $this->documents( 'policies' ) ]) ;
        $sync->invoke( 'removeServicePolicyPolicies' , 's1' , 'p1' ) ;

        // enforcer but empty policy
        $enforcer = new SpyEnforcer() ;
        $policies = $this->documents( 'policies' ) ;
        $policies->getResults[ 'p1' ] = $this->policy( [] ) ;

        $sync2 = new MockCasbinPolicySync
        ([
            'enforcer'      => $enforcer ,
            'policiesModel' => $policies ,
        ]) ;
        $sync2->invoke( 'removeServicePolicyPolicies' , 's1' , 'p1' ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }

    // ---- resolveServicesForPolicy ---------------------------------------

    public function testResolveServicesForPolicyReturnsEmptyWithoutEdge() :void
    {
        $sync = new MockCasbinPolicySync([ 'enforcer' => new SpyEnforcer() ]) ;

        $this->assertSame( [] , $sync->invoke( 'resolveServicesForPolicy' , 'p1' ) ) ;
    }

    public function testResolveServicesForPolicySwallowsListFailure() :void
    {
        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listThrows = new \RuntimeException( 'boom' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => new SpyEnforcer() ,
            'serviceHasPolicies' => $serviceHasPolicies ,
        ]) ;

        $this->assertSame( [] , $sync->invoke( 'resolveServicesForPolicy' , 'p1' ) ) ;
    }

    public function testResolveServicesForPolicyDeduplicatesAndSkipsUnparsable() :void
    {
        $serviceHasPolicies = $this->edges( 'service_has_policies' ) ;
        $serviceHasPolicies->listResult =
        [
            $this->edge( 'services/s1' ) ,
            $this->edge( null ) ,           // skipped
            $this->edge( 'services/s1' ) ,  // duplicate collapsed
            $this->edge( 'services/s2' ) ,
        ] ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'           => new SpyEnforcer() ,
            'serviceHasPolicies' => $serviceHasPolicies ,
        ]) ;

        $this->assertSame( [ 's1' , 's2' ] , $sync->invoke( 'resolveServicesForPolicy' , 'p1' ) ) ;
    }

    // ---- registerServiceDelete / onServiceDelete ------------------------

    public function testRegisterServiceDeleteIsNoOpWithoutEnforcer() :void
    {
        $services = $this->documents( 'services' ) ;
        $services->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'servicesModel' => $services ]) ;
        $sync->registerServiceDelete( $services ) ;

        $services->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->withKey( 's1' ) ) ) ;

        $this->assertTrue( true ) ;
    }

    public function testRegisterServiceDeleteWiresAfterDeleteToDeleteUser() :void
    {
        $enforcer = new SpyEnforcer() ;

        $services = $this->documents( 'services' ) ;
        $services->afterDelete = new Signal() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer , 'servicesModel' => $services ]) ;
        $sync->registerServiceDelete( $services ) ;

        $services->afterDelete->emit( new Payload( type: 'afterDelete' , data: $this->withKey( 's-del' ) ) ) ;

        $this->assertSame( [ [ 'deleteUser' , [ 'service:s-del' ] ] ] , $enforcer->calls ) ;
    }

    public function testOnServiceDeleteDeletesNamespacedSubjectAndSkipsKeyless() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $arrayService = [ '_key' => 'arr-svc' ] ;
        $keyless      = new stdClass() ;

        $sync->invoke( 'onServiceDelete' , new Payload
        (
            type: 'afterDelete' ,
            data: [ $this->withKey( 'obj-svc' ) , $arrayService , $keyless ]
        ) ) ;

        $this->assertSame
        (
            [
                [ 'deleteUser' , [ 'service:obj-svc' ] ] ,
                [ 'deleteUser' , [ 'service:arr-svc' ] ] ,
            ] ,
            $enforcer->calls
        ) ;
    }

    public function testOnServiceDeleteIsNoOpOnNullData() :void
    {
        $enforcer = new SpyEnforcer() ;

        $sync = new MockCasbinPolicySync([ 'enforcer' => $enforcer ]) ;

        $sync->invoke( 'onServiceDelete' , new Payload( type: 'afterDelete' , data: null ) ) ;

        $this->assertSame( [] , $enforcer->calls ) ;
    }
}
