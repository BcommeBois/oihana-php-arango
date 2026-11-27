<?php

namespace oihana\arango\auth\traits;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;

use org\schema\constants\Schema;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;
use oihana\reflect\exceptions\ConstantException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Throwable;

use function oihana\arango\helpers\edgeEndKeys;

/**
 * Tells which Casbin rules a role or a service still holds, read from the
 * authorization graph.
 *
 * Casbin keeps **one** rule per `(subject, domain, object, action, effect)`,
 * whatever path of the graph grants it. A role can hold the same permission
 * directly (`role_has_permissions`) and through one or more policies
 * (`role_has_policies` → `policy_has_permissions`) ; a service the same way
 * (`service_has_permissions`, `service_has_policies`). When one of those paths
 * is removed, the live sync must remove the rule **only** when no other path
 * still grants it — otherwise the subject loses a right the graph still gives.
 *
 * The removal handlers run on `afterDelete` : the removed edge is no longer in
 * the graph. A policy deletion runs on `beforeDelete` : its edges are still
 * there, so the handler names the policy to ignore.
 *
 * 🚨 A lookup that fails returns `null`, and the caller removes the rule : a
 * right the subject may have lost is restored by the next full rebuild, while
 * a right kept by mistake would stay granted. The failure is logged as a
 * warning.
 *
 * The trait expects the consumer class to expose the following protected
 * properties (declared on {@see \oihana\arango\auth\CasbinPolicySync}) :
 *  - `string $domain`
 *  - `?Documents $permissionsModel`
 *  - `?Edges $policyHasPermissions`
 *  - `?Edges $roleHasPermissions`
 *  - `?Edges $roleHasPolicies`
 *  - `?Edges $serviceHasPermissions`
 *  - `?Edges $serviceHasPolicies`
 *  - `?LoggerInterface $logger`
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncGrantTrait
{
    /**
     * The most edges or permissions one lookup reads.
     */
    public const int GRANT_LOOKUP_LIMIT = 10000 ;

    /**
     * Removes from a subject the rules of the given permissions it no longer holds.
     *
     * @param string                   $subject     The Casbin subject.
     * @param iterable<object>         $permissions The permissions whose rules may go.
     * @param array<string, true>|null $held        The rules the subject still holds (see {@see self::roleHeldRules()}),
     *                                              `null` when they cannot be read : every rule goes.
     *
     * @return int The number of rules removed.
     */
    protected function removeUnheldRules( string $subject , iterable $permissions , ?array $held ) :int
    {
        $removed = 0 ;

        foreach( $permissions as $permission )
        {
            if( isset( $held[ $this->ruleKey( $permission ) ] ) )
            {
                continue ;
            }

            $this->enforcer->removePolicy( $subject , ...$this->ruleOf( $permission ) ) ;

            $removed++ ;
        }

        return $removed ;
    }

    /**
     * Lists the rules a role still holds through the graph.
     *
     * @param string      $roleKey          The role ArangoDB `_key`.
     * @param string|null $ignoredPolicyKey A policy whose permissions do not count (a policy being deleted).
     *
     * @return array<string, true>|null The keys of the held rules (see {@see CasbinPolicySyncRuleTrait::ruleKey()}), or `null` when the graph cannot be read.
     */
    protected function roleHeldRules( string $roleKey , ?string $ignoredPolicyKey = null ) :?array
    {
        return $this->heldRules( "roles/$roleKey" , $this->roleHasPermissions , $this->roleHasPolicies , $ignoredPolicyKey ) ;
    }

    /**
     * Lists the rules a service still holds through the graph.
     *
     * @param string      $serviceKey       The service ArangoDB `_key`.
     * @param string|null $ignoredPolicyKey A policy whose permissions do not count (a policy being deleted).
     *
     * @return array<string, true>|null The keys of the held rules (see {@see CasbinPolicySyncRuleTrait::ruleKey()}), or `null` when the graph cannot be read.
     */
    protected function serviceHeldRules( string $serviceKey , ?string $ignoredPolicyKey = null ) :?array
    {
        return $this->heldRules( "services/$serviceKey" , $this->serviceHasPermissions , $this->serviceHasPolicies , $ignoredPolicyKey ) ;
    }

    /**
     * Reads the permissions a subject reaches directly and through its policies,
     * and returns the keys of their rules.
     *
     * Four reads at most, whatever the number of permissions : the direct edges,
     * the policy edges, the permissions of those policies, then the permission
     * documents themselves.
     *
     * @param string      $subjectId        The subject `_id` (`roles/…`, `services/…`).
     * @param Edges|null  $direct           The direct subject → permission edges.
     * @param Edges|null  $viaPolicies      The subject → policy edges.
     * @param string|null $ignoredPolicyKey A policy whose permissions do not count.
     *
     * @return array<string, true>|null The held rule keys, or `null` when a read failed.
     */
    private function heldRules( string $subjectId , ?Edges $direct , ?Edges $viaPolicies , ?string $ignoredPolicyKey ) :?array
    {
        if( !$this->permissionsModel )
        {
            return [] ;
        }

        try
        {
            $permissionKeys = $this->targetKeys( $direct , [ $subjectId ] ) ;
            $policyIds      = [] ;

            foreach( $this->targetKeys( $viaPolicies , [ $subjectId ] ) as $policyKey )
            {
                if( $policyKey !== $ignoredPolicyKey )
                {
                    $policyIds[] = "policies/$policyKey" ;
                }
            }

            if( !empty( $policyIds ) )
            {
                $permissionKeys = [ ...$permissionKeys , ...$this->targetKeys( $this->policyHasPermissions , $policyIds ) ] ;
            }

            $permissionKeys = array_values( array_unique( $permissionKeys ) ) ;

            if( empty( $permissionKeys ) )
            {
                return [] ;
            }

            $permissions = $this->permissionsModel->list
            ([
                Arango::CONDITIONS => [ 'doc._key IN @permissionKeys' ] ,
                Arango::BINDS      => [ 'permissionKeys' => $permissionKeys ] ,
                Arango::LIMIT      => self::GRANT_LOOKUP_LIMIT ,
            ]) ;
        }
        catch( Throwable $e )
        {
            $this->logger?->warning( "CasbinSync: cannot read what '$subjectId' still holds, its rules are removed — the next auth:sync:policies restores any still granted: " . $e->getMessage() ) ;
            return null ;
        }

        $held = [] ;

        foreach( $permissions as $permission )
        {
            if( is_object( $permission ) )
            {
                $held[ $this->ruleKey( $permission ) ] = true ;
            }
        }

        return $held ;
    }

    /**
     * Lists the `_key` of the `_to` end of every edge leaving one of the given vertices.
     *
     * @param Edges|null $edges The edge relation, `null` when not wired.
     * @param array<string> $fromIds The `_id` of the start vertices.
     *
     * @return array<string> The `_to` keys.
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     * @throws ConstantException
     */
    private function targetKeys( ?Edges $edges , array $fromIds ) :array
    {
        if( !$edges )
        {
            return [] ;
        }

        return edgeEndKeys
        (
            $edges->list
            ([
                Arango::CONDITIONS => [ 'doc._from IN @fromIds' ] ,
                Arango::BINDS      => [ 'fromIds' => $fromIds ] ,
                Arango::LIMIT      => self::GRANT_LOOKUP_LIMIT ,
            ]) ,
            Schema::_TO
        ) ;
    }
}
