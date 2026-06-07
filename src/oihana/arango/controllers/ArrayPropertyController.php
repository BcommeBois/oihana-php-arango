<?php

namespace oihana\arango\controllers;

use oihana\arango\controllers\traits\properties\ArrayPropertyControllerTrait;

/**
 * Exposes the element-level operations of an **embedded array property** of a document
 * (a field declared in the model's `AQL::ARRAYS` option) as REST sub-resources.
 *
 * It extends {@see PropertyController} — inheriting its full wiring plus `get()` (read
 * the whole array) and `patch()` (replace the whole array) — and adds, through
 * {@see ArrayPropertyControllerTrait}:
 *
 * - {@see ArrayPropertyControllerTrait::addItem()}    — `POST   /{collection}/{id}/{property}`
 * - {@see ArrayPropertyControllerTrait::removeItem()} — `DELETE /{collection}/{id}/{property}/{value}`
 * - {@see ArrayPropertyControllerTrait::moveItem()}   — `PATCH  /{collection}/{id}/{property}/{value}`
 * - {@see ArrayPropertyControllerTrait::hasItem()}    — `GET    /{collection}/{id}/{property}/{value}`
 *
 * The four routes can be declared at once with {@see ArrayPropertyRoute}.
 *
 * @package oihana\arango\controllers
 */
class ArrayPropertyController extends PropertyController
{
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
}
