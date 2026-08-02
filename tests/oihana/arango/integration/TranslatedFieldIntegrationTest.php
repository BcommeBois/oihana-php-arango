<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the translated projection (`Filter::TRANSLATE`).
 *
 * `TRANSLATE()` looks a language up **in a document**. Handed anything else it returns
 * `null` *and raises an AQL warning* — which the unit suite cannot see, since a warning
 * lives in the server's response and not in the emitted string. Worse, it is not cosmetic:
 * with the `failOnWarning` cursor option the whole query fails. Both halves are measured
 * here against a real server: the unguarded form is run explicitly so the failure is shown
 * rather than argued, and the guarded projection is run the same way to prove it survives —
 * while returning exactly the same values.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class TranslatedFieldIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_translated_field_it' ;

    private const string ARTICLES = 'articles' ;

    /**
     * The cursor options that turn any AQL warning into a query failure.
     */
    private const array FAIL_ON_WARNING = [ CursorField::OPTIONS => [ 'failOnWarning' => true ] ] ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::ARTICLES )->create() ;

        // a1 has the requested language, a2 an object without it, a3 no attribute at all,
        // and a4 a plain string — the shape a record left over from before i18n still has.
        $db->collection( self::ARTICLES )->insert( [ '_key' => 'a1' , 'title' => [ 'fr' => 'Bonjour' , 'en' => 'Hello' ] ] ) ;
        $db->collection( self::ARTICLES )->insert( [ '_key' => 'a2' , 'title' => [ 'en' => 'Hi' ] ] ) ;
        $db->collection( self::ARTICLES )->insert( [ '_key' => 'a3' ] ) ;
        $db->collection( self::ARTICLES )->insert( [ '_key' => 'a4' , 'title' => 'plain' ] ) ;
    }

    /**
     * The projection built by aqlFields() for a French reader.
     */
    private function projection() :string
    {
        return aqlFields
        (
            [ 'title' => [ Field::FILTER => Filter::TRANSLATE ] ] ,
            'doc' ,
            null ,
            [ Arango::LANG => 'fr' ]
        ) ;
    }

    /**
     * @return array<int,mixed> The projected title of every row, sorted by key.
     */
    private function titles( string $fields , array $options = [] ) :array
    {
        $titles = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . self::ARTICLES . ' SORT doc._key RETURN { ' . $fields . ' }' , [] , $options ) as $row )
        {
            $titles[] = json_decode( json_encode( $row ) , true )[ 'title' ] ;
        }
        return $titles ;
    }

    /**
     * The values, unchanged by the guard — that is the whole point of this lot.
     *
     * ⚠ Note the second row: a document that HAS the attribute but not the requested
     * language yields the empty string, not `null`. Two shapes of « no text », two values.
     * The guard does not touch that; it is a separate decision, deliberately not taken.
     *
     * @throws ArangoException
     */
    public function testTheProjectedValuesAreUnchanged() :void
    {
        $this->assertSame
        (
            [ 'Bonjour' , '' , null , null ] ,
            $this->titles( $this->projection() )
        ) ;
    }

    /**
     * The harm, measured rather than argued: without the guard, a single document whose
     * attribute is not a document raises a warning — and under failOnWarning the query dies,
     * taking the rows that were perfectly fine with it.
     *
     * @throws ArangoException
     */
    public function testTheUnguardedFormDiesUnderFailOnWarning() :void
    {
        $this->expectException( ArangoException::class ) ;
        $this->titles( 'title:TRANSLATE("fr",doc.title,"")' , self::FAIL_ON_WARNING ) ;
    }

    /**
     * The remedy on the same server, the same documents and the same option: the query runs
     * and returns the same four values.
     *
     * @throws ArangoException
     */
    public function testTheGuardedProjectionSurvivesFailOnWarning() :void
    {
        $this->assertSame
        (
            [ 'Bonjour' , '' , null , null ] ,
            $this->titles( $this->projection() , self::FAIL_ON_WARNING )
        ) ;
    }
}
