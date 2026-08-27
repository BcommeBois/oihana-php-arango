<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Live validation of the cardinality test on a list of objects named last
 * (`Filter::ARRAY_EXPANSION` with `quant`).
 *
 * The unit cases pin the AQL string; only a server can say what it selects. Two claims
 * need one:
 *
 * - that « no attachment » covers **every** shape of emptiness — the empty list, the
 *   absent attribute and an explicit `null` — which is what a caller asking the question
 *   means, and what spares them any knowledge of how the field was stored ;
 * - 🚨 that the count is honest on a value that is **not a list**. `LENGTH()` of a string
 *   is its character count, so `"oops"` answers 4 to `LENGTH(doc.attachments)` and would
 *   be selected by « at least 3 elements ». The counterfactual is asserted here, next to
 *   the real one, so the guard cannot quietly stop working.
 *
 * The seed is built so that **no assertion below can answer the whole collection**: the
 * six tickets split 2 / 4, so a filter dropped the old way returns six and fails, rather
 * than passing while proving nothing.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ArrayCardinalityIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_array_cardinality_it' ;

    private const string COLLECTION = 'tickets' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tickets = $db->collection( self::COLLECTION ) ;
        $tickets->create() ;

        // t1 holds two attachments, t2 an EMPTY list, t3 says nothing at all, t4 stores
        // an explicit null, t5 holds one — and t6 stores a string where a list was
        // declared, the document a naive LENGTH() miscounts as four elements.
        $tickets->insert( [ '_key' => 't1' , 'attachments' => [ [ 'name' => 'a.pdf' ] , [ 'name' => 'b.pdf' ] ] , 'resolution' => [ 'steps' => [ [ 'dueAt' => '2026-01-05' ] , [ 'dueAt' => '2026-01-09' ] ] ] ] ) ;
        $tickets->insert( [ '_key' => 't2' , 'attachments' => [] ] ) ;
        $tickets->insert( [ '_key' => 't3' ] ) ;
        $tickets->insert( [ '_key' => 't4' , 'attachments' => null ] ) ;
        $tickets->insert( [ '_key' => 't5' , 'attachments' => [ [ 'name' => 'c.pdf' ] ] , 'resolution' => [ 'steps' => [] ] ] ) ;
        $tickets->insert( [ '_key' => 't6' , 'attachments' => 'oops' ] ) ;
    }

    /**
     * @throws ArangoException
     */
    private function keys( string $filter , array $binds = [] ) :array
    {
        $aql    = 'FOR doc IN ' . self::COLLECTION . ' FILTER ' . $filter . ' RETURN doc._key' ;
        $cursor = self::$db->query( $aql , $binds ) ;
        $keys   = array_map( 'strval' , iterator_to_array( $cursor , false ) ) ;
        sort( $keys ) ;
        return $keys ;
    }

    private function model() :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            AQL::COLLECTION => self::COLLECTION ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'attachments' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ 'name' => FilterType::STRING ] ,
                ] ,
                'resolution' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'steps' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'dueAt' => FilterType::STRING ] ,
                        ] ,
                    ] ,
                ] ,
            ]
        ]);
    }

    /**
     * With no `quant`, the question is « at least one » — only the two tickets that
     * really hold something.
     *
     * @throws ArangoException
     */
    public function testAListNamedLastIsExistentialByDefault() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'attachments[*]' ] , $binds ) ;

        $this->assertSame( [ 't1' , 't5' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * « Which tickets have no attachment? » — and the answer covers all four shapes of
     * emptiness at once: the empty list, the absent attribute, the explicit null, and
     * the value that is not a list at all.
     *
     * This is the whole reason `quant` was chosen over a slot-presence test: the caller
     * asks one question and never has to know which of those four the record holds.
     *
     * @throws ArangoException
     */
    public function testNoneCoversEveryShapeOfEmptiness() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 'none' ] , $binds ) ;

        $this->assertSame( [ 't2' , 't3' , 't4' , 't6' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * « At least two » — only the ticket holding two.
     *
     * @throws ArangoException
     */
    public function testAnIntegerQuantifierCountsElements() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 2 ] , $binds ) ;

        $this->assertSame( [ 't1' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * 🚨 The trap, measured — and its counterfactual asserted next to it.
     *
     * t6 stores the string `"oops"` under a list-declared key. Counted naively it has
     * four characters, so the shape this builder does NOT emit selects it for « at
     * least three elements ». Through the expansion it counts zero, which is the
     * honest answer: there is nothing to show.
     *
     * If the builder ever went back to the bare attribute, the first assertion would
     * start returning `['t6']` and this test would say so.
     *
     * @throws ArangoException
     */
    public function testAValueThatIsNotAListCountsZeroRatherThanItsCharacters() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'attachments[*]' , 'quant' => 3 ] , $binds ) ;

        // What the library emits: the count is taken on the expansion.
        $this->assertSame( [] , $this->keys( $filter , $binds ) ) ;

        // What it would have answered on the bare attribute — the shape being avoided.
        $this->assertSame( [ 't6' ] , $this->keys( 'LENGTH(doc.attachments) >= 3' ) ) ;
    }

    /**
     * The deeper seat: a dotted key goes through the hierarchical walk. t5 carries an
     * empty `steps`, so it answers « none » while t1 answers « at least one ».
     *
     * @throws ArangoException
     */
    public function testANestedListNamedLastReachesTheServer() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution.steps[*]' ] , $binds ) ;

        $this->assertSame( [ 't1' ] , $this->keys( $filter , $binds ) ) ;

        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' ] , $binds ) ;

        $this->assertSame( [ 't2' , 't3' , 't4' , 't5' , 't6' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * Filtering the elements of the list is untouched — the terminal case is the only
     * one that moves, and the two usages share one declaration.
     *
     * @throws ArangoException
     */
    public function testFilteringTheElementsIsUnchanged() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'attachments[*].name' , 'val' => 'c.pdf' ] , $binds ) ;

        $this->assertSame( [ 't5' ] , $this->keys( $filter , $binds ) ) ;
    }
}
