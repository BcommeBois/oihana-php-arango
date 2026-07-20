<?php

namespace oihana\arango\controllers;

use oihana\arango\enums\Arango;
use oihana\arango\models\interfaces\ArangoDocumentsModel;
use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\traits\AuthorizationContextTrait;
use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerCountTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerDeleteTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerGetTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerLastTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerListTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerPatchTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerPostTrait;
use oihana\arango\controllers\traits\documents\DocumentsControllerPutTrait;

use oihana\auth\controllers\traits\DocumentsControllerCapabilitiesTrait;
use oihana\auth\controllers\traits\PermissionAuthorizerTrait;

use oihana\controllers\Controller;
use oihana\controllers\traits\ModelCallTrait;

/**
 * The Document Controller based on the Arango DB engine.
 *
 * This controller implement the methods :
 * - count()
 * - delete()
 * - get()
 * - last()
 * - list()
 * - post()
 *
 * Capability-aware overrides (skin gating, filter key gating, ...) are bundled
 * in {@see DocumentsControllerCapabilitiesTrait}, opt-in through the
 * `ControllerParam::CAPABILITIES` init block.
 *
 * @property ArangoDocumentsModel $model The ArangoDB documents model (refines the
 *           generic `?DocumentsModel` from ModelTrait), exposing foundRows(),
 *           facetCounts() and bounds() used by the list flow.
 */
class DocumentsController extends Controller
{
    /**
     * Creates a new DocumentsController instance.
     *
     * @param Container             $container The DI Container reference.
     * @param array<string,mixed>   $init      The optional properties to initialize the object.
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
             ->initializeLimit               ( $init )
             ->initializeOwner               ( $init )
             ->initializePayload             ( $init )
             ->initializeSkins               ( $init )
             ->initializeSortDefault         ( $init )
             ->initializeAuthorizationContext( $init ) ;
    }

    use AuthorizationContextTrait            ,
        DocumentsControllerCapabilitiesTrait ,
        DocumentsControllerCountTrait        ,
        DocumentsControllerDeleteTrait       ,
        DocumentsControllerGetTrait          ,
        DocumentsControllerLastTrait         ,
        DocumentsControllerListTrait         ,
        DocumentsControllerPatchTrait        ,
        DocumentsControllerPostTrait         ,
        DocumentsControllerPutTrait          ,
        ModelCallTrait                       ,
        PayloadsTrait                        ,
        PermissionAuthorizerTrait
    {
        DocumentsControllerCapabilitiesTrait::prepareFilter insteadof
            DocumentsControllerCountTrait  ,
            DocumentsControllerGetTrait    ,
            DocumentsControllerLastTrait   ,
            DocumentsControllerListTrait   ,
            DocumentsControllerPatchTrait  ,
            DocumentsControllerPostTrait   ,
            DocumentsControllerPutTrait    ;

        DocumentsControllerCapabilitiesTrait::prepareSearch insteadof
            DocumentsControllerCountTrait  ,
            DocumentsControllerGetTrait    ,
            DocumentsControllerLastTrait   ,
            DocumentsControllerListTrait   ,
            DocumentsControllerPatchTrait  ,
            DocumentsControllerPostTrait   ,
            DocumentsControllerPutTrait    ;

        DocumentsControllerCapabilitiesTrait::prepareSkin insteadof
            DocumentsControllerCountTrait  ,
            DocumentsControllerGetTrait    ,
            DocumentsControllerLastTrait   ,
            DocumentsControllerListTrait   ,
            DocumentsControllerPatchTrait  ,
            DocumentsControllerPostTrait   ,
            DocumentsControllerPutTrait    ;
    }

    /**
     * Injects the request-scoped permission authorizer into the model `$init`
     * payload before every model call.
     *
     * Overrides the no-op {@see ModelCallTrait::beforeModelCall()}
     * hook, invoked automatically around each main model operation (`list`,
     * `get`, `last`, `count`, `insert`, `update`, `replace`, `delete`). It
     * builds a request-scoped `Closure(string $subject): bool` through
     * {@see PermissionAuthorizerTrait::buildPermissionAuthorizer()} — from the
     * capability enforcer and the permission-subject resolver wired in the
     * constructor by {@see AuthorizationContextTrait::initializeAuthorizationContext()} —
     * and stores it under `Arango::AUTHORIZER`, where the projection layer
     * ({@see \oihana\arango\models\helpers\isAuthorized()}) consults it to
     * enforce the field-level `Field::REQUIRES` and definition-level
     * `AQL::REQUIRES` gates.
     *
     * Two guards keep it safe and backward-compatible:
     * - an authorizer already present in `$init` is left untouched (a caller,
     *   a unit test, or a subclass that set one earlier wins) ;
     * - `buildPermissionAuthorizer()` returns `null` when there is no request,
     *   no enforcer, no resolver, or no authenticated user — nothing is then
     *   posed and the projection layer falls open (`isAuthorized()` returns
     *   `true` without an authorizer), so a controller that never carries the
     *   authorization stack keeps its previous behaviour.
     *
     * Consequence for consumers: once the authorization stack and an
     * authenticated user are present, a `Field::REQUIRES` / `AQL::REQUIRES`
     * marker that was previously dormant becomes enforced. Audit existing
     * model definitions for such markers before relying on this.
     *
     * @param Request|null            $request The current PSR-7 request (null in CLI / test contexts).
     * @param array<string,mixed>     $init    The init array forwarded to the model (by reference).
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
            $init[ Arango::AUTHORIZER ] = $authorizer;
        }
    }
}
