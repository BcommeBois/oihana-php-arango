<?php

namespace oihana\arango\controllers;

use ReflectionException;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\controllers\traits\properties\ArrayPropertyControllerTrait;

/**
 * Exposes the element-level operations of an **embedded array property** of a document
 * (a field declared in the model's `AQL::ARRAYS` option) as REST sub-resources.
 *
 * It extends {@see PropertyController} — inheriting its full wiring plus `get()` (read
 * the whole array) and `patch()` (replace the whole array) — and adds, through
 * {@see ArrayPropertyControllerTrait}:
 *
 * - {@see ArrayPropertyControllerTrait::addItem()}      — `POST   /{collection}/{id}/{property}`
 * - {@see ArrayPropertyControllerTrait::reorderItems()} — `PUT    /{collection}/{id}/{property}`
 * - {@see ArrayPropertyControllerTrait::removeItem()}   — `DELETE /{collection}/{id}/{property}/{value}`
 * - {@see ArrayPropertyControllerTrait::moveItem()}     — `PATCH  /{collection}/{id}/{property}/{value}`
 * - {@see ArrayPropertyControllerTrait::updateItem()}   — `PUT    /{collection}/{id}/{property}/{value}`
 * - {@see ArrayPropertyControllerTrait::hasItem()}      — `GET    /{collection}/{id}/{property}/{value}`
 *
 * `PATCH` and `PUT` share the element path but not an intent: the verb disambiguates
 * them — `PATCH` **moves** the element, `PUT` **edits** it. On the property path, `PUT`
 * replaces the **order** of the whole array.
 *
 * The six routes can be declared at once with {@see ArrayPropertyRoute}.
 *
 * A write answers the array property. Declare {@see self::RESPOND_WITH_OWNER} to make
 * it answer the **owner document** instead, and override
 * {@see ArrayPropertyControllerTrait::afterArrayWrite()} to bring whatever the owner
 * derives from that array up to date before the response is built.
 *
 * @package oihana\arango\controllers
 */
class ArrayPropertyController extends PropertyController
{
    /**
     * Creates a new ArrayPropertyController instance.
     *
     * @param Container $container The DI Container reference.
     * @param array $init The optional properties to passed-in to initialize the object.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function __construct( Container $container , array $init = [] )
    {
        parent::__construct( $container , $init ) ;

        $this->initializeRespondWithOwner( $init ) ;
    }

    use ArrayPropertyControllerTrait ;

    /**
     * The `addItem` controller method name (route binding).
     */
    public const string ADD_ITEM = 'addItem' ;

    /**
     * The `hasItem` controller method name (route binding).
     */
    public const string HAS_ITEM = 'hasItem' ;

    /**
     * The `moveItem` controller method name (route binding).
     */
    public const string MOVE_ITEM = 'moveItem' ;

    /**
     * The `removeItem` controller method name (route binding).
     */
    public const string REMOVE_ITEM = 'removeItem' ;

    /**
     * The `reorderItems` controller method name (route binding).
     */
    public const string REORDER_ITEMS = 'reorderItems' ;

    /**
     * The `updateItem` controller method name (route binding).
     */
    public const string UPDATE_ITEM = 'updateItem' ;
}
