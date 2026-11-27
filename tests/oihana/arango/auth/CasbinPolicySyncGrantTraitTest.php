<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;

use PHPUnit\Framework\Attributes\CoversClass;

use RuntimeException;

use stdClass;

use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyLogger;

/**
 * Coverage for {@see \oihana\arango\auth\traits\CasbinPolicySyncGrantTrait} : the rules
 * a role or a service still holds through the graph.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncGrantTraitTest extends CasbinSyncTestCase
{
    public function testARoleHoldsTheRulesOfItsDirectPermissions() :void
    {
        $sync = new MockCasbinPolicySync
        ([
            'domain'             => 'my-api' ,
            'permissionsModel'   => $this->permissionsByKey([ 'k1' => $this->permission( 'my-api' , 'a' , 'read' ) ]) ,
            'roleHasPermissions' => $this->edgesFrom( 'role_has_permissions' , [ 'roles/r1' => [ 'permissions/k1' ] ] ) ,
        ]) ;

        $held = $sync->invoke( 'roleHeldRules' , 'r1' ) ;

        $this->assertSame( [ $sync->invoke( 'ruleKey' , $this->permission( 'my-api' , 'a' , 'read' ) ) => true ] , $held ) ;
    }

    public function testARoleHoldsTheRulesOfItsPolicies() :void
    {
        $sync = new MockCasbinPolicySync
        ([
            'domain'               => 'my-api' ,
            'permissionsModel'     => $this->permissionsByKey([ 'k1' => $this->permission( 'my-api' , 'a' , 'read' ) ]) ,
            'roleHasPolicies'      => $this->edgesFrom( 'role_has_policies'      , [ 'roles/r1'    => [ 'policies/p1'    ] ] ) ,
            'policyHasPermissions' => $this->edgesFrom( 'policy_has_permissions' , [ 'policies/p1' => [ 'permissions/k1' ] ] ) ,
        ]) ;

        $this->assertArrayHasKey( $sync->invoke( 'ruleKey' , $this->permission( 'my-api' , 'a' , 'read' ) ) , $sync->invoke( 'roleHeldRules' , 'r1' ) ) ;
    }

    public function testAServiceHoldsTheRulesOfItsDirectPermissionsAndOfItsPolicies() :void
    {
        $sync = new MockCasbinPolicySync
        ([
            'domain'                => 'my-api' ,
            'permissionsModel'      => $this->permissionsByKey
            ([
                'k1' => $this->permission( 'my-api' , 'a' , 'read' ) ,
                'k2' => $this->permission( 'my-api' , 'b' , 'read' ) ,
            ]) ,
            'serviceHasPermissions' => $this->edgesFrom( 'service_has_permissions' , [ 'services/s1' => [ 'permissions/k1' ] ] ) ,
            'serviceHasPolicies'    => $this->edgesFrom( 'service_has_policies'    , [ 'services/s1' => [ 'policies/p1'    ] ] ) ,
            'policyHasPermissions'  => $this->edgesFrom( 'policy_has_permissions'  , [ 'policies/p1' => [ 'permissions/k2' ] ] ) ,
        ]) ;

        $this->assertCount( 2 , $sync->invoke( 'serviceHeldRules' , 's1' ) ) ;
    }

    public function testAnIgnoredPolicyDoesNotCount() :void
    {
        $sync = new MockCasbinPolicySync
        ([
            'domain'               => 'my-api' ,
            'permissionsModel'     => $this->permissionsByKey([ 'k1' => $this->permission( 'my-api' , 'a' , 'read' ) ]) ,
            'roleHasPolicies'      => $this->edgesFrom( 'role_has_policies'      , [ 'roles/r1'    => [ 'policies/p1'    ] ] ) ,
            'policyHasPermissions' => $this->edgesFrom( 'policy_has_permissions' , [ 'policies/p1' => [ 'permissions/k1' ] ] ) ,
        ]) ;

        $this->assertSame( [] , $sync->invoke( 'roleHeldRules' , 'r1' , 'p1' ) ) ;
    }

    public function testAnUnreadableGraphReturnsNullAndLogsAWarning() :void
    {
        $logger = new SpyLogger() ;
        $edges  = $this->edges( 'role_has_permissions' ) ;

        $edges->listThrows = new RuntimeException( 'connection lost' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'logger'             => $logger ,
            'permissionsModel'   => $this->permissionsByKey( [] ) ,
            'roleHasPermissions' => $edges ,
        ]) ;

        $this->assertNull( $sync->invoke( 'roleHeldRules' , 'r1' ) ) ;

        $warnings = $logger->messages( 'warning' ) ;

        $this->assertCount( 1 , $warnings ) ;
        $this->assertStringContainsString( "cannot read what 'roles/r1' still holds" , $warnings[ 0 ] ) ;
        $this->assertStringContainsString( 'connection lost' , $warnings[ 0 ] ) ;
    }

    public function testTheRuleKeyTellsTwoEffectsAndTwoDomainsApart() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $allow = $sync->invoke( 'ruleKey' , $this->permission( 'my-api'    , 'a' , 'read' , 'allow' ) ) ;
        $deny  = $sync->invoke( 'ruleKey' , $this->permission( 'my-api'    , 'a' , 'read' , 'deny'  ) ) ;
        $other = $sync->invoke( 'ruleKey' , $this->permission( 'other-api' , 'a' , 'read' , 'allow' ) ) ;

        $this->assertNotSame( $allow , $deny  ) ;
        $this->assertNotSame( $allow , $other ) ;
    }

    public function testTheRuleKeyUsesTheDefaultsTheSyncWrites() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $bare = new stdClass() ;
        $bare->object = 'a' ;
        $bare->action = 'read' ;

        $this->assertSame( $sync->invoke( 'ruleKey' , $this->permission( 'my-api' , 'a' , 'read' , 'allow' ) ) , $sync->invoke( 'ruleKey' , $bare ) ) ;
    }

    public function testWithoutAPermissionsModelNothingIsHeld() :void
    {
        $sync = new MockCasbinPolicySync
        ([
            'roleHasPermissions' => $this->edgesFrom( 'role_has_permissions' , [ 'roles/r1' => [ 'permissions/k1' ] ] ) ,
        ]) ;

        $this->assertSame( [] , $sync->invoke( 'roleHeldRules' , 'r1' ) ) ;
    }
}
