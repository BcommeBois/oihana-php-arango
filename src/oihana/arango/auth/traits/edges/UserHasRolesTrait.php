<?php

namespace oihana\arango\auth\traits\edges;

use DateInvalidTimeZoneException;
use DateMalformedStringException;
use DI\Container;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\models\Edges;

use oihana\exceptions\BindException;
use oihana\exceptions\http\Error409;
use oihana\exceptions\UnsupportedOperationException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;
use Throwable;
use function oihana\arango\models\helpers\edges\getEdges;

/**
 * Standalone trait for the `user_has_roles` Edges model dependency.
 *
 * @package oihana\arango\auth\traits\edges
 * @author  Marc Alcaraz
 */
trait UserHasRolesTrait
{
    /**
     * Initialization key for the user_has_roles Edges model.
     */
    public const string USER_HAS_ROLES = 'userHasRoles' ;

    /**
     * The user_has_roles Edges model.
     */
    protected ?Edges $userHasRoles = null ;

    /**
     * Assign specific roles to a newly created user.
     *
     * Creates edges in ArangoDB. The user→role grouping is then materialized
     * into Casbin by the model-layer signals; Zitadel is not involved (RBAC
     * lives in Arango + Casbin).
     *
     * @param string $userKey The ArangoDB _key of the created user.
     * @param array $roleKeys The list of role _keys to assign.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws DateInvalidTimeZoneException
     * @throws DateMalformedStringException
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws UnsupportedOperationException
     */
    protected function assignRoles( string $userKey , array $roleKeys ) :void
    {
        if( !$this->userHasRoles )
        {
            return ;
        }

        foreach( $roleKeys as $roleKey )
        {
            try
            {
                $this->userHasRoles->insertEdge( $userKey , $roleKey ) ;
            }
            catch( Error409 )
            {
                // Edge already exists, ignore
            }
        }
    }

    /**
     * Initializes the user_has_roles edges dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeUserHasRoles( array $init , ?Container $container ) :static
    {
        $this->userHasRoles = getEdges( $init , $container , self::USER_HAS_ROLES ) ;
        return $this ;
    }
}
