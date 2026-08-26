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
 * Live validation of the presence test on an object named last (`Filter::DOCUMENT`).
 *
 * The unit cases pin the AQL string; they cannot say what **ArangoDB** answers to it.
 * Two claims need a server:
 *
 * - that `doc.<key> == null` selects both the document whose attribute is **absent**
 *   and the one storing an explicit `null` — AQL reads a missing attribute as `null`,
 *   which is the whole reason the plain comparison is the right shape here ;
 * - that an **empty object** counts as *present*. `{}` is the case that separates "the
 *   location is there" from "there is something useful in it", and it is the one a
 *   green test could quietly miss.
 *
 * The seed is built so that **no assertion below can answer the whole collection**: the
 * five documents split 2 / 3, so a filter silently dropped — the defect being fixed —
 * would return five and fail, rather than passing while proving nothing.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ObjectPresenceIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_object_presence_it' ;

    private const string COLLECTION = 'tickets' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tickets = $db->collection( self::COLLECTION ) ;
        $tickets->create() ;

        // t1 carries a real resolution, t2 says nothing at all, t3 stores an explicit
        // null, t4 stores an EMPTY object — present, though it holds nothing — and t5
        // carries a resolution with a nested `audit` object, for the deeper seat.
        $tickets->insert( [ '_key' => 't1' , 'resolution' => [ 'closedAt' => '2026-01-05' , 'by' => 'ada'  ] ] ) ;
        $tickets->insert( [ '_key' => 't2' ] ) ;
        $tickets->insert( [ '_key' => 't3' , 'resolution' => null ] ) ;
        $tickets->insert( [ '_key' => 't4' , 'resolution' => [] ] ) ;
        $tickets->insert( [ '_key' => 't5' , 'resolution' => [ 'closedAt' => '2026-02-11' , 'audit' => [ 'by' => 'grace' ] ] ] ) ;
    }

    /**
     * Run the built condition against the server and return the matching keys.
     *
     * @throws ArangoException
     */
    private function keys( string $filter , array $binds ) :array
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
                        'closedAt' => FilterType::STRING ,
                        'audit'    =>
                        [
                            AQL::TYPE    => Filter::DOCUMENT ,
                            AQL::FILTERS => [ 'by' => FilterType::STRING ] ,
                        ] ,
                    ] ,
                ] ,
            ]
        ]);
    }

    /**
     * "Which tickets have no resolution yet?" — the question that had no writable form.
     *
     * Both shapes of absence answer it: the attribute that is not there (t2) and the one
     * storing `null` (t3). Two of five, so a dropped filter cannot pass this.
     *
     * @throws ArangoException
     */
    public function testAnObjectComparedToNullSelectsAbsentAndNull() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution' , 'val' => null ] , $binds ) ;

        $this->assertSame( [ 't2' , 't3' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * The mirror question — and the case that makes the green mean something.
     *
     * t4 stores `{}`: an object holding nothing at all. It is **present**, so it belongs
     * here. A test seeded only with filled objects would pass just as well against an
     * implementation testing the contents rather than the location.
     *
     * @throws ArangoException
     */
    public function testAnEmptyObjectCountsAsPresent() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution' , 'val' => null , 'op' => 'ne' ] , $binds ) ;

        $this->assertSame( [ 't1' , 't4' , 't5' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * The deeper seat, reached by the other road: a dotted key goes through the
     * hierarchical walk, where the object is the last segment.
     *
     * It also pins something the string cannot say: for t2 and t3, `doc.resolution` is
     * `null`, so `doc.resolution.audit` asks for a sub-attribute of a non-object. AQL
     * answers `null` **without raising a warning**, so the rows are simply selected —
     * no guard is needed here, and the query survives `failOnWarning`.
     *
     * @throws ArangoException
     */
    public function testANestedObjectComparedToNullReachesTheServer() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution.audit' , 'val' => null ] , $binds ) ;

        $this->assertSame( [ 't1' , 't2' , 't3' , 't4' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * And its mirror: only the ticket that really carries the nested object.
     *
     * @throws ArangoException
     */
    public function testANestedObjectIsPresentOnlyWhereItIsStored() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution.audit' , 'val' => null , 'op' => 'ne' ] , $binds ) ;

        $this->assertSame( [ 't5' ] , $this->keys( $filter , $binds ) ) ;
    }

    /**
     * Filtering *inside* the object is unchanged — the fix touches only the terminal
     * case, and this is the usage that had to keep working for the two to coexist.
     *
     * @throws ArangoException
     */
    public function testFilteringInsideTheObjectStillWorks() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'resolution.audit.by' , 'val' => 'grace' ] , $binds ) ;

        $this->assertSame( [ 't5' ] , $this->keys( $filter , $binds ) ) ;
    }
}
