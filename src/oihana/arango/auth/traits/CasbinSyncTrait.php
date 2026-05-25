<?php

namespace oihana\arango\auth\traits;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\auth\CasbinPolicySync;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\controllers\helpers\resolveDependency;

/**
 * Shared wiring for controllers that need to invoke {@see CasbinPolicySync}
 * during their lifecycle — typically to clean up policies in the `rbac`
 * collection before a vertex is deleted (cf. PoliciesController +
 * PermissionsController).
 *
 * Provides:
 * - the `CASBIN_SYNC` init key consumers reference via `self::CASBIN_SYNC`
 *   (per Marc's convention, never `CasbinSyncTrait::CASBIN_SYNC`) ;
 * - the `$casbinSync` property typed as the optional service ;
 * - {@see initializeCasbinSync()} a one-line helper that resolves the
 *   service from the constructor `$init` array.
 *
 * Usage:
 * ```php
 * use CasbinSyncTrait ;
 *
 * public function __construct( Container $container , array $init = [] )
 * {
 *     parent::__construct( $container , $init ) ;
 *     $this->initializeCasbinSync( $init , $container ) ;
 * }
 * ```
 *
 * @package oihana\arango\auth\traits
 * @author  Marc Alcaraz
 */
trait CasbinSyncTrait
{
    /**
     * Initialization key for the Casbin policy sync service. Consumers must
     * pass either the {@see CasbinPolicySync} instance directly or its DI
     * container identifier (string) under this key in the `$init` array.
     *
     * Reference from the consumer class as `self::CASBIN_SYNC` — never as
     * `CasbinSyncTrait::CASBIN_SYNC` (PHP 8.2+ forbids the latter).
     */
    public const string CASBIN_SYNC = 'casbinSync' ;

    /**
     * The optional Casbin policy sync service.
     */
    protected ?CasbinPolicySync $casbinSync = null ;

    /**
     * Resolves the Casbin policy sync service from the `$init` array and
     * stores it on `$this->casbinSync`. Safe to call when the key is absent
     * or the dependency is unavailable — the property simply stays `null`
     * and consumers must guard with `if( $this->casbinSync )`.
     *
     * Call from the consumer constructor right after `parent::__construct`.
     *
     * @param array     $init      The constructor init array.
     * @param Container $container The DI container.
     *
     * @return static
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     */
    protected function initializeCasbinSync( array $init , Container $container ) :static
    {
        $this->casbinSync = resolveDependency( $init[ self::CASBIN_SYNC ] ?? null , $container ) ;
        return $this ;
    }
}
