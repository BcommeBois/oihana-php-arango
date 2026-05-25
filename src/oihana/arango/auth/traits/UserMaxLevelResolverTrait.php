<?php

namespace oihana\arango\auth\traits;

use DI\Container;

use oihana\arango\auth\UserMaxLevelResolver;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\controllers\helpers\resolveDependency;

/**
 * Standalone trait for the {@see UserMaxLevelResolver} dependency.
 *
 * Mirrors the convention used by the other auth model / edge / service
 * traits — a single init key, a typed nullable property, and a fluent
 * `initialize…()` method that the consuming class chains in its
 * constructor.
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait UserMaxLevelResolverTrait
{
    /**
     * `$init` key carrying the DI identifier of the resolver service.
     */
    public const string USER_MAX_LEVEL_RESOLVER = 'userMaxLevelResolver' ;

    /**
     * The resolver instance, or `null` when the auth feature is
     * disabled / the resolver is not wired in DI.
     */
    protected ?UserMaxLevelResolver $userMaxLevelResolver = null ;

    /**
     * Initializes the user max-level resolver dependency from the
     * `$init` array.
     *
     * @param array          $init      The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeUserMaxLevelResolver( array $init , ?Container $container ) :static
    {
        $resolved = resolveDependency( $init[ self::USER_MAX_LEVEL_RESOLVER ] ?? null , $container ) ;
        $this->userMaxLevelResolver = $resolved instanceof UserMaxLevelResolver ? $resolved : null ;
        return $this ;
    }
}
