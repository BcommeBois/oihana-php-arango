<?php

namespace tests\oihana\arango\integration;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\Attributes\Group;

use function oihana\arango\db\helpers\aqlFields;

/**
 * Live validation of the guarded sub-document projection (`Field::NULLABLE` /
 * `Field::WHEN` on a `Filter::DOCUMENT`).
 *
 * The whole point of the guard is a claim about what **ArangoDB** does, not about the
 * string the builder emits: that reading an attribute of a missing object yields `null`
 * without raising, so an unguarded rebuild returns an object of nulls — and that the
 * ternary suppresses it. The unit suite freezes the AQL text and cannot prove either
 * half. So the real {@see aqlFields()} output is run against a seeded, disposable
 * database and the returned rows are asserted, unguarded and guarded side by side.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class GuardedDocumentIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_guarded_document_it' ;

    private const string USERS = 'users' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::USERS )->create() ;

        // u1 carries a real sub-document, u2 none at all, u3 a non-object under the
        // same attribute (the case a `!= null` guard would let through), and u4 an
        // explicit null.
        $db->collection( self::USERS )->insert( [ '_key' => 'u1' , 'visibility' => 'public'  , 'thing' => [ '_key' => 't9' , 'name' => 'Widget' ] ] ) ;
        $db->collection( self::USERS )->insert( [ '_key' => 'u2' , 'visibility' => 'public'  ] ) ;
        $db->collection( self::USERS )->insert( [ '_key' => 'u3' , 'visibility' => 'private' , 'thing' => 'not-an-object' ] ) ;
        $db->collection( self::USERS )->insert( [ '_key' => 'u4' , 'visibility' => 'public'  , 'thing' => null ] ) ;
    }

    /**
     * Run the built projection and return the decoded rows.
     */
    private function project( string $fields ) :array
    {
        $rows = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . self::USERS . ' SORT doc._key RETURN { ' . $fields . ' }' ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }
        return $rows ;
    }

    /**
     * @return array<string,array> The sub-document projection, guarded or not.
     */
    private function definition( array $extra = [] ) :array
    {
        return
        [
            '_key'  => [] ,
            'thing' => $extra +
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS =>
                [
                    '_key' => [] ,
                    'name' => [] ,
                    'url'  => [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
                ] ,
            ] ,
        ] ;
    }

    /**
     * The defect itself, measured rather than argued: an absent source comes back as an
     * object of nulls, and the recomputed url as an address leading nowhere. This is the
     * historical behaviour every existing projection has — it is pinned here so the
     * remedy below is provably a change of outcome and not of wording.
     *
     * @throws ArangoException
     */
    public function testUnguardedProjectionDressesAnEmptySlot() :void
    {
        $rows = $this->project( aqlFields( $this->definition() , 'doc' ) ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'u1' , 'thing' => [ '_key' => 't9' , 'name' => 'Widget' , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'u2' , 'thing' => [ '_key' => null , 'name' => null     , 'url' => '/things/'   ] ] ,
                [ '_key' => 'u3' , 'thing' => [ '_key' => null , 'name' => null     , 'url' => '/things/'   ] ] ,
                [ '_key' => 'u4' , 'thing' => [ '_key' => null , 'name' => null     , 'url' => '/things/'   ] ] ,
            ] ,
            $rows
        ) ;
    }

    /**
     * The remedy: the object present is untouched, the three empty slots become `null`.
     * u3 is the reason the guard is a type test — the attribute *exists* there, so a
     * `!= null` comparison would have let it through and rebuilt the same object of nulls.
     *
     * @throws ArangoException
     */
    public function testNullableYieldsNullOnAMissingOrNonObjectSource() :void
    {
        $rows = $this->project( aqlFields( $this->definition( [ Field::NULLABLE => true ] ) , 'doc' ) ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'u1' , 'thing' => [ '_key' => 't9' , 'name' => 'Widget' , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'u2' , 'thing' => null ] ,
                [ '_key' => 'u3' , 'thing' => null ] ,
                [ '_key' => 'u4' , 'thing' => null ] ,
            ] ,
            $rows
        ) ;
    }

    /**
     * The general mechanism on a real server: a free condition read on the PARENT
     * document guards the whole rebuilt object. u3 is private, so it is hidden even
     * though it carries the attribute; u2 and u4 pass the condition but have no object
     * to show — proving the condition alone does not cover the existence case.
     *
     * @throws ArangoException
     */
    public function testWhenGuardsTheWholeObjectFromTheParentDocument() :void
    {
        $rows = $this->project( aqlFields( $this->definition( [ Field::WHEN => [ 'visibility' , 'public' ] ] ) , 'doc' ) ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'u1' , 'thing' => [ '_key' => 't9' , 'name' => 'Widget' , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'u2' , 'thing' => [ '_key' => null , 'name' => null , 'url' => '/things/' ] ] ,
                [ '_key' => 'u3' , 'thing' => null ] ,
                [ '_key' => 'u4' , 'thing' => [ '_key' => null , 'name' => null , 'url' => '/things/' ] ] ,
            ] ,
            $rows
        ) ;
    }

    /**
     * Both guards composed with `&&`, and the `Field::ELSE` branch reading another
     * attribute of the parent — the shape the ternary is built for.
     *
     * @throws ArangoException
     */
    public function testBothGuardsComposeAndElseIsHonoured() :void
    {
        $fields = aqlFields
        (
            $this->definition
            ([
                Field::NULLABLE => true ,
                Field::WHEN     => [ 'visibility' , 'public' ] ,
                Field::ELSE     => [ Field::PROPERTY => 'visibility' ] ,
            ]) ,
            'doc'
        ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'u1' , 'thing' => [ '_key' => 't9' , 'name' => 'Widget' , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'u2' , 'thing' => 'public'  ] ,
                [ '_key' => 'u3' , 'thing' => 'private' ] ,
                [ '_key' => 'u4' , 'thing' => 'public'  ] ,
            ] ,
            $this->project( $fields )
        ) ;
    }

    /**
     * Nesting on a real server: the outer guard being false, the inner ternary is never
     * reached — and where the outer holds, the inner decides on its own.
     *
     * @throws ArangoException
     */
    public function testNestedGuardsComposeOnARealServer() :void
    {
        $fields = aqlFields
        ([
            '_key'  => [] ,
            'thing' =>
            [
                Field::FILTER   => Filter::DOCUMENT ,
                Field::NULLABLE => true ,
                Field::FIELDS   =>
                [
                    'name'  => [] ,
                    'owner' =>
                    [
                        Field::FILTER   => Filter::DOCUMENT ,
                        Field::NULLABLE => true ,
                        Field::FIELDS   => [ 'name' => [] ] ,
                    ] ,
                ] ,
            ] ,
        ] , 'doc' ) ;

        $this->assertSame
        (
            [
                // u1 has a thing, but that thing has no owner : outer object, inner null.
                [ '_key' => 'u1' , 'thing' => [ 'name' => 'Widget' , 'owner' => null ] ] ,
                [ '_key' => 'u2' , 'thing' => null ] ,
                [ '_key' => 'u3' , 'thing' => null ] ,
                [ '_key' => 'u4' , 'thing' => null ] ,
            ] ,
            $this->project( $fields )
        ) ;
    }
}
