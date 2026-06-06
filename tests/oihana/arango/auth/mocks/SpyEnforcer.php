<?php

namespace tests\oihana\arango\auth\mocks;

use Casbin\Enforcer;

/**
 * Lightweight spy for the Casbin {@see Enforcer}.
 *
 * Bypasses the real Casbin bootstrap (model + adapter) by overriding the
 * constructor with a no-op, and records every write call
 * (`addPolicy` / `removePolicy` / `addGroupingPolicy` /
 * `removeGroupingPolicy` / `deleteRole` / `deleteUser`) into {@see $calls}
 * so the Casbin-sync traits can be asserted without an in-memory enforcer.
 *
 * The `add*` / `remove*` return values are configurable to exercise the
 * idempotency branch of {@see \oihana\arango\auth\traits\CasbinPolicySyncPolicyTrait::addPolicyPermissionPolicy()}.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class SpyEnforcer extends Enforcer
{
    /**
     * Every recorded write call as `[ method , [ ...args ] ]`.
     *
     * @var array<int,array{0:string,1:array}>
     */
    public array $calls = [] ;

    /**
     * Value returned by {@see addPolicy()} (mimics Casbin's idempotency flag).
     */
    public bool $addPolicyReturn = true ;

    /**
     * Value returned by {@see removePolicy()}.
     */
    public bool $removePolicyReturn = true ;

    /**
     * Bypasses the Casbin enforcer bootstrap entirely.
     */
    public function __construct()
    {
        // no-op : no model / adapter wiring needed for the spy.
    }

    /**
     * Records an `addPolicy` call.
     *
     * @param mixed ...$params
     *
     * @return bool The configured {@see $addPolicyReturn}.
     */
    public function addPolicy( ...$params ) :bool
    {
        $this->calls[] = [ 'addPolicy' , $params ] ;
        return $this->addPolicyReturn ;
    }

    /**
     * Records an `addGroupingPolicy` call.
     *
     * @param mixed ...$params
     *
     * @return bool Always true.
     */
    public function addGroupingPolicy( ...$params ) :bool
    {
        $this->calls[] = [ 'addGroupingPolicy' , $params ] ;
        return true ;
    }

    /**
     * Records a `deleteRole` call.
     *
     * @param string $role
     *
     * @return bool Always true.
     */
    public function deleteRole( string $role ) :bool
    {
        $this->calls[] = [ 'deleteRole' , [ $role ] ] ;
        return true ;
    }

    /**
     * Records a `deleteUser` call.
     *
     * @param string $user
     *
     * @return bool Always true.
     */
    public function deleteUser( string $user ) :bool
    {
        $this->calls[] = [ 'deleteUser' , [ $user ] ] ;
        return true ;
    }

    /**
     * Records a `removeGroupingPolicy` call.
     *
     * @param mixed ...$params
     *
     * @return bool Always true.
     */
    public function removeGroupingPolicy( ...$params ) :bool
    {
        $this->calls[] = [ 'removeGroupingPolicy' , $params ] ;
        return true ;
    }

    /**
     * Records a `removePolicy` call.
     *
     * @param mixed ...$params
     *
     * @return bool The configured {@see $removePolicyReturn}.
     */
    public function removePolicy( ...$params ) :bool
    {
        $this->calls[] = [ 'removePolicy' , $params ] ;
        return $this->removePolicyReturn ;
    }

    /**
     * The ordered list of recorded method names.
     *
     * @return string[]
     */
    public function names() :array
    {
        return array_map( fn( array $call ) => $call[ 0 ] , $this->calls ) ;
    }

    /**
     * The recorded calls for a single method name.
     *
     * @param string $method
     *
     * @return array<int,array> The argument lists, in call order.
     */
    public function callsFor( string $method ) :array
    {
        $out = [] ;

        foreach( $this->calls as [ $name , $args ] )
        {
            if( $name === $method )
            {
                $out[] = $args ;
            }
        }

        return $out ;
    }
}
