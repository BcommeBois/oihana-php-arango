<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\PropertyController;
use oihana\arango\enums\Arango;

/**
 * Stands in for a **consumer** subclass: the lib provides the seat, the consumer
 * provides the rule.
 *
 * It overrides the two lifecycle hooks the way a host application would — a
 * predicate appended to `Arango::CONDITIONS`, its bind variable added to
 * `Arango::BINDS`, and a post-read transformation — so the tests can assert the
 * enrichment reaches every model call the controller makes.
 *
 * The predicate is deliberately meaningless (`doc.__scope == @__scope`): naming a
 * business concept here would smuggle one into the lib's own test suite.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedPropertyController extends PropertyController
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
     * The property value {@see afterModelCall()} substitutes into the read document.
     */
    public const array REPLACEMENT = [ 'replaced@x' ] ;

    /**
     * Replaces the property of the document read, proving the hook can transform
     * a result and not merely observe it.
     *
     * @inheritDoc
     */
    protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
    {
        if ( is_object( $result ) )
        {
            $result->emails = self::REPLACEMENT ;
        }
    }

    /**
     * Appends the scope predicate and its bind, then defers to the parent for the
     * request-scoped authorizer.
     *
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , self::CONDITION ] ;
        $init[ Arango::BINDS      ] = [ ...( $init[ Arango::BINDS      ] ?? [] ) , ...self::BINDS ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
