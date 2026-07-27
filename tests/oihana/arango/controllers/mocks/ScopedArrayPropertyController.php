<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\ArrayPropertyController;
use oihana\arango\enums\Arango;

/**
 * The {@see ScopedPropertyController} counterpart for the element-level
 * operations: same consumer-side rule, applied to an
 * {@see ArrayPropertyController} so the tests can assert the six array
 * operations sit behind the same scope as `get()` and `patch()`.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedArrayPropertyController extends ArrayPropertyController
{
    /**
     * Appends the same scope predicate and bind as {@see ScopedPropertyController},
     * then defers to the parent for the request-scoped authorizer.
     *
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , ScopedPropertyController::CONDITION ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , ...ScopedPropertyController::BINDS ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
