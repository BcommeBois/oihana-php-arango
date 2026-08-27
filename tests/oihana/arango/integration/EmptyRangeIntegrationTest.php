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
 * Live validation that an unfilled range expresses no constraint.
 *
 * Two claims need a server, and neither can be seen in the emitted string:
 *
 * - the old empty condition reached ArangoDB as `FILTER  RETURN` and was **refused**
 *   outright — a `500` for a caller who merely left a form field blank ;
 * - `{"min":null,"max":null}` compiled a comparison **against null**, which the
 *   server answers with the records holding **no value at all**. That is the sharper
 *   half: a plausible page, in `200`, answering a question nobody asked.
 *
 * The seed therefore carries products with a price, one storing `null`, and one with
 * no price attribute — the documents that tell "no constraint" apart from "no value".
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class EmptyRangeIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_empty_range_it' ;

    private const string COLLECTION = 'products' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $products = $db->collection( self::COLLECTION ) ;
        $products->create() ;

        $products->insert( [ '_key' => 'p1' , 'price' => 10   ] ) ;
        $products->insert( [ '_key' => 'p2' , 'price' => 50   ] ) ;
        $products->insert( [ '_key' => 'p3' , 'price' => null ] ) ; // stored, but empty
        $products->insert( [ '_key' => 'p4' ] ) ;                    // no price attribute
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
            AQL::FILTERS    => [ 'price' => FilterType::NUMBER ] ,
        ]);
    }

    /**
     * An unfilled range no longer builds a condition at all, so there is nothing to
     * send — and the two counterfactuals show what the server did with what used to be
     * built.
     *
     * @throws ArangoException
     */
    public function testAnUnfilledRangeBuildsNothingAndTheOldShapesFail() :void
    {
        $binds = [] ;

        $this->assertNull( $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' ] , $binds ) ) ;

        // What used to be built when the keys were omitted: an empty condition, which
        // the server refuses whole.
        $refusal = null ;

        try
        {
            $this->keys( '' ) ;
        }
        catch ( Throwable $exception )
        {
            $refusal = $exception->getMessage() ;
        }

        $this->assertStringContainsString( 'syntax error' , (string) $refusal ) ;
    }

    /**
     * 🚨 And what used to be built when the keys were sent as null: a comparison
     * against null, which selects the records holding **no price**.
     *
     * The caller asked for "prices between nothing and nothing" and was answered with
     * the products that have no price — in `200`, with nothing to signal it.
     *
     * @throws ArangoException
     */
    public function testNullBoundsUsedToSelectTheRecordsWithNoValue() :void
    {
        $binds = [] ;

        $this->assertNull
        (
            $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => null , 'max' => null ] , $binds )
        ) ;

        // The counterfactual, run against the server: the shape that is no longer built.
        $this->assertSame( [ 'p3' , 'p4' ] , $this->keys( '(doc.price >= null && doc.price <= null)' ) ) ;
    }

    /**
     * A range that IS filled in still constrains, at both ends and at one.
     *
     * @throws ArangoException
     */
    public function testAFilledRangeStillConstrains() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => 20 , 'max' => 80 ] , $binds ) ;

        $this->assertSame( [ 'p2' ] , $this->keys( (string) $filter , $binds ) ) ;

        // min only, max explicitly null — one real bound, and the null is not one.
        $binds  = [] ;
        $filter = $this->model()->prepareFilter( [ 'key' => 'price' , 'op' => 'between' , 'min' => 20 , 'max' => null ] , $binds ) ;

        $this->assertSame( [ 'p2' ] , $this->keys( (string) $filter , $binds ) ) ;
    }
}
