<?php

namespace oihana\arango\controllers\traits;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\auth\CapabilityEnforcerInterface;
use oihana\auth\PermissionSubjectResolverInterface;

/**
 * Resolves a controller's request-scoped authorization context from the DI
 * container — the capability enforcer and the permission-subject resolver —
 * and wires them in, in one place.
 *
 * Both `DocumentsController` and `FederatedSearchController` (and any other
 * capability-aware Arango controller) need the exact same boilerplate right
 * after `parent::__construct()`: look up `CapabilityEnforcerInterface` and
 * `PermissionSubjectResolverInterface` in the container, guard them with an
 * `instanceof`, and hand them to `initializeCapabilities()` /
 * `initializePermissionSubjectResolver()`. This trait factors that out.
 *
 * The consuming class must:
 * - expose `$this->container` (provided by the base `Controller`) ;
 * - provide `initializeCapabilities()` — from
 *   {@see \oihana\auth\controllers\traits\CapabilityContextTrait} (pulled in by
 *   {@see \oihana\auth\controllers\traits\DocumentsControllerCapabilitiesTrait}) ;
 * - provide `initializePermissionSubjectResolver()` — from
 *   {@see \oihana\auth\controllers\traits\PermissionAuthorizerTrait}.
 *
 * Usage:
 * ```php
 * public function __construct( Container $container , array $init = [] )
 * {
 *     parent::__construct( $container , $init ) ;
 *     $this->initializeModel( $init )
 *          // ...
 *          ->initializeAuthorizationContext( $init ) ;
 * }
 * ```
 *
 * @package oihana\arango\controllers\traits
 * @author  Marc Alcaraz (ekameleon)
 */
trait AuthorizationContextTrait
{
    /**
     * Resolves the capability enforcer and the permission-subject resolver from
     * the container (each guarded by an `instanceof`, null when absent) and
     * wires them through `initializeCapabilities()` and
     * `initializePermissionSubjectResolver()`.
     *
     * @param array<string,mixed> $init Same array passed to the controller constructor.
     *
     * @return static
     *
     * @throws DependencyException
     * @throws NotFoundException
     */
    protected function initializeAuthorizationContext( array $init = [] ) : static
    {
        $resolved = $this->container->has( CapabilityEnforcerInterface::class )
                  ? $this->container->get( CapabilityEnforcerInterface::class )
                  : null ;

        $enforcer = $resolved instanceof CapabilityEnforcerInterface ? $resolved : null ;

        $resolvedSubject = $this->container->has( PermissionSubjectResolverInterface::class )
                         ? $this->container->get( PermissionSubjectResolverInterface::class )
                         : null ;

        $subjectResolver = $resolvedSubject instanceof PermissionSubjectResolverInterface ? $resolvedSubject : null ;

        return $this->initializeCapabilities( $init , $enforcer )
                    ->initializePermissionSubjectResolver( $subjectResolver ) ;
    }
}
