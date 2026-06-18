<?php

namespace oihana\arango\controllers;

use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

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

    use AuthorizationContextTrait        ,
        DocumentsControllerCapabilitiesTrait ,
        DocumentsControllerCountTrait  ,
        DocumentsControllerDeleteTrait ,
        DocumentsControllerGetTrait    ,
        DocumentsControllerLastTrait   ,
        DocumentsControllerListTrait   ,
        DocumentsControllerPatchTrait  ,
        DocumentsControllerPostTrait   ,
        DocumentsControllerPutTrait    ,
        ModelCallTrait                 ,
        PayloadsTrait                  ,
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
}
