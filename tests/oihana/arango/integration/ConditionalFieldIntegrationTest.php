<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Field;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the `Field::WHEN` conditional projection: the value of a
 * scalar field is guarded by a condition compiled inline (no bind variables) into
 * an AQL ternary `<condition> ? <then> : <else>`.
 *
 * The real {@see aqlFields()} output is wrapped in a `FOR doc IN products RETURN { … }`
 * query and run against a seeded, disposable database. A correct result proves the
 * generated `TRANSLATE`-free ternary — including AND groups and an attribute-valued
 * `Field::ELSE` — actually parses AND runs on a real server, which the unit suite
 * (frozen AQL string only) cannot prove.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ConditionalFieldIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_conditional_field_it' ;

    private const string PRODUCTS = 'products' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::PRODUCTS )->create() ;

        $db->collection( self::PRODUCTS )->insert( [ '_key' => 'p1' , 'visibility' => 'public'  , 'price' => 100 , 'basePrice' => 10 , 'stock' => 5 , 'enabled' => true  , 'tag' => 'hot'  ] ) ;
        $db->collection( self::PRODUCTS )->insert( [ '_key' => 'p2' , 'visibility' => 'private' , 'price' => 200 , 'basePrice' => 20 , 'stock' => 3 , 'enabled' => false , 'tag' => 'cold' ] ) ;
        $db->collection( self::PRODUCTS )->insert( [ '_key' => 'p3' , 'visibility' => 'public'  , 'price' => 300 , 'basePrice' => 30 , 'stock' => 0 , 'enabled' => true  , 'tag' => 'warm' ] ) ;
    }

    public function testConditionalFieldsRunOnARealServer() :void
    {
        $fields = aqlFields
        ([
            '_key'  => [] ,

            // single equality condition + attribute-valued else fallback
            'price' =>
            [
                Field::WHEN => [ 'visibility' , 'public' ] ,
                Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
            ],

            // AND group (public AND in stock), else defaults to null
            'promo' =>
            [
                Field::NAME => 'tag' ,
                Field::WHEN => [ [ 'visibility' , 'public' ] , [ 'stock' , 'gt' , 0 ] ] ,
            ],

            // truthiness shorthand on a boolean attribute
            'badge' =>
            [
                Field::NAME => 'tag' ,
                Field::WHEN => 'enabled' ,
            ],
        ] , 'doc' ) ;

        $query = 'FOR doc IN ' . self::PRODUCTS . ' SORT doc._key RETURN { ' . $fields . ' }' ;

        $rows = [] ;
        foreach ( self::$db->query( $query ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }

        $this->assertSame
        (
            [
                // p1 public, stock 5, enabled       → price=100, promo=hot,  badge=hot
                [ '_key' => 'p1' , 'price' => 100 , 'promo' => 'hot'  , 'badge' => 'hot'  ] ,
                // p2 private (→ basePrice), stock>0 but private, disabled
                [ '_key' => 'p2' , 'price' => 20  , 'promo' => null   , 'badge' => null   ] ,
                // p3 public but out of stock, enabled
                [ '_key' => 'p3' , 'price' => 300 , 'promo' => null   , 'badge' => 'warm' ] ,
            ] ,
            $rows
        ) ;
    }
}
