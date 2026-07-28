<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

/**
 * A consumer double whose authorizer **refuses everything**.
 *
 * Where {@see ScopedDocumentsController} narrows *which documents* are reachable,
 * this one closes the other gate: which *fields* of a reachable document are
 * projected (`Field::REQUIRES`). It is what a caller lacking a permission looks
 * like from the model's point of view.
 *
 * The two are deliberately separate doubles: a scope and a projection gate fail
 * in different places, and a test that mixed them could not say which one held.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class GatedDocumentsController extends DocumentsController
{
    /**
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $init[ Arango::AUTHORIZER ] = static fn( string $subject ) :bool => false ;
    }
}
