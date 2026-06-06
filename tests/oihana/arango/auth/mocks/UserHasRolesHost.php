<?php

namespace tests\oihana\arango\auth\mocks;

use oihana\arango\auth\traits\edges\UserHasRolesTrait;
use oihana\arango\models\Edges;

/**
 * Minimal host composing {@see UserHasRolesTrait} for unit testing its
 * `assignRoles()` cascade in isolation.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class UserHasRolesHost
{
    use UserHasRolesTrait ;

    /**
     * @param Edges|null $userHasRoles
     */
    public function __construct( ?Edges $userHasRoles = null )
    {
        $this->userHasRoles = $userHasRoles ;
    }

    /**
     * Public proxy for {@see UserHasRolesTrait::assignRoles()}.
     *
     * @param string $userKey
     * @param array  $roleKeys
     *
     * @return void
     */
    public function callAssignRoles( string $userKey , array $roleKeys ) :void
    {
        $this->assignRoles( $userKey , $roleKeys ) ;
    }
}
