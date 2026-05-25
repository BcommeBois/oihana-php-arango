<?php

namespace oihana\arango\auth\traits;

use oihana\auth\enums\PermissionEffect;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\exceptions\BindException;
use oihana\signals\notices\Payload;

use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

use function oihana\auth\helpers\casbinSafeSubject;

/**
 * Casbin policy synchronization handlers for the `users` collection.
 *
 * Handles the user branch of the live RBAC sync : direct
 * `user_has_permissions` edges, `user_has_roles` groupings, the cascade-
 * aware user vertex deletion which wipes every Casbin trace of the user
 * (groupings + direct policies) in one shot, and the helper that maps an
 * Arango user `_key` to its Zitadel `identifier` (the only string ever
 * used as a user-keyed Casbin subject).
 *
 * Subject convention : every Casbin row keyed on a user uses the user's
 * Zitadel `identifier` (stable, never reassigned). The `_key` is never
 * used as a Casbin subject, so a user vertex without an identifier
 * never produced any Casbin row in the first place — nothing to clean
 * on deletion.
 *
 * The trait expects the consumer class to expose the following protected
 * properties (already declared on
 * {@see \oihana\arango\auth\CasbinPolicySync} via constructor promotion) :
 *  - `?Enforcer $enforcer`
 *  - `string $domain`
 *  - `?Documents $usersModel`
 *  - `?Documents $permissionsModel`
 *  - `?LoggerInterface $logger`
 *
 * Plus the cross-trait helper `resolveRoleSubject()` (provided by
 * {@see CasbinPolicySyncRoleTrait}) and the standalone helper
 * `casbinSafeSubject()`.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncUserTrait
{
    /**
     * Registers cleanup of Casbin policies / groupings when a user vertex is deleted.
     *
     * Symmetric to {@see CasbinPolicySyncRoleTrait::registerRoleDelete} but for
     * users. The cascade edge purge wired on the Users model
     * (`user_has_roles`, `user_has_permissions` via
     * `UsersController::CASCADE_EDGES`) removes the edges with a raw AQL
     * query, which bypasses per-edge `afterDelete` signals — so the
     * edge-level Casbin sync never fires and any
     * `g, <userIdentifier>, <roleIdentifier>, ...` grouping or
     * `p, <userIdentifier>, ...` direct policy would survive the deletion as
     * orphaned data (silent security gap on M2M flows that rely on the
     * user's identifier).
     *
     * This listener subscribes to the users model's `afterDelete` signal and
     * calls `Enforcer::deleteUser($identifier)` which purges both the user's
     * groupings AND any direct user→permission policy in one shot.
     *
     * @param Documents $usersModel The users vertex model.
     */
    public function registerUserDelete( Documents $usersModel ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $usersModel->afterDelete?->connect( fn( Payload $p ) => $this->onUserDelete( $p ) ) ;
    }

    /**
     * Adds: p, userId (identifier), domain, object, action, effect
     *
     * @param string $userKey
     * @param string $permissionKey
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addUserPermissionPolicy( string $userKey , string $permissionKey ) :void
    {
        $userId = $this->resolveUserIdentifier( $userKey ) ;

        if( !$userId )
        {
            return ;
        }

        $permission = $this->permissionsModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $permissionKey ]) ;

        if( !$permission )
        {
            return ;
        }

        $this->enforcer->addPolicy
        (
            $userId ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: +direct perm user '$userId' → '$permissionKey'" ) ;
    }

    /**
     * Adds: g, userIdentifier, roleIdentifier, domain
     *
     * @param string $userKey
     * @param string $roleKey
     * @return void
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addUserRoleGrouping( string $userKey , string $roleKey ) :void
    {
        $userId = $this->resolveUserIdentifier( $userKey ) ;
        if( !$userId ) { return ; }

        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;
        if( !$roleSubject ) { return ; }

        $this->enforcer->addGroupingPolicy( $userId , $roleSubject , $this->domain ) ;

        $this->logger?->info( "CasbinSync: +grouping user '$userId' → role '$roleSubject'" ) ;
    }

    /**
     * Called when a user vertex is deleted — wipes every Casbin trace of that user.
     *
     * The payload `data` is the `OLD` document (or list of documents) returned
     * by the ArangoDB `REMOVE` query, so the Zitadel `identifier` is still
     * accessible even though the vertex is gone.
     *
     * `Enforcer::deleteUser($subject)` purges in one shot:
     *   - every `g, <subject>, *, *` grouping (role assignments removed when
     *     the cascade-purged `user_has_roles` edges silently bypass per-edge
     *     `afterDelete` signals) ;
     *   - every `p, <subject>, *, *, *, *` direct user→permission policy
     *     (created via `user_has_permissions`, equally silent under cascade).
     *
     * @param Payload $payload
     *
     * @return void
     */
    protected function onUserDelete( Payload $payload ) :void
    {
        $data = $payload->data ?? null ;

        if( !$data )
        {
            return ;
        }

        $users = is_array( $data ) ? $data : [ $data ] ;

        foreach( $users as $user )
        {
            // Mirror the user-edge convention: every Casbin row keyed on a user
            // uses the Zitadel identifier (stable, never reassigned). No `_key`
            // fallback : a user vertex without an identifier never produced any
            // Casbin row in the first place, so there is nothing to clean.
            $subject = is_object( $user )
                ? ( $user->identifier ?? null )
                : ( $user[ 'identifier' ] ?? null ) ;

            if( !$subject )
            {
                continue ;
            }

            $this->enforcer->deleteUser( casbinSafeSubject( (string) $subject ) ) ;

            $this->logger?->info( "CasbinSync: deleted user '$subject' (purged groupings + direct policies)" ) ;
        }
    }

    /**
     * Removes: p, userId (identifier), domain, object, action, effect
     *
     * @param string $userKey
     * @param string $permissionKey
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function removeUserPermissionPolicy( string $userKey , string $permissionKey ) :void
    {
        $userId = $this->resolveUserIdentifier( $userKey ) ;
        if( !$userId ) { return ; }

        $permission = $this->permissionsModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $permissionKey ]) ;

        if( !$permission )
        {
            return ;
        }

        $this->enforcer->removePolicy
        (
            $userId ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: -direct perm user '$userId' → '$permissionKey'" ) ;
    }

    /**
     * Removes: g, userIdentifier, roleIdentifier, domain
     *
     * @param string $userKey
     * @param string $roleKey
     * @return void
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function removeUserRoleGrouping( string $userKey , string $roleKey ) :void
    {
        $userId = $this->resolveUserIdentifier( $userKey ) ;
        if( !$userId ) { return ; }

        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;
        if( !$roleSubject ) { return ; }

        $this->enforcer->removeGroupingPolicy( $userId , $roleSubject , $this->domain ) ;

        $this->logger?->info( "CasbinSync: -grouping user '$userId' → role '$roleSubject'" ) ;
    }

    /**
     * Resolves a user's identifier (Zitadel ID) from their ArangoDB _key.
     *
     * @param string $userKey
     * @return string|null null if the user has no identifier set.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function resolveUserIdentifier( string $userKey ) :?string
    {
        $user = $this->usersModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $userKey ]) ;

        $identifier = $user->identifier ?? null ;

        return $identifier !== null ? casbinSafeSubject( (string) $identifier ) : null ;
    }
}
