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
use Throwable;

use function oihana\auth\helpers\casbinSafeSubject;
use function oihana\arango\helpers\parseKey;

/**
 * Casbin policy synchronization handlers for the `services` collection.
 *
 * Canonical M2M Casbin sync trait — drives the Service Account
 * (Zitadel Machine User) flow. A wider refacto may split the
 * user / role / policy paths of {@see \oihana\arango\auth\CasbinPolicySync}
 * into their own traits as well (cf.
 * `memory/project_casbin_policy_sync_traits_refactor.md`).
 *
 * Subject namespacing : every Service Account row in `rbac` is keyed on
 * `service:{$service->_key}`. The middleware
 * {@see \oihana\api\middlewares\CheckJwtAuthentication::process()}
 * writes the same `service:` prefix into `ATTR_USER_ID` for incoming JWTs
 * so the in-memory keys match end-to-end.
 *
 * The trait expects the consumer class to expose the following protected
 * properties (already declared on `CasbinPolicySync` via constructor
 * promotion) :
 *  - `?Enforcer $enforcer`
 *  - `string $domain`
 *  - `?Documents $servicesModel`
 *  - `?Documents $policiesModel`
 *  - `?Documents $permissionsModel`
 *  - `?Edges $serviceHasPolicies`
 *  - `?Edges $serviceHasPermissions`
 *  - `?LoggerInterface $logger`
 *
 * Plus the standalone helpers `casbinSafeSubject()` and `parseKey()`.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncServiceTrait
{
    /**
     * Registers cleanup of Casbin policies / groupings when a service
     * vertex is deleted.
     *
     * The cascade edge purge wired on the Services model
     * (`service_has_policies`, `service_has_permissions`) removes the
     * edges with a raw AQL `REMOVE`, which bypasses per-edge
     * `afterDelete` signals — so the normal edge-level Casbin sync
     * is never triggered for those cascaded deletes and policies
     * would leak. This handler subscribes to the service model's
     * `afterDelete` signal and calls `Enforcer::deleteUser($subject)`
     * keyed on the service's namespaced subject (`service:{_key}`).
     *
     * @param Documents $servicesModel The services vertex model.
     */
    public function registerServiceDelete( Documents $servicesModel ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $servicesModel->afterDelete?->connect( fn( Payload $p ) => $this->onServiceDelete( $p ) ) ;
    }

    /**
     * Adds a direct permission policy for a service.
     *
     * @param string $serviceKey The service ArangoDB `_key`.
     * @param string $permissionKey The permission ArangoDB `_key`.
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addServicePermissionPolicy( string $serviceKey , string $permissionKey ) :void
    {
        if( !$this->enforcer || !$this->permissionsModel )
        {
            return ;
        }

        $subject    = $this->resolveServiceSubject( $serviceKey ) ;
        $permission = $this->permissionsModel->get
        ([
            Arango::KEY   => Schema::_KEY ,
            Arango::VALUE => $permissionKey ,
        ]) ;

        if( !$permission )
        {
            return ;
        }

        $this->enforcer->addPolicy
        (
            $subject ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: +direct perm service '$subject' → '$permissionKey'" ) ;
    }

    /**
     * Adds Casbin policies for every permission of a policy attached
     * to a service.
     *
     * Subject : namespaced `service:{_key}` — see the trait header for
     * why the prefix is load-bearing.
     *
     * @param string $serviceKey The service ArangoDB `_key`.
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function addServicePolicyPolicies( string $serviceKey , string $policyKey ) :void
    {
        if( !$this->enforcer || !$this->policiesModel )
        {
            return ;
        }

        $subject = $this->resolveServiceSubject( $serviceKey ) ;
        $policy  = $this->policiesModel->get
        ([
            Arango::KEY   => Schema::_KEY ,
            Arango::VALUE => $policyKey ,
            Arango::SKIN  => Skin::FULL ,
        ]) ;

        if( !$policy || empty( $policy->permissions ) )
        {
            return ;
        }

        foreach( $policy->permissions as $permission )
        {
            $this->enforcer->addPolicy
            (
                $subject ,
                $permission->domain ?? $this->domain ,
                $permission->object ?? '' ,
                $permission->action ?? '' ,
                $permission->effect ?? PermissionEffect::ALLOW
            ) ;
        }

        $count = count( $policy->permissions ) ;
        $this->logger?->info( "CasbinSync: +$count policies service '$subject' via policy '$policyKey'" ) ;
    }

    /**
     * Called when a service vertex is deleted — wipes every Casbin trace.
     *
     * The payload `data` is the `OLD` document (or list of documents)
     * returned by the ArangoDB `REMOVE` query, so the service's `_key`
     * is still accessible even though the vertex is gone.
     *
     * `Enforcer::deleteUser($subject)` purges every
     * `p, <subject>, <domain>, <object>, <action>, <effect>` policy keyed
     * on the service's namespaced subject — covering both
     * `service_policy` derived policies and `service_permission` direct
     * policies in a single call.
     *
     * @param Payload $payload
     */
    protected function onServiceDelete( Payload $payload ) :void
    {
        $data = $payload->data ?? null ;

        if( !$data )
        {
            return ;
        }

        $services = is_array( $data ) ? $data : [ $data ] ;

        foreach( $services as $service )
        {
            $key = is_object( $service )
                ? ( $service->_key ?? null )
                : ( $service[ '_key' ] ?? null ) ;

            if( !$key )
            {
                continue ;
            }

            $subject = $this->resolveServiceSubject( (string) $key ) ;

            $this->enforcer->deleteUser( $subject ) ;

            $this->logger?->info( "CasbinSync: deleted service '$subject' (purged direct + policy-derived policies)" ) ;
        }
    }

    /**
     * Removes a direct permission policy from a service.
     *
     * @param string $serviceKey The service ArangoDB `_key`.
     * @param string $permissionKey The permission ArangoDB `_key`.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     */
    protected function removeServicePermissionPolicy( string $serviceKey , string $permissionKey ) :void
    {
        if( !$this->enforcer || !$this->permissionsModel )
        {
            return ;
        }

        $subject    = $this->resolveServiceSubject( $serviceKey ) ;
        $permission = $this->permissionsModel->get
        ([
            Arango::KEY   => Schema::_KEY ,
            Arango::VALUE => $permissionKey ,
        ]) ;

        if( !$permission )
        {
            return ;
        }

        $this->enforcer->removePolicy
        (
            $subject ,
            $permission->domain ?? $this->domain ,
            $permission->object ?? '' ,
            $permission->action ?? '' ,
            $permission->effect ?? PermissionEffect::ALLOW
        ) ;

        $this->logger?->info( "CasbinSync: -direct perm service '$subject' → '$permissionKey'" ) ;
    }

    /**
     * Removes Casbin policies for every permission of a policy detached
     * from a service.
     *
     * @param string $serviceKey The service ArangoDB `_key`.
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function removeServicePolicyPolicies( string $serviceKey , string $policyKey ) :void
    {
        if( !$this->enforcer || !$this->policiesModel )
        {
            return ;
        }

        $subject = $this->resolveServiceSubject( $serviceKey ) ;
        $policy  = $this->policiesModel->get
        ([
            Arango::KEY   => Schema::_KEY ,
            Arango::VALUE => $policyKey ,
            Arango::SKIN  => Skin::FULL ,
        ]) ;

        if( !$policy || empty( $policy->permissions ) )
        {
            return ;
        }

        foreach( $policy->permissions as $permission )
        {
            $this->enforcer->removePolicy
            (
                $subject ,
                $permission->domain ?? $this->domain ,
                $permission->object ?? '' ,
                $permission->action ?? '' ,
                $permission->effect ?? PermissionEffect::ALLOW
            ) ;
        }

        $count = count( $policy->permissions ) ;
        $this->logger?->info( "CasbinSync: -$count policies service '$subject' via policy '$policyKey'" ) ;
    }

    /**
     * Returns the list of service `_key`s currently linked to the given
     * policy via the `service_has_policies` edge collection.
     *
     * Used by the policy-permission propagation handlers when a perm
     * is added to / removed from a policy : every service holding
     * that policy needs its Casbin tuples kept in sync.
     *
     * @param string $policyKey The policy ArangoDB `_key`.
     *
     * @return string[] Deduplicated service `_key`s.
     */
    protected function resolveServicesForPolicy( string $policyKey ) :array
    {
        if( !$this->serviceHasPolicies )
        {
            return [] ;
        }

        try
        {
            $edges = $this->serviceHasPolicies->list
            ([
                Arango::CONDITIONS => [ 'doc._to == @policyId' ] ,
                Arango::BINDS      => [ 'policyId' => "policies/$policyKey" ] ,
                Arango::LIMIT      => 1000 ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: failed to list services for policy '$policyKey': " . $e->getMessage() ) ;
            return [] ;
        }

        $serviceKeys = [] ;

        foreach( $edges as $edge )
        {
            $from = is_object( $edge ) ? ( $edge->_from ?? null ) : ( $edge[ '_from' ] ?? null ) ;
            $key  = parseKey( $from ) ;

            if( $key )
            {
                $serviceKeys[ $key ] = true ;
            }
        }

        return array_keys( $serviceKeys ) ;
    }

    /**
     * Resolves the namespaced Casbin subject for a service.
     *
     * The `service:` prefix partitions Service Account RBAC tuples
     * from raw user identifiers — a security load-bearing invariant
     * since a leaked human token must never match a Service Account's
     * RBAC bundle. The middleware writes the same prefix into
     * `ATTR_USER_ID` for incoming JWTs (see
     * {@see \oihana\api\middlewares\CheckJwtAuthentication::process()}).
     *
     * @param string $serviceKey The service ArangoDB `_key`.
     *
     * @return string Casbin-safe subject : `service:{_key}` (passed
     *                through `safeSubject` for the digit-only quirk).
     */
    protected function resolveServiceSubject( string $serviceKey ) :string
    {
        return casbinSafeSubject( 'service:' . $serviceKey ) ;
    }
}
