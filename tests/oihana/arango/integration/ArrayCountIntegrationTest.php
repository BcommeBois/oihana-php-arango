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

/**
 * Live validation that counting a list-declared key counts its elements.
 *
 * `COUNT()` and `LENGTH()` are defined on strings as well as arrays, and that is a
 * claim about **ArangoDB**, not about the string this library emits: a document
 * storing `"backend"` under a list-declared key answers `7` to `COUNT(doc.tags)`.
 * Only a server can say so, and only a server can confirm that the expansion form
 * answers `0` instead.
 *
 * The seed carries one such malformed document on purpose. It is what makes the
 * green mean something: a test seeded only with well-formed lists would pass just
 * as well against the old shape, since `["x","y"][*]` *is* `["x","y"]`.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ArrayCountIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_array_count_it' ;

    private const string COLLECTION = 'tickets' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $tickets = $db->collection( self::COLLECTION ) ;
        $tickets->create() ;

        $tickets->insert( [ '_key' => 't1' , 'tags' => [ 'a' , 'b' , 'c' ] ] ) ;
        $tickets->insert( [ '_key' => 't2' , 'tags' => [ 'a' ] ] ) ;
        $tickets->insert( [ '_key' => 't3' , 'tags' => [] ] ) ;
        $tickets->insert( [ '_key' => 't4' ] ) ;

        // 🚨 The document the whole batch is about: a string where a list was declared,
        // seven characters long, which the bare COUNT() reports as seven tags.
        $tickets->insert( [ '_key' => 't5' , 'tags' => 'backend' ] ) ;
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
            AQL::FILTERS    => [ 'tags' => FilterType::ARRAY ] ,
        ]);
    }

    /**
     * "Records with no tags at all" — the comparison that matters.
     *
     * The malformed record has no tags to show, and it belongs in this answer. It used
     * to be **left out**: `COUNT("backend")` is 7, not 0, so the one record most in need
     * of attention was the one the question could not reach.
     *
     * @throws ArangoException
     */
    public function testNoTagsAtAllIncludesTheMalformedRecord() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'tags' , 'op' => 'eq' , 'val' => 0 , 'alt' => 'count' ] ,
            $binds
        ) ;

        $this->assertSame( [ 't3' , 't4' , 't5' ] , $this->keys( $filter , $binds ) ) ;

        // The counterfactual: on the bare attribute, t5 answers 7 and drops out.
        $this->assertSame( [ 't3' , 't4' ] , $this->keys( 'COUNT(doc.tags) == 0' ) ) ;
    }

    /**
     * "At least three tags" — the case the documentation shows, and the noisy half of
     * the defect: the malformed record used to be counted in.
     *
     * @throws ArangoException
     */
    public function testAtLeastThreeTagsExcludesTheMalformedRecord() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'tags' , 'op' => 'ge' , 'val' => 3 , 'alt' => 'count' ] ,
            $binds
        ) ;

        $this->assertSame( [ 't1' ] , $this->keys( $filter , $binds ) ) ;

        // The counterfactual: seven characters read as seven tags.
        $this->assertSame( [ 't1' , 't5' ] , $this->keys( 'COUNT(doc.tags) >= 3' ) ) ;
    }

    /**
     * ⚠ And the well-formed records answer exactly what they answered before, which is
     * the property the whole approach rests on: `["a","b","c"][*]` **is**
     * `["a","b","c"]`, so no existing query moves.
     *
     * @throws ArangoException
     */
    public function testWellFormedRecordsAnswerExactlyAsBefore() :void
    {
        $binds  = [] ;
        $filter = $this->model()->prepareFilter
        (
            [ 'key' => 'tags' , 'op' => 'ge' , 'val' => 1 , 'alt' => 'count' ] ,
            $binds
        ) ;

        $expanded = $this->keys( $filter , $binds ) ;

        $this->assertSame( [ 't1' , 't2' ] , $expanded ) ;

        // Same answer on the bare attribute, minus the malformed record it invents.
        $this->assertSame( [ 't1' , 't2' , 't5' ] , $this->keys( 'COUNT(doc.tags) >= 1' ) ) ;
    }
}
