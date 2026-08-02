<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the projected identifier (`Filter::ID`).
 *
 * The claim is about what **ArangoDB** does with a key, not about the emitted string: a
 * `_key` is always a *string*, and the `TO_NUMBER()` this filter used to apply turned every
 * non-numeric one into `0` — so all those documents shared one identifier — while dropping
 * leading zeros and losing precision on long numeric keys. Both halves are measured here:
 * the old conversion is run explicitly so the harm is shown rather than argued, and the
 * projection built by {@see aqlFields()} is asserted to return the keys untouched.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class ProjectedIdIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_projected_id_it' ;

    private const string THINGS = 'things' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        // A plain numeric key, a zero-padded one, and two the day an identifier carries
        // letters or a dash — the shapes an ArangoDB key is allowed to take.
        $db->collection( self::THINGS )->create() ;
        $db->collection( self::THINGS )->insert( [ '_key' => '007'    ] ) ;
        $db->collection( self::THINGS )->insert( [ '_key' => '1234'   ] ) ;
        $db->collection( self::THINGS )->insert( [ '_key' => 'abc-42' ] ) ;
        $db->collection( self::THINGS )->insert( [ '_key' => 't9'     ] ) ;
    }

    /**
     * @param string $fields The projection to run.
     * @return array<int,mixed> The projected `id` of every row, sorted by key.
     */
    private function ids( string $fields ) :array
    {
        $ids = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . self::THINGS . ' SORT doc._key RETURN { ' . $fields . ' }' ) as $row )
        {
            $ids[] = json_decode( json_encode( $row ) , true )[ 'id' ] ;
        }
        return $ids ;
    }

    /**
     * The harm, measured rather than argued: the two alphanumeric keys both collapse to `0`
     * — one identifier for two distinct documents — and the padded key comes back as a
     * number that no longer addresses anything.
     *
     * @throws ArangoException
     */
    public function testTheFormerConversionCollapsedDistinctKeys() :void
    {
        $ids = $this->ids( 'id:TO_NUMBER(doc._key)' ) ;

        $this->assertSame( [ 7 , 1234 , 0 , 0 ] , $ids ) ;
        $this->assertCount( 3 , array_unique( $ids ) ) ; // 4 documents, 3 identifiers
    }

    /**
     * What the filter returns now: every key as it is stored, addressable as-is.
     *
     * @throws ArangoException
     */
    public function testTheFilterReturnsEveryKeyUntouched() :void
    {
        $ids = $this->ids( aqlFields( [ 'id' => [ Field::FILTER => Filter::ID ] ] , 'doc' ) ) ;

        $this->assertSame( [ '007' , '1234' , 'abc-42' , 't9' ] , $ids ) ;
        $this->assertCount( 4 , array_unique( $ids ) ) ; // one identifier per document
    }
}
