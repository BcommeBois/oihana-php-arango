<?php

namespace oihana\arango\auth\traits;

use oihana\auth\enums\PermissionEffect;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\exceptions\BindException;
use oihana\signals\notices\Payload;

use org\schema\constants\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Throwable;

use function oihana\arango\helpers\parseKey;

/**
 * Casbin policy synchronization handlers for the `policies` collection.
 *
 * Handles the policy branch of the live RBAC sync : the `policy_has_permissions`
 * propagation (when a permission is added to / removed from a policy, every
 * subject currently attached to that policy gets its Casbin tuples kept in
 * sync), and the cascade-aware cleanup that runs **before** a policy or
 * permission vertex is deleted (since the cascade edge purge bypasses
 * per-edge `afterDelete` signals).
 *
 * The cleanup methods cover both direct subjects (role / user / service via
 * `*_has_permissions`) and the indirect "policy materialised on subject"
 * path (via `policy_has_permissions` × `service_has_policies` ×
 * `role_has_policies`).
 *
 * The trait expects the consumer class to expose the following protected
 * properties (already declared on
 * {@see \oihana\arango\auth\CasbinPolicySync} via constructor promotion) :
 *  - `?Enforcer $enforcer`
 *  - `string $domain`
 *  - `?Documents $permissionsModel`
 *  - `?Documents $policiesModel`
 *  - `?Edges $policyHasPermissions`
 *  - `?Edges $roleHasPermissions`
 *  - `?Edges $roleHasPolicies`
 *  - `?Edges $userHasPermissions`
 *  - `?Edges $serviceHasPermissions`
 *  - `?Edges $serviceHasPolicies`
 *  - `?LoggerInterface $logger`
 *
 * Plus the cross-trait helpers {@see CasbinPolicySyncRoleTrait::resolveRoleSubject},
 * {@see CasbinPolicySyncUserTrait::resolveUserIdentifier},
 * {@see CasbinPolicySyncServiceTrait::resolveServiceSubject},
 * {@see CasbinPolicySyncServiceTrait::resolveServicesForPolicy},
 * {@see CasbinPolicySyncServiceTrait::removeServicePolicyPolicies} and
 * {@see CasbinPolicySyncRoleTrait::removeRolePolicyPolicies}.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncPolicyTrait
{
    /**
     * Registers cleanup of Casbin tuples when a permission vertex is about to be deleted.
     *
     * Subscribes to the permissions model's `beforeDelete` signal (not
     * `afterDelete` like role / user / service): a permission is not itself
     * a Casbin subject, so the tuples to purge are keyed on the role / user /
     * service vertices that point to it. We must enumerate those subjects
     * via the inbound `*_has_permissions` and `policy_has_permissions` edges
     * **before** the cascade edge purge fires — once `afterDelete` runs,
     * the edges are gone and the subject set is unrecoverable.
     *
     * Wired alongside the existing `registerRoleDelete` / `registerUserDelete`
     * / `registerServiceDelete` so every delete path (HTTP controller, CLI
     * command, raw model call, future transitive cascade) automatically
     * cleans the `rbac` collection.
     *
     * @param Documents $permissionsModel The permissions vertex model.
     */
    public function registerPermissionDelete( Documents $permissionsModel ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $permissionsModel->beforeDelete?->connect( fn( Payload $p ) => $this->onPermissionDelete( $p ) ) ;
    }

    /**
     * Registers cleanup of Casbin tuples when a policy vertex is about to be deleted.
     *
     * Symmetric to {@see registerPermissionDelete} but for policies. Connects
     * to `beforeDelete` because the cleanup walks `*_has_policies` and
     * `policy_has_permissions` edges that the cascade purge wipes via raw
     * AQL (bypassing per-edge `afterDelete` signals).
     *
     * @param Documents $policiesModel The policies vertex model.
     */
    public function registerPolicyDelete( Documents $policiesModel ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $policiesModel->beforeDelete?->connect( fn( Payload $p ) => $this->onPolicyDelete( $p ) ) ;
    }

    /**
     * Called before one or more permission vertices are deleted.
     *
     * The payload `context` mirrors the `$init` array passed to
     * `Documents::delete()` — the keys to purge live in `Arango::VALUE`
     * (always normalised to an array by `DocumentsControllerDeleteTrait`).
     * Each key triggers the existing edge-walk + Enforcer purge.
     *
     * @param Payload $payload
     *
     * @return void
     */
    protected function onPermissionDelete( Payload $payload ) :void
    {
        $context = $payload->context ?? [] ;
        $values  = $context[ Arango::VALUE ] ?? [] ;
        $keys    = is_array( $values ) ? $values : [ $values ] ;

        foreach( $keys as $key )
        {
            if( !$key )
            {
                continue ;
            }

            try
            {
                $this->cleanupPermissionDerivedPolicies( (string) $key ) ;
            }
            catch( Throwable $e )
            {
                $this->logger?->warning( "CasbinSync: permission cleanup failed for '$key': " . $e->getMessage() ) ;
            }
        }
    }

    /**
     * Called before one or more policy vertices are deleted.
     *
     * Same payload contract as {@see onPermissionDelete}: keys come from
     * `context[Arango::VALUE]`. Each key triggers the existing
     * service / role attached-subjects purge.
     *
     * @param Payload $payload
     *
     * @return void
     */
    protected function onPolicyDelete( Payload $payload ) :void
    {
        $context = $payload->context ?? [] ;
        $values  = $context[ Arango::VALUE ] ?? [] ;
        $keys    = is_array( $values ) ? $values : [ $values ] ;

        foreach( $keys as $key )
        {
            if( !$key )
            {
                continue ;
            }

            try
            {
                $this->cleanupPolicyDerivedPolicies( (string) $key ) ;
            }
            catch( Throwable $e )
            {
                $this->logger?->warning( "CasbinSync: policy cleanup failed for '$key': " . $e->getMessage() ) ;
            }
        }
    }

    /**
     * Removes every Casbin policy derived from a permission about to be deleted.
     *
     * Motivation — a permission is not itself a Casbin subject. It surfaces in
     * the `(object, action, effect)` tuple of every policy that references it,
     * keyed on a subject (role identifier, user identifier or service
     * identifier). When the permission vertex is deleted, the native cascade
     * purges the inbound edges (`role_has_permissions`,
     * `user_has_permissions`, `service_has_permissions` and
     * `policy_has_permissions`) via raw AQL `REMOVE`, which bypasses per-edge
     * `afterDelete` signals — so the corresponding `removePolicy` calls never
     * fire and policies survive in the `rbac` collection as orphan rows. Any
     * subject keyed on those rows (role / user / M2M service) keeps passing
     * Casbin checks against an object/action that no longer exists.
     *
     * Strategy (approach B — targeted by edges) :
     *   1. Read the permission to capture `(domain, object, action, effect)`.
     *   2. Walk each direct inbound edge type, resolve every subject's stable
     *      identifier and call `removePolicy(subject, ...)` once per match.
     *   3. Walk `policy_has_permissions` to find policies that hold this
     *      permission, then for each policy walk `service_has_policies` /
     *      `role_has_policies` to find every subject that holds that policy,
     *      and `removePolicy` keyed on the subject's identifier (this covers
     *      the indirect "policy materialised on subject" path that no direct
     *      edge expresses).
     *
     * This method must be called **before** the permission vertex is removed
     * from Arango — once the cascade fires, the inbound edges are gone and
     * we can no longer enumerate the subjects to purge.
     *
     * @param string $permissionKey The `_key` of the permission about to be deleted.
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function cleanupPermissionDerivedPolicies( string $permissionKey ) :void
    {
        if( !$this->enforcer || !$this->permissionsModel )
        {
            return ;
        }

        try
        {
            $permission = $this->permissionsModel->get([ Arango::KEY => '_key' , Arango::VALUE => $permissionKey ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: failed to load permission '$permissionKey': " . $e->getMessage() ) ;
            return ;
        }

        if( !$permission )
        {
            return ;
        }

        // Narrow the schema-typed `mixed` attributes to `string` so the helpers
        // below can rely on strict types without sprinkling casts at every call
        // site. A non-string value falls back to the same defaults the existing
        // listeners use when the field is absent.
        $domain = is_string( $permission->domain ?? null ) ? $permission->domain : $this->domain ;
        $object = is_string( $permission->object ?? null ) ? $permission->object : '' ;
        $action = is_string( $permission->action ?? null ) ? $permission->action : '' ;
        $effect = is_string( $permission->effect ?? null ) ? $permission->effect : PermissionEffect::ALLOW ;

        $permissionId = "permissions/$permissionKey" ;
        $purged       = 0 ;

        // Direct subjects: role, user, service
        if( $this->roleHasPermissions )
        {
            $purged += $this->purgePoliciesByEdges
            (
                $this->roleHasPermissions ,
                $permissionId ,
                fn( string $key ) => $this->resolveRoleSubject( $key ) ,
                $domain , $object , $action , $effect
            ) ;
        }

        if( $this->userHasPermissions )
        {
            $purged += $this->purgePoliciesByEdges
            (
                $this->userHasPermissions ,
                $permissionId ,
                fn( string $key ) => $this->resolveUserIdentifier( $key ) ,
                $domain , $object , $action , $effect
            ) ;
        }

        if( $this->serviceHasPermissions )
        {
            $purged += $this->purgePoliciesByEdges
            (
                $this->serviceHasPermissions ,
                $permissionId ,
                fn( string $key ) => $this->resolveServiceSubject( $key ) ,
                $domain , $object , $action , $effect
            ) ;
        }

        // Indirect subjects: policy_has_permissions → (service_has_policies | role_has_policies)
        if( $this->policyHasPermissions && ( $this->serviceHasPolicies || $this->roleHasPolicies ) )
        {
            $purged += $this->purgePoliciesViaPolicy( $permissionId , $domain , $object , $action , $effect ) ;
        }

        $this->logger?->info( "CasbinSync: permission '$permissionKey' derived policies cleaned ($purged purged)" ) ;
    }

    /**
     * Removes every Casbin policy derived from attaching the given policy to a
     * subject (service OR role), for every subject currently holding the
     * policy.
     *
     * Motivation — policies are not Casbin subjects: when a policy is attached
     * to a service via `service_has_policies` (or to a role via
     * `role_has_policies`), the corresponding edge listener materialises one
     * Casbin policy per permission in the policy, keyed by the subject :
     *
     * ```
     * p, service:<service._key>, <domain>, <object>, <action>, <effect>
     * p, <roleIdentifier>, <domain>, <object>, <action>, <effect>
     * ```
     *
     * Deleting a policy triggers a cascade purge of the `*_has_policies`
     * edges via raw AQL `REMOVE`, which bypasses per-edge `afterDelete`
     * signals — so the derived policies would survive in the `rbac`
     * collection and M2M subjects would keep passing Casbin checks for
     * capabilities that no longer exist. Silent security gap.
     *
     * This method must be called **before** the policy vertex is removed from
     * Arango (it reads `policy.permissions` and the current `*_has_policies`
     * edges, which both disappear once the cascade runs).
     *
     * @param string $policyKey The `_key` of the policy about to be deleted.
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function cleanupPolicyDerivedPolicies( string $policyKey ) :void
    {
        if( !$this->enforcer || ( !$this->serviceHasPolicies && !$this->roleHasPolicies ) )
        {
            return ;
        }

        $serviceKeys = $this->resolveServicesForPolicy( $policyKey ) ;

        foreach( $serviceKeys as $serviceKey )
        {
            $this->removeServicePolicyPolicies( $serviceKey , $policyKey ) ;
        }

        $roleKeys = [] ;

        if( $this->roleHasPolicies )
        {
            try
            {
                $roleEdges = $this->roleHasPolicies->list
                ([
                    Arango::CONDITIONS => [ 'doc._to == @policyId' ] ,
                    Arango::BINDS      => [ 'policyId' => "policies/$policyKey" ] ,
                    Arango::LIMIT      => 1000 ,
                ]) ;
            }
            catch( Throwable $e )
            {
                $this->logger?->warning( "CasbinSync: failed to list roles for policy '$policyKey': " . $e->getMessage() ) ;
                $roleEdges = [] ;
            }

            foreach( $roleEdges as $edge )
            {
                $from = is_object( $edge ) ? ( $edge->_from ?? null ) : ( $edge[ '_from' ] ?? null ) ;
                $key  = parseKey( $from ) ;

                if( $key )
                {
                    $roleKeys[ $key ] = true ;
                }
            }

            foreach( array_keys( $roleKeys ) as $roleKey )
            {
                $this->removeRolePolicyPolicies( $roleKey , $policyKey ) ;
            }
        }

        $this->logger?->info( "CasbinSync: policy '$policyKey' derived policies cleaned for " . count( $serviceKeys ) . ' service(s) + ' . count( $roleKeys ) . ' role(s)' ) ;
    }

    /**
     * Propagates a `policy_has_permissions` insertion to every subject
     * (service or role) currently attached to the policy.
     *
     * Walks both `service_has_policies` and `role_has_policies` for the
     * policy, then for each attached subject adds one Casbin row :
     *
     * ```
     * p, service:<service._key>, <domain>, <object>, <action>, <effect>
     * p, <roleIdentifier>, <domain>, <object>, <action>, <effect>
     * ```
     *
     * Without this propagation, an admin attaching a permission to a policy
     * would only update the rbac collection at the next full materialization
     * (`php bin/console.php auth:materialize`) — subjects already attached to the policy
     * would keep operating on a stale permission set until then.
     *
     * Casbin's `addPolicy` is idempotent (returns false on duplicates) so a
     * subject already granted the same permission via another source (a
     * sibling policy or a direct `*_has_permissions` edge) keeps a single
     * row in rbac.
     *
     * @param string $policyKey The policy ArangoDB `_key`.
     * @param string $permissionKey The permission ArangoDB `_key`.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addPolicyPermissionPolicy( string $policyKey , string $permissionKey ) :void
    {
        $ctx = $this->loadPolicyPermissionContext( $policyKey , $permissionKey ) ;

        if( !$ctx )
        {
            return ;
        }

        $count = 0 ;

        foreach( $ctx[ 'serviceKeys' ] as $serviceKey )
        {
            $subject = $this->resolveServiceSubject( $serviceKey ) ;

            if( $this->enforcer->addPolicy( $subject , $ctx[ 'domain' ] , $ctx[ 'object' ] , $ctx[ 'action' ] , $ctx[ 'effect' ] ) )
            {
                $count++ ;
            }
        }

        foreach( $ctx[ 'roleKeys' ] as $roleKey )
        {
            $subject = $this->resolveRoleSubject( $roleKey ) ;

            if( !$subject )
            {
                continue ;
            }

            if( $this->enforcer->addPolicy( $subject , $ctx[ 'domain' ] , $ctx[ 'object' ] , $ctx[ 'action' ] , $ctx[ 'effect' ] ) )
            {
                $count++ ;
            }
        }

        $this->logger?->info( "CasbinSync: +perm '$permissionKey' on $count subject(s) via policy '$policyKey'" ) ;
    }

    /**
     * Propagates a `policy_has_permissions` deletion to every service AND
     * every role currently attached to the policy.
     *
     * Symmetric to {@see addPolicyPermissionPolicy}: removes the
     * `(subject, domain, object, action, effect)` row keyed on each
     * attached subject (the service's namespaced `service:{_key}` or
     * the role identifier). Subjects that grant the same permission
     * via a sibling policy or a direct `*_has_permissions` edge will
     * lose the row here — this is a known limitation shared with the
     * existing `removeService*` / `removeRole*` handlers (no overlap
     * audit). A full reseed (`php bin/console.php auth:materialize`) restores the
     * rows from any remaining source.
     *
     * @param string $policyKey The policy ArangoDB `_key`.
     * @param string $permissionKey The permission ArangoDB `_key`.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function removePolicyPermissionPolicy( string $policyKey , string $permissionKey ) :void
    {
        $ctx = $this->loadPolicyPermissionContext( $policyKey , $permissionKey ) ;

        if( !$ctx )
        {
            return ;
        }

        $count = 0 ;

        foreach( $ctx[ 'serviceKeys' ] as $serviceKey )
        {
            $subject = $this->resolveServiceSubject( $serviceKey ) ;

            if( $this->enforcer->removePolicy( $subject , $ctx[ 'domain' ] , $ctx[ 'object' ] , $ctx[ 'action' ] , $ctx[ 'effect' ] ) )
            {
                $count++ ;
            }
        }

        foreach( $ctx[ 'roleKeys' ] as $roleKey )
        {
            $subject = $this->resolveRoleSubject( $roleKey ) ;

            if( !$subject )
            {
                continue ;
            }

            if( $this->enforcer->removePolicy( $subject , $ctx[ 'domain' ] , $ctx[ 'object' ] , $ctx[ 'action' ] , $ctx[ 'effect' ] ) )
            {
                $count++ ;
            }
        }

        $this->logger?->info( "CasbinSync: -perm '$permissionKey' on $count subject(s) via policy '$policyKey'" ) ;
    }

    /**
     * Loads the shared context used by `addPolicyPermissionPolicy` and
     * `removePolicyPermissionPolicy` : looks up the permission, resolves
     * every service AND role currently attached to the policy, and
     * returns a precomputed bundle. Returns null when nothing can be
     * done (no enforcer, missing permission, no attached subjects).
     *
     * Lifted out of the two propagation handlers to avoid duplicating
     * the early-return + lookup + (domain, object, action, effect)
     * extraction. Keeps both handlers down to a clean two-loop body.
     *
     * @param string $policyKey     The policy ArangoDB `_key`.
     * @param string $permissionKey The permission ArangoDB `_key`.
     *
     * @return array{
     *   permission:  object ,
     *   serviceKeys: string[] ,
     *   roleKeys:    string[] ,
     *   domain:      string ,
     *   object:      string ,
     *   action:      string ,
     *   effect:      string ,
     * }|null
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function loadPolicyPermissionContext( string $policyKey , string $permissionKey ) :?array
    {
        if( !$this->enforcer || !$this->permissionsModel )
        {
            return null ;
        }

        $permission = $this->permissionsModel->get([ Arango::KEY => Schema::_KEY , Arango::VALUE => $permissionKey ]) ;

        if( !$permission )
        {
            return null ;
        }

        $serviceKeys = $this->serviceHasPolicies ? $this->resolveServicesForPolicy( $policyKey ) : [] ;
        $roleKeys    = $this->roleHasPolicies    ? $this->resolveRolesForPolicy   ( $policyKey ) : [] ;

        if( empty( $serviceKeys ) && empty( $roleKeys ) )
        {
            return null ;
        }

        return
        [
            'permission'  => $permission ,
            'serviceKeys' => $serviceKeys ,
            'roleKeys'    => $roleKeys ,
            'domain'      => $permission->domain ?? $this->domain ,
            'object'      => $permission->object ?? '' ,
            'action'      => $permission->action ?? '' ,
            'effect'      => $permission->effect ?? PermissionEffect::ALLOW ,
        ] ;
    }

    /**
     * Lists every edge of the given relation pointing to `$permissionId`,
     * resolves each `_from` vertex into its Casbin subject through `$resolver`,
     * and removes the matching `(subject, domain, object, action, effect)`
     * policy via the Enforcer.
     *
     * Used by {@see cleanupPermissionDerivedPolicies} for the three direct
     * inbound relations: `role_has_permissions`, `user_has_permissions` and
     * `service_has_permissions`.
     *
     * @param Edges    $edges        The inbound edge relation.
     * @param string   $permissionId The permission's `_id` (e.g. `permissions/123`).
     * @param callable $resolver     Maps an Arango `_key` to its Casbin subject string,
     *                               or null if the subject cannot be resolved.
     * @param string   $domain
     * @param string   $object
     * @param string   $action
     * @param string   $effect
     *
     * @return int The number of policies actually removed.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function purgePoliciesByEdges
    (
        Edges    $edges ,
        string   $permissionId ,
        callable $resolver ,
        string   $domain ,
        string   $object ,
        string   $action ,
        string   $effect
    )
    :int
    {
        try
        {
            $rows = $edges->list
            ([
                Arango::CONDITIONS => [ 'doc._to == @permId' ] ,
                Arango::BINDS      => [ 'permId' => $permissionId ] ,
                Arango::LIMIT      => 1000 ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: failed to list edges for '$permissionId': " . $e->getMessage() ) ;
            return 0 ;
        }

        $purged = 0 ;

        foreach( $rows as $edge )
        {
            $from = is_object( $edge ) ? ( $edge->_from ?? null ) : ( $edge[ '_from' ] ?? null ) ;
            $key  = parseKey( $from ) ;

            if( !$key )
            {
                continue ;
            }

            $subject = $resolver( $key ) ;

            if( !$subject )
            {
                continue ;
            }

            $this->enforcer->removePolicy( $subject , $domain , $object , $action , $effect ) ;
            $purged++ ;
        }

        return $purged ;
    }

    /**
     * Removes every Casbin policy derived from the permission via the
     * indirect "policy materialised on subject" path: lists policies
     * that hold the permission, then for each policy lists the
     * services and roles that hold the policy, and removes the
     * `(subject, domain, object, action, effect)` policy keyed on
     * each of them.
     *
     * Used by {@see cleanupPermissionDerivedPolicies}. Direct edges
     * are already covered by {@see purgePoliciesByEdges}; this method
     * covers the case where the policy was materialised by the
     * `service_policy` / `role_policy` listener and no direct
     * `service_has_permissions` / `role_has_permissions` edge ever
     * existed.
     *
     * @param string $permissionId
     * @param string $domain
     * @param string $object
     * @param string $action
     * @param string $effect
     *
     * @return int The number of policies actually removed.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function purgePoliciesViaPolicy
    (
        string $permissionId ,
        string $domain ,
        string $object ,
        string $action ,
        string $effect
    )
    :int
    {
        try
        {
            $policyRows = $this->policyHasPermissions->list
            ([
                Arango::CONDITIONS => [ 'doc._to == @permId' ] ,
                Arango::BINDS      => [ 'permId' => $permissionId ] ,
                Arango::LIMIT      => 1000 ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: failed to list policies for '$permissionId': " . $e->getMessage() ) ;
            return 0 ;
        }

        $purged = 0 ;

        foreach( $policyRows as $policyEdge )
        {
            $policyKey = parseKey
            (
                is_object( $policyEdge ) ? ( $policyEdge->_from ?? null ) : ( $policyEdge[ '_from' ] ?? null )
            ) ;

            if( !$policyKey )
            {
                continue ;
            }

            // Services attached to this policy — namespaced subject.
            if( $this->serviceHasPolicies )
            {
                foreach( $this->resolveServicesForPolicy( $policyKey ) as $serviceKey )
                {
                    $subject = $this->resolveServiceSubject( $serviceKey ) ;
                    $this->enforcer->removePolicy( $subject , $domain , $object , $action , $effect ) ;
                    $purged++ ;
                }
            }

            // Roles attached to this policy — keyed on the role's stable
            // identifier (mirror of the M2M paths above).
            if( $this->roleHasPolicies )
            {
                try
                {
                    $roleRows = $this->roleHasPolicies->list
                    ([
                        Arango::CONDITIONS => [ 'doc._to == @policyId' ] ,
                        Arango::BINDS      => [ 'policyId' => "policies/$policyKey" ] ,
                        Arango::LIMIT      => 1000 ,
                    ]) ;
                }
                catch( Throwable $e )
                {
                    $this->logger?->warning( "CasbinSync: failed to list roles for policy '$policyKey': " . $e->getMessage() ) ;
                    $roleRows = [] ;
                }

                foreach( $roleRows as $roleEdge )
                {
                    $roleKey = parseKey
                    (
                        is_object( $roleEdge ) ? ( $roleEdge->_from ?? null ) : ( $roleEdge[ '_from' ] ?? null )
                    ) ;

                    if( !$roleKey )
                    {
                        continue ;
                    }

                    $roleSubject = $this->resolveRoleSubject( $roleKey ) ;

                    if( !$roleSubject )
                    {
                        continue ;
                    }

                    $this->enforcer->removePolicy( $roleSubject , $domain , $object , $action , $effect ) ;
                    $purged++ ;
                }
            }
        }

        return $purged ;
    }

    /**
     * Lists every role currently attached to the given policy via the
     * `role_has_policies` edge.
     *
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @return string[] Distinct role `_key`s — empty array on failure or
     *                  when no role is attached.
     */
    private function resolveRolesForPolicy( string $policyKey ) :array
    {
        if( !$this->roleHasPolicies )
        {
            return [] ;
        }

        try
        {
            $edges = $this->roleHasPolicies->list
            ([
                Arango::CONDITIONS => [ 'doc._to == @policyId' ] ,
                Arango::BINDS      => [ 'policyId' => "policies/$policyKey" ] ,
                Arango::LIMIT      => 1000 ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: failed to list roles for policy '$policyKey': " . $e->getMessage() ) ;
            return [] ;
        }

        $roleKeys = [] ;

        foreach( $edges as $edge )
        {
            $from = is_object( $edge ) ? ( $edge->_from ?? null ) : ( $edge[ '_from' ] ?? null ) ;
            $key  = parseKey( $from ) ;

            if( $key )
            {
                $roleKeys[ $key ] = true ;
            }
        }

        return array_keys( $roleKeys ) ;
    }
}
