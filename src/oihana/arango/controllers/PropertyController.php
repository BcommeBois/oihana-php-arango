<?php

namespace oihana\arango\controllers;

use oihana\arango\controllers\traits\properties\PropertyControllerPatchTrait;
use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\controllers\traits\properties\PropertyControllerGetTrait;
use oihana\controllers\Controller;

/**
 * The Property Controller based on the Arango DB engine.
 *
 * Use a Documents model to read (get) or update (patch) a property in a Document.
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

        $this->initializeModel     ( $init )
             ->initializeLanguages ( $init , $container )
             ->initializeOwner     ( $init )
             ->initializePayload   ( $init )
             ->initializeProperty  ( $init )
             ->initializeSkins     ( $init ) ;
    }

    use PayloadsTrait ,
        PropertyControllerGetTrait   ,
        PropertyControllerPatchTrait ;
}