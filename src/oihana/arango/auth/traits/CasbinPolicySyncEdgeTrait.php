<?php

namespace oihana\arango\auth\traits;


use ReflectionException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\models\Edges;
use oihana\auth\enums\EdgeSyncType;
use oihana\exceptions\BindException;
use oihana\signals\notices\Payload;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\helpers\parseKey;

/**
 * Casbin policy synchronization edge dispatcher.
 *
 * Subscribes to a generic edge model's `afterInsert` / `afterDelete`
 * signals and routes the events to the right per-domain handler based
 * on the {@see EdgeSyncType} value passed at registration time. Pure
 * router : every actual write to the Casbin Enforcer lives in one of
 * the per-domain traits ({@see CasbinPolicySyncRoleTrait},
 * {@see CasbinPolicySyncUserTrait},
 * {@see CasbinPolicySyncServiceTrait},
 * {@see CasbinPolicySyncPolicyTrait}).
 *
 * The trait expects the consumer class to expose the protected
 * `?Enforcer $enforcer` property (declared on
 * {@see \oihana\arango\auth\CasbinPolicySync} via constructor promotion)
 * plus every per-domain `add*` / `remove*` handler reachable through
 * trait composition on the same class.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinPolicySyncEdgeTrait
{
    /**
     * Registers this sync on an edge model's insert/delete signals.
     *
     * @param Edges  $edges The edge model to listen to.
     * @param string $type  The edge type — one of {@see EdgeSyncType}'s constants.
     */
    public function register( Edges $edges , string $type ) :void
    {
        if( !$this->enforcer )
        {
            return ;
        }

        $edges->afterInsert?->connect( fn( Payload $p ) => $this->onEdgeInsert( $p , $type ) ) ;
        $edges->afterDelete?->connect( fn( Payload $p ) => $this->onEdgeDelete( $p , $type ) ) ;
    }

    /**
     * Called when an edge is deleted.
     *
     * @param Payload $payload
     * @param string $type
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function onEdgeDelete( Payload $payload , string $type ) :void
    {
        $data = $payload->data ;

        if( !$data )
        {
            return ;
        }

        $edges = is_array( $data ) ? $data : [ $data ] ;

        foreach( $edges as $edge )
        {
            $from = parseKey( is_object( $edge ) ? ( $edge->_from ?? null ) : ( $edge[ '_from' ] ?? null ) ) ;
            $to   = parseKey( is_object( $edge ) ? ( $edge->_to   ?? null ) : ( $edge[ '_to'   ] ?? null ) ) ;

            if( !$from || !$to )
            {
                continue ;
            }

            match( $type )
            {
                EdgeSyncType::POLICY_PERMISSION  => $this->removePolicyPermissionPolicy  ( $from , $to ) ,
                EdgeSyncType::ROLE_PERMISSION    => $this->removeRolePermissionPolicy    ( $from , $to ) ,
                EdgeSyncType::ROLE_POLICY        => $this->removeRolePolicyPolicies      ( $from , $to ) ,
                EdgeSyncType::SERVICE_PERMISSION => $this->removeServicePermissionPolicy ( $from , $to ) ,
                EdgeSyncType::SERVICE_POLICY     => $this->removeServicePolicyPolicies   ( $from , $to ) ,
                EdgeSyncType::USER_ROLE          => $this->removeUserRoleGrouping        ( $from , $to ) ,
                EdgeSyncType::USER_PERMISSION    => $this->removeUserPermissionPolicy    ( $from , $to ) ,
                default                          => null
            } ;
        }
    }

    /**
     * Called when a new edge is inserted.
     *
     * @param Payload $payload
     * @param string $type
     *
     * @return void
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function onEdgeInsert( Payload $payload , string $type ) :void
    {
        $edge = $payload->data ;

        // The signal payload is `mixed`; an edge sync only makes sense for a
        // document object carrying _from/_to (a bare string / null is a no-op).
        if( !is_object( $edge ) )
        {
            return ;
        }

        $from = parseKey( $edge->_from ?? null ) ;
        $to   = parseKey( $edge->_to   ?? null ) ;

        if( !$from || !$to )
        {
            return ;
        }

        match( $type )
        {
            EdgeSyncType::POLICY_PERMISSION  => $this->addPolicyPermissionPolicy ( $from , $to ) ,
            EdgeSyncType::ROLE_PERMISSION    => $this->addRolePermissionPolicy   ( $from , $to ) ,
            EdgeSyncType::ROLE_POLICY        => $this->addRolePolicyPolicies     ( $from , $to ) ,
            EdgeSyncType::USER_PERMISSION    => $this->addUserPermissionPolicy   ( $from , $to ) ,
            EdgeSyncType::SERVICE_PERMISSION => $this->addServicePermissionPolicy( $from , $to ) ,
            EdgeSyncType::SERVICE_POLICY     => $this->addServicePolicyPolicies  ( $from , $to ) ,
            EdgeSyncType::USER_ROLE          => $this->addUserRoleGrouping       ( $from , $to ) ,
            default                          => null
        } ;
    }
}
