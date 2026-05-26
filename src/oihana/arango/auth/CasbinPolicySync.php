<?php

namespace oihana\arango\auth;

use DI\Container;

use oihana\arango\auth\traits\CasbinPolicySyncEdgeTrait;
use oihana\arango\auth\traits\CasbinPolicySyncPolicyTrait;
use oihana\arango\auth\traits\CasbinPolicySyncRoleTrait;
use oihana\arango\auth\traits\CasbinPolicySyncServiceTrait;
use oihana\arango\auth\traits\CasbinPolicySyncUserTrait;
use oihana\auth\casbin\traits\EnforcerTrait;
use oihana\arango\auth\traits\models\PermissionsModelTrait;
use oihana\arango\auth\traits\models\PoliciesModelTrait;
use oihana\arango\auth\traits\edges\PolicyHasPermissionsTrait;
use oihana\arango\auth\traits\edges\RoleHasPermissionsTrait;
use oihana\arango\auth\traits\edges\RoleHasPoliciesTrait;
use oihana\arango\auth\traits\models\RolesModelTrait;
use oihana\arango\auth\traits\edges\ServiceHasPermissionsTrait;
use oihana\arango\auth\traits\edges\ServiceHasPoliciesTrait;
use oihana\arango\auth\traits\models\ServicesModelTrait;
use oihana\arango\auth\traits\edges\UserHasPermissionsTrait;
use oihana\arango\auth\traits\models\UsersModelTrait;
use oihana\logging\LoggerTrait;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Synchronizes Casbin policies in real-time when edge relations are modified.
 *
 * Coordinator class : every actual sync logic lives in one of the
 * per-domain traits (edge dispatcher, role, user, service, policy).
 * This class carries the dependencies (Enforcer + Arango models +
 * edge collections + logger) by composing the canonical XxxModelTrait,
 * XxxEdgesTrait, EnforcerTrait and LoggerTrait families, then wires
 * them through the standard $init / $container init pattern shared
 * by every other auth-side service.
 *
 * @package oihana\arango\auth
 * @author  Marc Alcaraz
 */
class CasbinPolicySync
{
    /**
     * Creates a new CasbinPolicySync instance.
     *
     * @param array          $init      Init array.
     * @param Container|null $container Optional DI container — strings in $init are resolved via $container->get().
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct( array $init = [] , ?Container $container = null )
    {
        $this->domain = (string) ( $init[ self::DOMAIN ] ?? '' ) ;

        $this->initializeEnforcer              ( $init , $container )
             ->initializeLogger                ( $init , $container )
             ->initializeRolesModel            ( $init , $container )
             ->initializePermissionsModel      ( $init , $container )
             ->initializePoliciesModel         ( $init , $container )
             ->initializeServicesModel         ( $init , $container )
             ->initializeUsersModel            ( $init , $container )
             ->initializeRoleHasPermissions    ( $init , $container )
             ->initializeRoleHasPolicies       ( $init , $container )
             ->initializePolicyHasPermissions  ( $init , $container )
             ->initializeUserHasPermissions    ( $init , $container )
             ->initializeServiceHasPermissions ( $init , $container )
             ->initializeServiceHasPolicies    ( $init , $container ) ;
    }

    use CasbinPolicySyncEdgeTrait    ,
        CasbinPolicySyncPolicyTrait  ,
        CasbinPolicySyncRoleTrait    ,
        CasbinPolicySyncServiceTrait ,
        CasbinPolicySyncUserTrait    ,
        EnforcerTrait                ,
        LoggerTrait                  ,
        PermissionsModelTrait        ,
        PoliciesModelTrait           ,
        PolicyHasPermissionsTrait    ,
        RoleHasPermissionsTrait      ,
        RoleHasPoliciesTrait         ,
        RolesModelTrait              ,
        ServiceHasPermissionsTrait   ,
        ServiceHasPoliciesTrait      ,
        ServicesModelTrait           ,
        UserHasPermissionsTrait      ,
        UsersModelTrait              ;

    /**
     * Initialization key for the Casbin domain (e.g. the active API identifier
     * such as `my-api`). Forwarded to every implicit-permission lookup performed
     * by the per-domain sync traits.
     */
    public const string DOMAIN = 'domain' ;

    /**
     * The Casbin domain (= the active API identifier) the policies live in.
     */
    protected string $domain = '' ;
}
