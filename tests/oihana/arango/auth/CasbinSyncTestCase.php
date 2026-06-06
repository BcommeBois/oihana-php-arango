<?php

namespace tests\oihana\arango\auth;

use stdClass;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\auth\mocks\FakeDocuments;
use tests\oihana\arango\auth\mocks\FakeEdges;

/**
 * Shared factories for the Casbin-sync trait tests.
 *
 * Provides the small document / edge / permission / policy / role builders
 * reused by every `CasbinPolicySync*TraitTest` so each test can wire just
 * the doubles its sync path needs.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
abstract class CasbinSyncTestCase extends TestCase
{
    /**
     * A {@see FakeDocuments} double for the given collection.
     *
     * @param string $collection
     *
     * @return FakeDocuments
     */
    protected function documents( string $collection ) :FakeDocuments
    {
        return new FakeDocuments( $collection ) ;
    }

    /**
     * A bare edge object carrying a `_from` (and optional `_to`).
     *
     * @param string|null $from
     * @param string|null $to
     *
     * @return stdClass
     */
    protected function edge( ?string $from , ?string $to = null ) :stdClass
    {
        $edge = new stdClass() ;
        $edge->_from = $from ;
        $edge->_to   = $to ;
        return $edge ;
    }

    /**
     * A {@see FakeEdges} double for the given edge collection.
     *
     * @param string $collection
     *
     * @return FakeEdges
     */
    protected function edges( string $collection ) :FakeEdges
    {
        return new FakeEdges( $collection ) ;
    }

    /**
     * A permission document with the four RBAC-tuple fields.
     *
     * @param string      $domain
     * @param string      $object
     * @param string      $action
     * @param string|null $effect Omitted when null (exercises the ALLOW default).
     *
     * @return stdClass
     */
    protected function permission( string $domain , string $object , string $action , ?string $effect = null ) :stdClass
    {
        $perm = new stdClass() ;
        $perm->domain = $domain ;
        $perm->object = $object ;
        $perm->action = $action ;

        if( $effect !== null )
        {
            $perm->effect = $effect ;
        }

        return $perm ;
    }

    /**
     * A policy document holding the given permission list.
     *
     * @param array<int,object> $permissions
     *
     * @return stdClass
     */
    protected function policy( array $permissions ) :stdClass
    {
        $policy = new stdClass() ;
        $policy->permissions = $permissions ;
        return $policy ;
    }

    /**
     * A role document with a stable `identifier` (and optional `name`).
     *
     * @param string      $identifier
     * @param string|null $name
     *
     * @return stdClass
     */
    protected function role( string $identifier , ?string $name = null ) :stdClass
    {
        $role = new stdClass() ;
        $role->identifier = $identifier ;

        if( $name !== null )
        {
            $role->name = $name ;
        }

        return $role ;
    }

    /**
     * A bare document carrying only an `identifier` (user / service shape).
     *
     * @param string $identifier
     *
     * @return stdClass
     */
    protected function withIdentifier( string $identifier ) :stdClass
    {
        $doc = new stdClass() ;
        $doc->identifier = $identifier ;
        return $doc ;
    }

    /**
     * A bare document carrying only a `_key`.
     *
     * @param string $key
     *
     * @return stdClass
     */
    protected function withKey( string $key ) :stdClass
    {
        $doc = new stdClass() ;
        $doc->_key = $key ;
        return $doc ;
    }
}
