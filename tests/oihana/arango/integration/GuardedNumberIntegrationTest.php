<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the guarded numeric projection (`Field::NULLABLE` on a `Filter::NUMBER`).
 *
 * The claim being tested is about what **ArangoDB** does, not about the string the builder
 * emits: that `TO_NUMBER()` of a missing attribute is `0` — so « it is free » and « we have
 * no price » come back as one value — and that the guard suppresses that without touching
 * the documents that really carry the attribute. It also pins the reason the guard is a
 * presence test and not `IS_NUMBER()`: a price stored as the string `"42"` still counts as
 * `42`, and must keep counting.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class GuardedNumberIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_guarded_number_it' ;

    private const string OFFERS = 'offers' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::OFFERS )->create() ;

        // o1/o2 really carry the price — o2 being the free offer the defect makes
        // indistinguishable from o3, which says nothing at all. o4 stores an explicit null,
        // o5/o6 store what TO_NUMBER() accepts and a type test would silently drop.
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o1' , 'price' => 19.9 ] ) ;
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o2' , 'price' => 0    ] ) ;
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o3' ] ) ;
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o4' , 'price' => null ] ) ;
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o5' , 'price' => '42' ] ) ;
        $db->collection( self::OFFERS )->insert( [ '_key' => 'o6' , 'price' => '7.5' ] ) ;
    }

    /**
     * Run the built projection and return the decoded rows.
     */
    private function project( array $extra = [] ) :array
    {
        $fields = aqlFields
        ([
            '_key'  => [] ,
            'price' => $extra + [ Field::FILTER => Filter::NUMBER ] ,
        ] , 'doc' ) ;

        $rows = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . self::OFFERS . ' SORT doc._key RETURN { ' . $fields . ' }' ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }
        return $rows ;
    }

    /**
     * The defect, measured: the free offer and the one with no price at all come back with
     * the very same `0`.
     *
     * @throws ArangoException
     */
    public function testUnguardedCastMakesTheFreeOfferLookLikeTheUnpricedOne() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 'o1' , 'price' => 19.9 ] ,
                [ '_key' => 'o2' , 'price' => 0    ] , // really free
                [ '_key' => 'o3' , 'price' => 0    ] , // no attribute at all
                [ '_key' => 'o4' , 'price' => 0    ] , // an explicit null
                [ '_key' => 'o5' , 'price' => 42   ] ,
                [ '_key' => 'o6' , 'price' => 7.5  ] ,
            ] ,
            $this->project()
        ) ;
    }

    /**
     * The remedy: the two silent documents abstain, the free offer keeps its `0`, and — the
     * point of the presence test — the prices stored as strings keep converting. An
     * `IS_NUMBER()` guard would have dropped those two as well.
     *
     * @throws ArangoException
     */
    public function testNullableAbstainsWithoutDroppingTheAcceptedValues() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 'o1' , 'price' => 19.9 ] ,
                [ '_key' => 'o2' , 'price' => 0    ] , // ⭐ a stored 0 is still a 0
                [ '_key' => 'o3' , 'price' => null ] ,
                [ '_key' => 'o4' , 'price' => null ] ,
                [ '_key' => 'o5' , 'price' => 42   ] , // ⭐ kept : IS_NUMBER() would have dropped it
                [ '_key' => 'o6' , 'price' => 7.5  ] , // ⭐ kept
            ] ,
            $this->project( [ Field::NULLABLE => true ] )
        ) ;
    }

    /**
     * `Field::ELSE` says what to answer instead of `null` — here another attribute of the
     * same document, the shape a fallback price takes in practice.
     *
     * @throws ArangoException
     */
    public function testElseChoosesWhatIsSaidInstead() :void
    {
        $rows = $this->project( [ Field::NULLABLE => true , Field::ELSE => -1 ] ) ;

        $this->assertSame( [ 19.9 , 0 , -1 , -1 , 42 , 7.5 ] , array_column( $rows , 'price' ) ) ;
    }
}
