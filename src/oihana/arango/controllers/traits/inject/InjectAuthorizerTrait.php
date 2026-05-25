<?php

namespace oihana\arango\controllers\traits\inject;

use Closure;

use oihana\arango\enums\Arango;

use function oihana\core\callables\resolveCallable;

/**
 * Provides the plumbing to attach a permission authorizer to ArangoDB
 * controllers — used by the framework to gate fields via `Field::REQUIRES`
 * without coupling `oihana/arango` to a specific authorization backend
 * (Casbin, OPA, custom, ...).
 *
 * Lifecycle:
 * - {@see self::initializeArangoAuthorizer()} is called once at construction
 *   time, typically right after `parent::__construct()`. The controller
 *   resolves a `Closure(string $subject): bool` from the DI container (or
 *   provides it explicitly) and hands it to the trait.
 * - {@see self::injectAuthorizer()} is called every time the controller
 *   forges an `$init` array bound for `$this->model->list/get/...`. It poses
 *   the stored authorizer under `Arango::AUTHORIZER` so that the underlying
 *   `buildVariables` / `buildEdgeVariable` / `buildJoinVariable` chain can
 *   consult it via {@see \oihana\arango\models\helpers\isAuthorized()}.
 *
 * When no authorizer was registered, {@see self::injectAuthorizer()} is a
 * no-op — the framework's `isAuthorized()` falls open in that case, so
 * existing controllers that do not opt in keep their current behaviour.
 *
 * Usage in a controller:
 * ```php
 * use oihana\arango\controllers\traits\inject\InjectAuthorizerTrait;
 *
 * final class MyController extends DocumentsController
 * {
 *     use InjectAuthorizerTrait ;
 *
 *     public function __construct( Container $container , array $init = [] )
 *     {
 *         parent::__construct( $container , $init ) ;
 *
 *         $authorizer = $container->has( Definition::ARANGO_AUTHORIZER )
 *             ? $container->get( Definition::ARANGO_AUTHORIZER )
 *             : null ;
 *
 *         $this->initializeArangoAuthorizer( $init , $authorizer ) ;
 *     }
 *
 *     public function list( ?Request $req , ?Response $res , array $args = [] , array $init = [] ) : mixed
 *     {
 *         $this->injectAuthorizer( $init ) ;
 *         return parent::list( $req , $res , $args , $init ) ;
 *     }
 * }
 * ```
 *
 * @see \oihana\arango\models\helpers\isAuthorized()
 *
 * @package oihana\arango\controllers\traits\inject
 * @author  Marc Alcaraz
 */
trait InjectAuthorizerTrait
{
    /**
     * Stored authorizer, resolved at init time. Null when no authorizer was
     * registered — every {@see self::injectAuthorizer()} call becomes a no-op.
     *
     * The callable signature is `Closure(string $subject): bool` ; only a
     * strict `true` return counts as a grant in
     * {@see \oihana\arango\models\helpers\isAuthorized()}.
     */
    protected ?Closure $arangoAuthorizer = null ;

    /**
     * Initialise the trait from a controller's `$init` array.
     *
     * Resolution order:
     * 1. Explicit `$authorizer` argument (the controller resolved a service
     *    from the DI container or built the closure inline).
     * 2. `$init[Arango::AUTHORIZER]` if it carries a value.
     * 3. Otherwise, the trait stays disarmed (`$arangoAuthorizer = null`).
     *
     * The candidate is run through {@see resolveCallable()} so any of the
     * supported shapes (Closure, invokable object, `Class::method`,
     * `[obj, 'method']`, fully-qualified function name) is accepted ; a
     * non-resolvable value silently disarms the trait.
     *
     * @param array<array-key,mixed>  $init       Same array passed to the controller constructor.
     * @param string|array|object|null $authorizer Optional explicit candidate. Takes precedence over `$init`.
     *
     * @return static
     */
    protected function initializeArangoAuthorizer( array $init , string|array|object|null $authorizer = null ) : static
    {
        $candidate = $authorizer ?? ( $init[ Arango::AUTHORIZER ] ?? null ) ;

        $resolved = is_string( $candidate ) || is_array( $candidate ) || is_object( $candidate )
            ? resolveCallable( $candidate )
            : null ;

        $this->arangoAuthorizer = $resolved !== null ? $resolved(...) : null ;

        return $this ;
    }

    /**
     * Pose the stored authorizer under `Arango::AUTHORIZER` so the framework
     * helpers ({@see \oihana\arango\models\helpers\isAuthorized()}) can
     * consult it when building edges/joins.
     *
     * No-op when no authorizer was registered, or when `$init` already
     * carries an entry under that key (a more specific call site wins —
     * useful for tests or for a per-call override).
     *
     * @param array<array-key,mixed> $init The init array to enrich (by reference).
     */
    protected function injectAuthorizer( array &$init ) : void
    {
        if ( $this->arangoAuthorizer === null )
        {
            return ;
        }

        if ( array_key_exists( Arango::AUTHORIZER , $init ) )
        {
            return ;
        }

        $init[ Arango::AUTHORIZER ] = $this->arangoAuthorizer ;
    }
}
