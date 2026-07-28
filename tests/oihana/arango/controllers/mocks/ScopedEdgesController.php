<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\EdgesController;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

/**
 * Stands in for a **consumer** subclass of {@see EdgesController}: the lib provides
 * the seat, the consumer provides the rule.
 *
 * It branches on {@see EdgesController::CALL} — the whole point of that key — and
 * poses a **different** predicate per collection, the way a host does: the two
 * vertex collections are narrowed through `Arango::CONDITIONS` (what `exist()`
 * reads), the edge collection through `AQL::FILTER` (what `existEdge()` and
 * `deleteEdge()` read). A single undiscriminated predicate would be meaningless
 * on at least two of the three.
 *
 * The predicates are deliberately meaningless (`__scope`): naming a business
 * concept here would smuggle one into the lib's own test suite.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedEdgesController extends EdgesController
{
    /**
     * The bind the vertex predicates reference.
     */
    public const array BINDS = [ '__scope' => 'visible' ] ;

    /**
     * The AQL fragment narrowing the edge collection.
     */
    public const string EDGE_FILTER = 'doc.__scope == @__scope' ;

    /**
     * The predicate narrowing the source vertices.
     */
    public const string FROM_CONDITION = 'doc.__from_scope == @__scope' ;

    /**
     * The predicate narrowing the target vertices.
     */
    public const string TO_CONDITION = 'doc.__to_scope == @__scope' ;

    /**
     * The last result the "after" hook was handed, so a test can assert the hook
     * observes the model's own return value.
     */
    public mixed $seen = false ;

    /**
     * @inheritDoc
     */
    protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
    {
        $this->seen = $result ;
    }

    /**
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $condition = match ( $init[ self::CALL ] ?? null )
        {
            self::FROM => self::FROM_CONDITION ,
            self::TO   => self::TO_CONDITION ,
            default    => null ,
        } ;

        if ( $condition !== null )
        {
            $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , $condition ] ;
            $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , ...self::BINDS ] ;
        }
        else if ( ( $init[ self::CALL ] ?? null ) === self::EDGES )
        {
            // The edge collection is reached through the traversal filter slot,
            // not through Arango::CONDITIONS : existEdge() / deleteEdge() read
            // AQL::FILTER + AQL::BINDS.
            $init[ AQL::FILTER ] = [ ...( $init[ AQL::FILTER ] ?? [] ) , self::EDGE_FILTER ] ;
            $init[ AQL::BINDS  ] = [ ...( $init[ AQL::BINDS  ] ?? [] ) , ...self::BINDS ] ;
        }

        parent::beforeModelCall( $request , $init ) ;
    }
}
