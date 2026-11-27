<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\CasbinPolicySync;

use PHPUnit\Framework\Attributes\CoversClass;

use stdClass;

use tests\oihana\arango\auth\mocks\MockCasbinPolicySync;
use tests\oihana\arango\auth\mocks\SpyEnforcer;

/**
 * Coverage for {@see \oihana\arango\auth\traits\CasbinPolicySyncRuleTrait} : the rule a
 * permission writes, read the same way by every handler.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversClass( CasbinPolicySync::class )]
class CasbinPolicySyncRuleTraitTest extends CasbinSyncTestCase
{
    public function testAnAbsentAttributeTakesTheDefaultOfTheSync() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $this->assertSame( [ 'my-api' , '' , '' , 'allow' ] , $sync->invoke( 'ruleOf' , new stdClass() ) ) ;
    }

    public function testANonStringAttributeTakesTheDefaultOfTheSync() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $permission         = new stdClass() ;
        $permission->domain = [ 'not' , 'a' , 'string' ] ;
        $permission->object = 42 ;
        $permission->action = 'read' ;
        $permission->effect = true ;

        $this->assertSame( [ 'my-api' , '' , 'read' , 'allow' ] , $sync->invoke( 'ruleOf' , $permission ) ) ;
    }

    public function testAWellFormedPermissionIsReadAsItIs() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $this->assertSame( [ 'other-api' , '/things' , 'GET' , 'deny' ] , $sync->invoke( 'ruleOf' , $this->permission( 'other-api' , '/things' , 'GET' , 'deny' ) ) ) ;
    }

    public function testEveryHandlerWritesTheSameRule() :void
    {
        $enforcer   = new SpyEnforcer() ;
        $permission = new stdClass() ;

        $permission->object = '/things' ;
        $permission->action = 'GET' ;
        $permission->effect = 7 ; // malformed : the default effect is written

        $roles = $this->documents( 'roles' ) ;
        $roles->getResults[ 'r1' ] = $this->role( 'role-id' ) ;

        $sync = new MockCasbinPolicySync
        ([
            'enforcer'         => $enforcer ,
            'domain'           => 'my-api' ,
            'rolesModel'       => $roles ,
            'permissionsModel' => $this->permissionsByKey([ 'k1' => $permission ]) ,
        ]) ;

        $sync->invoke( 'addRolePermissionPolicy'    , 'r1' , 'k1' ) ;
        $sync->invoke( 'removeRolePermissionPolicy' , 'r1' , 'k1' ) ;

        $this->assertSame( [ [ 'role-id' , 'my-api' , '/things' , 'GET' , 'allow' ] ] , $enforcer->callsFor( 'addPolicy'    ) ) ;
        $this->assertSame( [ [ 'role-id' , 'my-api' , '/things' , 'GET' , 'allow' ] ] , $enforcer->callsFor( 'removePolicy' ) ) ;
    }

    public function testTheRuleKeyIsTheRuleOnOneLinePerPart() :void
    {
        $sync = new MockCasbinPolicySync([ 'domain' => 'my-api' ]) ;

        $this->assertSame( "my-api\n/things\nGET\nallow" , $sync->invoke( 'ruleKey' , $this->permission( 'my-api' , '/things' , 'GET' ) ) ) ;
    }
}
