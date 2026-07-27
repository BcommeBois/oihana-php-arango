<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\TraversalController;
use oihana\arango\db\enums\AQL;

/**
 * Stands in for a **consumer** subclass of {@see TraversalController}: the lib
 * provides the seat, the consumer provides the rule.
 *
 * It poses its scope the way the traversal surface requires it — a **compiled AQL
 * fragment** appended to `AQL::FILTER` (a list at this point) with its bind merged
 * into `AQL::BINDS` — because `getVertices()` reads that slot and nothing else.
 * See {@see CompilingTraversalController} for the other way in, through the gated
 * engine.
 *
 * The predicate is deliberately meaningless (`vertex.__scope == @__scope`): naming
 * a business concept here would smuggle one into the lib's own test suite.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedTraversalController extends TraversalController
{
    /**
     * The bind variable the hook adds alongside its fragment.
     */
    public const array BINDS = [ '__scope' => 'visible' ] ;

    /**
     * The AQL fragment the hook appends to the traversal filter.
     */
    public const string FRAGMENT = 'vertex.__scope == @__scope' ;

    /**
     * The vertices {@see afterModelCall()} substitutes into the response.
     */
    public const array REPLACEMENT = [ [ '_key' => 'replaced' ] ] ;

    /**
     * The last result the "after" hook was handed, so a test can assert the hook
     * observes the model's own return value.
     */
    public mixed $seen = false ;

    /**
     * Records the result and replaces it, proving the hook can transform a
     * traversal and not merely observe it.
     *
     * @inheritDoc
     */
    protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
    {
        $this->seen = $result ;
        $result     = self::REPLACEMENT ;
    }

    /**
     * Appends the scope fragment and merges its bind.
     *
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $init[ AQL::FILTER ] = [ ...( $init[ AQL::FILTER ] ?? [] ) , self::FRAGMENT ] ;
        $init[ AQL::BINDS  ] = [ ...( $init[ AQL::BINDS  ] ?? [] ) , ...self::BINDS ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
