<?php

namespace tests\oihana\arango\auth;

use DI\Container;

use oihana\arango\auth\CasbinPolicySync;
use oihana\arango\auth\UserMaxLevelResolver;
use oihana\arango\auth\traits\CasbinSyncTrait;
use oihana\arango\auth\traits\UserMaxLevelResolverTrait;
use oihana\arango\auth\traits\edges\UserHasRolesTrait;
use oihana\arango\auth\traits\models\ApisModelTrait;
use oihana\arango\auth\traits\models\AuditLogsModelTrait;
use oihana\arango\auth\traits\models\InvitationsModelTrait;
use oihana\arango\auth\traits\models\PasswordResetsModelTrait;
use oihana\arango\auth\traits\models\SessionsModelTrait;

use oihana\arango\models\Documents;
use oihana\arango\models\Edges;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

class ApisModelInitHost
{
    use ApisModelTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeApisModel( $init , $container ) ; }
    public function model() :?Documents { return $this->apisModel ; }
}

class AuditLogsModelInitHost
{
    use AuditLogsModelTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeAuditLogsModel( $init , $container ) ; }
    public function model() :?Documents { return $this->auditLogsModel ; }
}

class PasswordResetsModelInitHost
{
    use PasswordResetsModelTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializePasswordResetsModel( $init , $container ) ; }
    public function model() :?Documents { return $this->passwordResetsModel ; }
}

class SessionsModelInitHost
{
    use SessionsModelTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeSessionsModel( $init , $container ) ; }
    public function model() :?Documents { return $this->sessionsModel ; }
}

class InvitationsModelInitHost
{
    use InvitationsModelTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeInvitationsModel( $init , $container ) ; }
    public function model() :?Documents { return $this->invitationsModel ; }
}

class UserHasRolesInitHost
{
    use UserHasRolesTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeUserHasRoles( $init , $container ) ; }
    public function edges() :?Edges { return $this->userHasRoles ; }
}

class CasbinSyncInitHost
{
    use CasbinSyncTrait ;
    public function init( array $init , Container $container ) :static { return $this->initializeCasbinSync( $init , $container ) ; }
    public function sync() :?CasbinPolicySync { return $this->casbinSync ; }
}

class UserMaxLevelResolverInitHost
{
    use UserMaxLevelResolverTrait ;
    public function init( array $init , ?Container $container ) :static { return $this->initializeUserMaxLevelResolver( $init , $container ) ; }
    public function resolver() :?UserMaxLevelResolver { return $this->userMaxLevelResolver ; }
}

/**
 * Unit coverage for the auth DI initializer traits (model / edge / service
 * resolvers). With an empty init and no resolvable service, each initializer
 * leaves its slot null and returns `$this` (fluent).
 */
#[CoversTrait(ApisModelTrait::class)]
#[CoversTrait(AuditLogsModelTrait::class)]
#[CoversTrait(PasswordResetsModelTrait::class)]
#[CoversTrait(SessionsModelTrait::class)]
#[CoversTrait(InvitationsModelTrait::class)]
#[CoversTrait(UserHasRolesTrait::class)]
#[CoversTrait(CasbinSyncTrait::class)]
#[CoversTrait(UserMaxLevelResolverTrait::class)]
class AuthInitializersTest extends TestCase
{
    public function testApisModelInitializerDefaultsToNull() :void
    {
        $host = new ApisModelInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->model() ) ;
    }

    public function testAuditLogsModelInitializerDefaultsToNull() :void
    {
        $host = new AuditLogsModelInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->model() ) ;
    }

    public function testPasswordResetsModelInitializerDefaultsToNull() :void
    {
        $host = new PasswordResetsModelInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->model() ) ;
    }

    public function testSessionsModelInitializerDefaultsToNull() :void
    {
        $host = new SessionsModelInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->model() ) ;
    }

    public function testInvitationsModelInitializerDefaultsToNull() :void
    {
        $host = new InvitationsModelInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->model() ) ;
    }

    public function testUserHasRolesInitializerDefaultsToNull() :void
    {
        $host = new UserHasRolesInitHost() ;
        $this->assertSame( $host , $host->init( [] , null ) ) ;
        $this->assertNull( $host->edges() ) ;
    }

    public function testCasbinSyncInitializerDefaultsToNull() :void
    {
        $host = new CasbinSyncInitHost() ;
        $this->assertSame( $host , $host->init( [] , new Container() ) ) ;
        $this->assertNull( $host->sync() ) ;
    }

    public function testUserMaxLevelResolverInitializerDefaultsToNull() :void
    {
        $host = new UserMaxLevelResolverInitHost() ;
        $this->assertSame( $host , $host->init( [] , new Container() ) ) ;
        $this->assertNull( $host->resolver() ) ;
    }
}
