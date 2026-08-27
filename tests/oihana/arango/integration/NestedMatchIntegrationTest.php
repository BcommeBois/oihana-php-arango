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
 * Live validation that a `match` behind an object still tests the elements.
 *
 * The unit cases pin the AQL string; only a server says which tickets come back, and
 * that is where the defect actually hurt. A `match` nested behind an object was
 * replaced by a bare cardinality, so the caller was not refused — they were
 * **answered**, with the tickets carrying *at least one* step instead of those
 * carrying a step *satisfying their conditions*.
 *
 * The seed therefore holds a ticket that has steps but **none matching**: it is the
 * one document that tells the two questions apart, and every case below asserts the
 * **counterfactual** beside the real one so the difference is written down.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class NestedMatchIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_nested_match_it' ;

    private const string COLLECTION = 'tickets' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tickets = $db->collection( self::COLLECTION ) ;
        $tickets->create() ;

        // t1 carries a step that matches; t2 carries steps but NONE that match — the
        // document the bare cardinality could not tell apart from t1; t3 carries an
        // empty list; t4 carries no resolution at all.
        $tickets->insert( [ '_key' => 't1' , 'resolution' => [ 'steps' => [ [ 'label' => 'review' , 'done' => true ] , [ 'label' => 'ship' , 'done' => false ] ] ] ] ) ;
        $tickets->insert( [ '_key' => 't2' , 'resolution' => [ 'steps' => [ [ 'label' => 'ship' , 'done' => false ] ] ] ] ) ;
        $tickets->insert( [ '_key' => 't3' , 'resolution' => [ 'steps' => [] ] ] ) ;
        $tickets->insert( [ '_key' => 't4' ] ) ;
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
                'resolution' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'steps' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'label' => FilterType::STRING , 'done' => FilterType::BOOL ] ,
                        ] ,
                    ] ,
                ] ,
            ]
        ]);
    }

    /**
     * "Tickets with a step labelled review" — only t1.
     *
     * The counterfactual is what the caller used to receive: the cardinality of the
     * list, which cannot tell t1 from t2 because both simply have steps.
     *
     * @throws ArangoException
     */
    public function testANestedMatchSelectsOnTheElementsNotOnTheirNumber() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'resolution.steps[*]' , 'match' => [ 'label' => 'review' ] ] ,
            $binds
        ) ;

        $this->assertSame( [ 't1' ] , $this->keys( $filter , $binds ) ) ;

        // What the bare cardinality answered instead — the question nobody asked.
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( 'LENGTH(doc.resolution.steps[*]) > 0' ) ) ;
    }

    /**
     * A `match` over several sub-fields at once — the whole point of `match`, and the
     * part a cardinality cannot express at all.
     *
     * @throws ArangoException
     */
    public function testANestedMatchCombinesItsSubFields() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'resolution.steps[*]' , 'match' => [ 'label' => 'ship' , 'done' => true ] ] ,
            $binds
        ) ;

        // t1 and t2 both hold a `ship` step, neither of them done.
        $this->assertSame( [] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * `match` under `quant: none` — "no step satisfies these conditions".
     *
     * ⚠ **`NONE` requires the list to exist**, and t4 is what says so: it carries no
     * `resolution` at all, and the question-mark operator over a `null` is false rather
     * than vacuously true. This is not something the batch changed — the root spelling
     * answers identically, measured below — but it is worth having written down next to
     * a case that could easily be read the other way.
     *
     * @throws ArangoException
     */
    public function testANestedMatchUnderNoneAnswersTheAbsence() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'resolution.steps[*]' , 'quant' => 'none' , 'match' => [ 'label' => 'review' ] ] ,
            $binds
        ) ;

        // t2 holds a step that does not match, t3 holds an empty list. t4 has no
        // `resolution`, so there is no list for "none of them" to range over.
        $this->assertSame( [ 't2' , 't3' ] , $this->keys( $filter , $binds ) ) ;

        // The cardinality it used to compile answered the emptiness of the list, which
        // is a different set entirely — it drops t2, which has steps, and picks up t4,
        // which has none to look at.
        $this->assertSame( [ 't3' , 't4' ] , $this->keys( 'LENGTH(doc.resolution.steps[*]) == 0' ) ) ;

        // And the root spelling agrees, which is what says the exclusion of t4 belongs
        // to the operator and not to the depth.
        $this->assertSame( [ 't2' , 't3' ] , $this->keys( 'doc.resolution.steps[? NONE FILTER CURRENT.label == "review"]' ) ) ;
    }
}
