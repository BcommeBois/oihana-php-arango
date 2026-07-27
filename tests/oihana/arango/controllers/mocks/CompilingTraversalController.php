<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\TraversalController;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

/**
 * The other way a consumer poses a scope on the traversal: instead of writing the
 * AQL by hand ({@see ScopedTraversalController}), it hands a **JSON predicate** to
 * the protected {@see TraversalController::compileVertexPredicate()}, so the scope
 * goes through the very same `AQL::FILTERS` whitelist and `Field::REQUIRES` gate as
 * the client `?filter=`.
 *
 * It also stands for the two call-time rules: the binds are **merged** into the
 * array handed in (never overwritten), and a `null` compilation is a wiring error
 * — here it refuses to fall back to "no scope".
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class CompilingTraversalController extends TraversalController
{
    /**
     * The JSON predicate the hook compiles into a fragment.
     */
    public const array PREDICATE = [ 'key' => '__scope' , 'op' => 'eq' , 'val' => 'visible' ] ;

    /**
     * Set when the compilation yielded nothing — the attribute is not declared
     * filterable, which a consumer must treat as a wiring error, never as an
     * absent scope.
     */
    public bool $refused = false ;

    /**
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $binds    = $init[ AQL::BINDS ] ?? [] ;
        $fragment = $this->compileVertexPredicate( self::PREDICATE , $init[ Arango::AUTHORIZER ] ?? null , $binds ) ;

        if ( $fragment === null )
        {
            $this->refused = true ;
            return ;
        }

        $init[ AQL::FILTER ] = [ ...( $init[ AQL::FILTER ] ?? [] ) , $fragment ] ;
        $init[ AQL::BINDS  ] = $binds ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
