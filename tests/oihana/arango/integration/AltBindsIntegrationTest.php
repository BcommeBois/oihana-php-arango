<?php

namespace tests\oihana\arango\integration;

use DI\Container;
use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\enums\AQL;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Live validation that a parameterised `alt` chain reaches the server complete.
 *
 * This defect could not be seen from the emitted AQL: the text was always right.
 * What was missing travelled beside it — the bind filling the parameter the text
 * declares. A server is the only place the two are put back together, and the only
 * place that says so:
 *
 * ```
 * no value specified for declared bind parameter 'query_…'
 * ```
 *
 * Each case therefore asserts twice: that the query the library builds **runs** and
 * selects the right documents, and — the counterfactual, next to it — that the same
 * query with that one bind withheld is **refused**. The second assertion is what
 * says the first is proving something: if the parameter were ever dropped again,
 * the pair would disagree.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class AltBindsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_alt_binds_it' ;

    private const string COLLECTION = 'tickets' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tickets = $db->collection( self::COLLECTION ) ;
        $tickets->create() ;

        $tickets->insert( [ '_key' => 't1' , 'tags' => [ 'internal' ]            , 'created' => '2026-08-01' ] ) ;
        $tickets->insert( [ '_key' => 't2' , 'tags' => [ 'internal' , 'urgent' ] , 'created' => '2026-08-01' ] ) ;
        $tickets->insert( [ '_key' => 't3' , 'tags' => []                        , 'created' => '2026-06-01' ] ) ;
        $tickets->insert( [ '_key' => 't4' , 'tags' => [ 'urgent' ]              , 'created' => '2026-06-01' ] ) ;
    }

    /**
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

    /**
     * Runs the same query with one bind withheld and returns the server's refusal.
     *
     * The withheld one is the parameter the `alt` chain contributed — exactly what
     * used to go missing. Returns the message, or null when the server accepted it,
     * which would mean the case proves nothing.
     *
     * @throws ArangoException
     */
    private function refusalWithout( string $filter , array $binds , mixed $altValue ) :?string
    {
        $crippled = array_filter( $binds , fn( $v ) => $v !== $altValue ) ;

        $this->assertCount( count( $binds ) - 1 , $crippled , 'the alt parameter must be one of the binds, or nothing is being withheld' ) ;

        try
        {
            $this->keys( $filter , $crippled ) ;
            return null ;
        }
        catch ( Throwable $exception )
        {
            return $exception->getMessage() ;
        }
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
                'tags'    => FilterType::ARRAY ,
                'created' => FilterType::DATE  ,
            ]
        ]);
    }

    /**
     * Seat one — a list. "Tickets carrying nothing but internal tags": the stored
     * list minus `internal` must come out empty.
     *
     * `internal` is the `alt` parameter. It is the one that used to be declared in
     * the query and left unbound.
     *
     * @throws ArangoException
     */
    public function testAParameterisedAltOnAListReachesTheServer() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'tags' , 'val' => [] , 'alt' => [ [ 'remove' , 'internal' ] ] ] ,
            $binds
        ) ;

        // t1 holds only `internal`, t3 holds nothing at all — both come out empty.
        $this->assertSame( [ 't1' , 't3' ] , $this->keys( $filter , $binds ) ) ;

        // The counterfactual: withhold that one bind and the server refuses it whole.
        $this->assertStringContainsString
        (
            'no value specified for declared bind parameter' ,
            (string) $this->refusalWithout( $filter , $binds , 'internal' )
        ) ;
    }

    /**
     * Seat two — a date. The key side is shifted back 30 days before comparing, and
     * the unit (`day`) is the bound parameter.
     *
     * @throws ArangoException
     */
    public function testAParameterisedAltOnADateReachesTheServer() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'created' , 'op' => 'ge' , 'val' => '2026-07-01' , 'alt' => [ [ 'dateSubtract' , 30 , 'day' ] ] ] ,
            $binds
        ) ;

        // 2026-08-01 minus 30 days is 2026-07-02, still >= 2026-07-01 — t1 and t2.
        // 2026-06-01 minus 30 days is not.
        $this->assertSame( [ 't1' , 't2' ] , $this->keys( $filter , $binds ) ) ;

        $this->assertStringContainsString
        (
            'no value specified for declared bind parameter' ,
            (string) $this->refusalWithout( $filter , $binds , 'day' )
        ) ;
    }
}
