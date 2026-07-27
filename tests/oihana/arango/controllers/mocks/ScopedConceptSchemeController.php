<?php

namespace tests\oihana\arango\controllers\mocks;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\ConceptSchemeController;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\filters\FilterLogic;
use oihana\arango\models\enums\filters\FilterParam;

/**
 * Stands in for a **consumer** subclass of {@see ConceptSchemeController}: the lib
 * provides the seat, the consumer provides the rule.
 *
 * It exercises the two levers `list()` honours — a **structured** predicate ANDed
 * into `Arango::FILTER` (the same DSL as the `Documents` surface) and an AQL
 * fragment under `Arango::CONDITIONS` — plus a post-read transformation, so the
 * tests can assert both reach the model and that the client `?filter=` never
 * degrades them.
 *
 * The scope is deliberately meaningless (`__scope`): naming a business concept
 * here would smuggle one into the lib's own test suite.
 *
 * @package tests\oihana\arango\controllers\mocks
 * @author  Marc Alcaraz
 */
class ScopedConceptSchemeController extends ConceptSchemeController
{
    /**
     * The AQL fragment the hook appends to the conditions.
     */
    public const string CONDITION = 'doc.__scope == @__scope' ;

    /**
     * The structured predicate the hook ANDs into the filter.
     */
    public const array PREDICATE = [ FilterParam::KEY => '__scope' , FilterParam::VAL => 'visible' ] ;

    /**
     * The roots {@see afterModelCall()} substitutes into the scheme.
     */
    public const array REPLACEMENT = [ [ '_key' => 'replaced' ] ] ;

    /**
     * Replaces the roots read, proving the hook can transform a result and not
     * merely observe it.
     *
     * @inheritDoc
     */
    protected function afterModelCall( ?Request $request , array &$init , mixed &$result ) :void
    {
        $result = self::REPLACEMENT ;
    }

    /**
     * ANDs the scope predicate into the filter — as a SINGLE operand, so a client
     * `or` group keeps its own parentheses — and appends its AQL fragment to the
     * conditions.
     *
     * @inheritDoc
     */
    protected function beforeModelCall( ?Request $request , array &$init ) :void
    {
        $filter = $init[ Arango::FILTER ] ?? null ;

        $init[ Arango::FILTER     ] = $filter === null ? self::PREDICATE : [ FilterLogic::AND , self::PREDICATE , $filter ] ;
        $init[ Arango::CONDITIONS ] = [ ...( $init[ Arango::CONDITIONS ] ?? [] ) , self::CONDITION ] ;

        parent::beforeModelCall( $request , $init ) ;
    }
}
