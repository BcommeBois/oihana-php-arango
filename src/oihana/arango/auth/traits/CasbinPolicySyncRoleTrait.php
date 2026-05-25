<?php

namespace oihana\arango\auth\traits;

use oihana\auth\enums\PermissionEffect;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\controllers\enums\Skin;
use oihana\exceptions\BindException;
use oihana\signals\notices\Payload;

use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;

use function oihana\auth\helpers\casbinSafeSubject;

/**
 * Casbin policy synchronization handlers for the `roles` collection.
 *
 * Handles the role branch of the live RBAC sync : direct
 * `role_has_permissions` edges, indirect `role_has_policies` propagation
 * (one Casbin row per permission of the policy keyed on the role
 * identifier), and the cascade-aware role vertex deletion which wipes
 * every Casbin trace of the role in one shot.
 *
 * Subject convention : every Casbin row keyed on a role uses the role's
 * stable `identifier` (Zitadel role key, pinned at POST time and never
 * mutated). Legacy roles created before the pin convention fall back to
 * `name`, which is also their current Zitadel key — once
 * `bun auth:roles:backfill-identifiers` has run, the fallback becomes
 * dead code.
 *
 * The trait expects the consumer class to expose the following protected
 * properties (already declared on
 * {@see \oihana\arango\auth\CasbinPolicySync} via constructor promotion) :
 *  - `?Enforcer $enforcer`
 *  - `string $domain`
 *  - `?Documents $rolesModel`
 *  - `?Documents $permissionsModel`
 *  - `?Documents $policiesModel`
 *  - `?LoggerInterface $logger`
 *
 * Plus the standalone helper `casbinSafeSubject()`.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncRoleTrait
{
    /**
     * Registers cleanup of Casbin policies / groupings when a role vertex is deleted.
     *
     * The cascade edge purge wired on the Roles model (`role_has_permissions`,
     * `user_has_roles` via `RolesController::CASCADE_EDGES`) removes the edges
     * with a raw AQL query, which bypasses per-edge `afterDelete` signals.
     * As a consequence the normal edge-level Casbin sync is never triggered
     * for those cascaded deletes and policies/groupings would leak.
     *
     * This listener subscribes to the role model's `afterDelete` signal and
     * calls `Enforcer::deleteRole($name)` for every deleted role — which wipes
     * both the `p, <roleName>, ...` policies and the `g, <userId>, <roleName>, ...`
     * groupings in one shot.
     *
     * @param Documents $rolesModel The roles vertex model.
     */
    public function registerRoleDelete( Documents $rolesModel ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $rolesModel->afterDelete?->connect( fn( Payload $p ) => $this->onRoleDelete( $p ) ) ;
    }

    /**
     * Adds: p, roleIdentifier, domain, object, action, effect
     *
     * The Casbin subject for role-level policies is the role's stable
     * `identifier` (Zitadel role key), never the mutable `name`. This way
     * a PATCH rename never has to rewrite policies — `name` drifts but
     * `identifier` is set once at POST time and pinned forever.
     *
     * Legacy fallback: for roles created before the identifier pin
     * convention landed (typically the 3 seeded ones: admin/guest/superadmin
     * when not yet backfilled) we fall back to `$role->name`, which **is**
     * their current Zitadel key. Backfill command:
     * `bun auth:roles:backfill-identifiers`.
     *
     * @param string $roleKey
     * @param string $permissionKey
     * @return void
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addRolePermissionPolicy( string $roleKey , string $permissionKey ) :void
    {
        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;
        $permission  = $this->permissionsModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $permissionKey ]) ;

        if( !$roleSubject || !$permission )
        {
            return ;
        }

        $this->enforcer->addPolicy
        (
            $roleSubject ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: +policy role '$roleSubject' → perm '$permissionKey'" ) ;
    }

    /**
     * Adds policies for every permission of a policy attached to a role.
     *
     * When a policy vertex is attached to a role via the
     * `role_has_policies` edge, every permission contained in the policy
     * must be materialised in Casbin keyed on the role's stable
     * identifier — otherwise `getImplicitPermissionsForUser` will not
     * walk through `policy_has_permissions` and the role's effective
     * permission set silently misses the policy's permissions.
     *
     * Without this method, the M2M branch (service → policy) is the
     * only path Casbin sees, and self-service callers whose effective
     * permissions depend on a role-attached policy fail
     * `validatePolicyAttachmentPermissions` even though they have the
     * permission "on paper" (in the role's policies tab).
     *
     * @param string $roleKey   The role ArangoDB `_key`.
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addRolePolicyPolicies( string $roleKey , string $policyKey ) :void
    {
        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;

        if( !$roleSubject )
        {
            return ;
        }

        $policy = $this->policiesModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $policyKey , Arango::SKIN => Skin::FULL ]) ;

        if( !$policy || empty( $policy->permissions ) )
        {
            return ;
        }

        foreach( $policy->permissions as $permission )
        {
            $this->enforcer->addPolicy
            (
                $roleSubject ,
                $permission->domain ?? $this->domain ,
                $permission->object ?? '' ,
                $permission->action ?? '' ,
                $permission->effect ?? PermissionEffect::ALLOW
            ) ;
        }

        $count = count( $policy->permissions ) ;
        $this->logger?->info( "CasbinSync: +$count policies role '$roleSubject' via policy '$policyKey'" ) ;
    }

    /**
     * Called when a role vertex is deleted — wipes every Casbin trace of that role.
     *
     * The payload `data` is the `OLD` document (or list of documents) returned
     * by the ArangoDB `REMOVE` query, so role names are still accessible even
     * though the vertex is gone.
     *
     * @param Payload $payload
     *
     * @return void
     */
    protected function onRoleDelete( Payload $payload ) :void
    {
        $data = $payload->data ?? null ;

        if( !$data )
        {
            return ;
        }

        $roles = is_array( $data ) ? $data : [ $data ] ;

        foreach( $roles as $role )
        {
            // Mirror the add/remove convention: policies + groupings are keyed
            // on the role's stable identifier. Fall back to `name` for the
            // legacy roles that still lack an identifier (backfill pending).
            $subject = is_object( $role )
                ? ( $role->identifier ?? $role->name ?? null )
                : ( $role[ 'identifier' ] ?? $role[ 'name' ] ?? null ) ;

            if( !$subject )
            {
                continue ;
            }

            // Normalise to a non-purely-numeric string — PHP would otherwise
            // coerce numeric keys and break Casbin's in-memory role manager.
            $this->enforcer->deleteRole( casbinSafeSubject( (string) $subject ) ) ;

            $this->logger?->info( "CasbinSync: deleted role '$subject' (purged policies + groupings)" ) ;
        }
    }

    /**
     * Removes: p, roleIdentifier, domain, object, action, effect
     *
     * Same identifier-vs-name convention as `addRolePermissionPolicy`.
     *
     * @param string $roleKey
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
    protected function removeRolePermissionPolicy( string $roleKey , string $permissionKey ) :void
    {
        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;
        $permission  = $this->permissionsModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $permissionKey ]) ;

        if( !$roleSubject || !$permission )
        {
            return ;
        }

        $this->enforcer->removePolicy
        (
            $roleSubject ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: -policy role '$roleSubject' → perm '$permissionKey'" ) ;
    }

    /**
     * Removes policies for every permission of a policy detached from a role.
     *
     * Mirror of {@see addRolePolicyPolicies}.
     *
     * @param string $roleKey   The role ArangoDB `_key`.
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function removeRolePolicyPolicies( string $roleKey , string $policyKey ) :void
    {
        $roleSubject = $this->resolveRoleSubject( $roleKey ) ;

        if( !$roleSubject )
        {
            return ;
        }

        $policy = $this->policiesModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $policyKey , Arango::SKIN => Skin::FULL ]) ;

        if( !$policy || empty( $policy->permissions ) )
        {
            return ;
        }

        foreach( $policy->permissions as $permission )
        {
            $this->enforcer->removePolicy
            (
                $roleSubject ,
                $permission->domain ?? $this->domain ,
                $permission->object ?? '' ,
                $permission->action ?? '' ,
                $permission->effect ?? PermissionEffect::ALLOW
            ) ;
        }

        $count = count( $policy->permissions ) ;
        $this->logger?->info( "CasbinSync: -$count policies role '$roleSubject' via policy '$policyKey'" ) ;
    }

    /**
     * Resolves the Casbin subject for a role.
     *
     * Roles created since the Zitadel pin convention carry their stable
     * `identifier` (Zitadel role key = Arango `_key` of the role vertex).
     * Legacy roles (admin / guest / superadmin seeded before the pin
     * landed, not yet backfilled) fall back to `name`, which **is** their
     * current Zitadel key — safe for Casbin. Once the backfill command
     * `bun auth:roles:backfill-identifiers` has run, every role has an
     * identifier and the fallback becomes dead code.
     *
     * @param string $roleKey The ArangoDB `_key` of the role vertex.
     * @return string|null null if the role vertex is gone.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function resolveRoleSubject( string $roleKey ) :?string
    {
        $role = $this->rolesModel?->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $roleKey ]) ;

        if( !$role )
        {
            return null ;
        }

        $subject = $role->identifier ?? $role->name ?? null ;

        return $subject !== null ? casbinSafeSubject( (string) $subject ) : null ;
    }
}
