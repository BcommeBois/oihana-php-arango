<?php

namespace oihana\arango\controllers;

use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\AuthorizationContextTrait;
use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\controllers\traits\properties\PropertyControllerGetTrait;
use oihana\arango\controllers\traits\properties\PropertyControllerPatchTrait;
use oihana\arango\enums\Arango;

use oihana\auth\controllers\traits\CapabilityContextTrait;
use oihana\auth\controllers\traits\PermissionAuthorizerTrait;

use oihana\controllers\Controller;
use oihana\controllers\traits\ModelCallTrait;

/**
 * The Property Controller based on the Arango DB engine.
 *
 * Use a Documents model to read (get) or update (patch) a property in a Document.
 *
 * Like {@see DocumentsController}, it carries an **authorization seat**: the
 * capability enforcer and the permission-subject resolver are resolved from the
 * container at construction time, and every model call is wrapped by the
 * {@see ModelCallTrait} hooks. The class itself only poses the request-scoped
 * authorizer — a subclass overriding {@see beforeModelCall()} is what turns the
 * seat into an actual scope (extra `Arango::CONDITIONS`, extra `Arango::BINDS`),
 * both of which the model already honours.
 *
 * **Every query carries the scope.** The hook runs around each read, around the
 * existence probe that precedes each write, and around the write itself — whose
 * `FILTER` now reads the same `Arango::CONDITIONS` as the reads. A document
 * outside the scope is therefore reported missing by the probe, and would match
 * nothing in the `UPDATE` even if it slipped past it: the two no longer have to
 * agree across a window in which the document may have moved.
 *
 * The six array operations of {@see ArrayPropertyController} are the exception:
 * they build their own `FILTER` and do not read `Arango::CONDITIONS`, so the
 * scoped existence probe remains their only gate.
 */
class PropertyController extends Controller
{
    /**
     * Creates a new DocumentsController instance.
     *
     * @param Container $container The DI Container reference.
     * @param array $init The optional properties to passed-in to initialize the object.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;

        $this->initializeModel               ( $init )
             ->initializeLanguages           ( $init , $container )
             ->initializeOwner               ( $init )
             ->initializePayload             ( $init )
             ->initializeProperty            ( $init )
             ->initializeSkins               ( $init )
             ->initializeAuthorizationContext( $init ) ;
    }

    use AuthorizationContextTrait    ,
        CapabilityContextTrait       ,
        ModelCallTrait               ,
        PayloadsTrait                ,
        PermissionAuthorizerTrait    ,
        PropertyControllerGetTrait   ,
        PropertyControllerPatchTrait ;

    /**
     * Injects the request-scoped permission authorizer into the model `$init`
     * payload before every model call.
     *
     * Overrides the no-op {@see ModelCallTrait::beforeModelCall()}, invoked
     * around each model call of this controller — `get()`, the post-write reload,
     * the `update()` of `patch()`, and the existence probe that gates `patch()`
     * and the six array operations of {@see ArrayPropertyController}. It builds a
     * request-scoped `Closure(string $subject): bool` through
     * {@see PermissionAuthorizerTrait::buildPermissionAuthorizer()} and stores it
     * under `Arango::AUTHORIZER`, where the projection layer
     * ({@see \oihana\arango\models\helpers\isAuthorized()}) consults it to enforce
     * the field-level `Field::REQUIRES` and definition-level `AQL::REQUIRES` gates.
     *
     * Strictly the behaviour of {@see DocumentsController::beforeModelCall()},
     * with the same two guards:
     * - an authorizer already present in `$init` is left untouched (a caller, a
     *   unit test, or a subclass that set one earlier wins) ;
     * - `buildPermissionAuthorizer()` returns `null` when there is no request, no
     *   enforcer, no resolver, or no authenticated user — nothing is then posed
     *   and the projection layer falls open, so a controller that never carries
     *   the authorization stack (CLI, tests) keeps its previous behaviour.
     *
     * @param Request|null        $request The current PSR-7 request (null in CLI / test contexts).
     * @param array<string,mixed> $init    The init array forwarded to the model (by reference).
     *
     * @return void
     *
     * @see PermissionAuthorizerTrait::buildPermissionAuthorizer()
     * @see \oihana\arango\models\helpers\isAuthorized()
     */
    protected function beforeModelCall( ?Request $request , array &$init ) : void
    {
        if ( !array_key_exists( Arango::AUTHORIZER , $init ) && ( $authorizer = $this->buildPermissionAuthorizer( $request ) ) !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
    }
}
