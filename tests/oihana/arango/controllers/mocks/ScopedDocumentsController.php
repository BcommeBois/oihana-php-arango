<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\DocumentsController;
use oihana\arango\enums\Arango;

/**
 * Stands in for a **consumer** subclass of {@see DocumentsController}: the lib
 * provides the seat, the consumer provides the rule.
 *
 * It poses a request-scoped predicate and the bind it references, the way a host
 * application does — which is precisely the shape the existence probes used to be
 * blind to, since the hook ran after them.
 *
 * The predicate is deliberately meaningless (`doc.__scope == @__scope`): naming a
 * business concept here would smuggle one into the lib's own test suite.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedDocumentsController extends DocumentsController
{
    /**
     * The bind variables the hook adds alongside its condition.
     */
    public const array BINDS = [ '__scope' => 'visible' ] ;

    /**
     * The AQL predicate the hook appends to the conditions.
     */
    public const string CONDITION = 'doc.__scope == @__scope' ;

    /**
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , self::CONDITION ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , ...self::BINDS ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
