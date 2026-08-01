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

    private const string RECORDS = 'records' ;

    private const string USERS = 'users' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::USERS )->create() ;
        $db->collection( self::RECORDS )->create() ;

        // The url guard is measured on its own collection: frozen copies of the same
        // shape, some coming from a record and carrying a key, others hand-typed with
        // legitimately none — plus the empty-key case, which produces the very same
        // truncated link as a missing one.
        $db->collection( self::RECORDS )->insert( [ '_key' => 'r1' , 'thing' => [ '_key' => 't9' , 'additionalType' => 'Place' , 'name' => 'Widget'     ] ] ) ;
        $db->collection( self::RECORDS )->insert( [ '_key' => 'r2' , 'thing' => [                   'additionalType' => 'Text'  , 'name' => 'Hand-typed' ] ] ) ;
        $db->collection( self::RECORDS )->insert( [ '_key' => 'r3' , 'thing' => [ '_key' => ''   , 'additionalType' => 'Place' , 'name' => 'Empty key'  ] ] ) ;

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
    private function project( string $fields , string $collection = self::USERS ) :array
    {
        $rows = [] ;
        foreach ( self::$db->query( 'FOR doc IN ' . $collection . ' SORT doc._key RETURN { ' . $fields . ' }' ) as $row )
        {
            $rows[] = json_decode( json_encode( $row ) , true ) ;
        }
        return $rows ;
    }

    /**
     * @param array $urlExtra Markers added to the url projection.
     * @return array<string,array> A sub-document rebuilding a url from the copy's own key.
     */
    private function recordDefinition( array $urlExtra = [] ) :array
    {
        return
        [
            '_key'  => [] ,
            'thing' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS =>
                [
                    'name' => [] ,
                    'url'  => $urlExtra + [ Field::FILTER => Filter::URL , Field::PATH => '/things' ] ,
                ] ,
            ] ,
        ] ;
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

    // ---------------------------------------------------------------- the url guard

    /**
     * The defect, measured: `CONCAT()` drops its null arguments, so a copy with no key
     * does not come back without a url — it comes back with a link that leads nowhere,
     * indistinguishable at a glance from a real one. An empty key does exactly the same.
     *
     * @throws ArangoException
     */
    public function testUnguardedUrlDressesAKeylessCopy() :void
    {
        $rows = $this->project( aqlFields( $this->recordDefinition() , 'doc' ) , self::RECORDS ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'r1' , 'thing' => [ 'name' => 'Widget'     , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'r2' , 'thing' => [ 'name' => 'Hand-typed' , 'url' => '/things/'   ] ] ,
                [ '_key' => 'r3' , 'thing' => [ 'name' => 'Empty key'  , 'url' => '/things/'   ] ] ,
            ] ,
            $rows
        ) ;
    }

    /**
     * The remedy on a real server: a one-element leaf is a truthiness test, so the copy
     * with no key AND the copy with an empty one both abstain, while the real link is
     * untouched. The object itself keeps its other attributes — only the url gives up.
     *
     * @throws ArangoException
     */
    public function testWhenLetsTheUrlAbstainWithoutHidingTheObject() :void
    {
        $rows = $this->project( aqlFields( $this->recordDefinition( [ Field::WHEN => [ '_key' ] ] ) , 'doc' ) , self::RECORDS ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'r1' , 'thing' => [ 'name' => 'Widget'     , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'r2' , 'thing' => [ 'name' => 'Hand-typed' , 'url' => null ] ] ,
                [ '_key' => 'r3' , 'thing' => [ 'name' => 'Empty key'  , 'url' => null ] ] ,
            ] ,
            $rows
        ) ;
    }

    /**
     * The condition reads the reference the projection itself reads from — the COPY, not
     * the parent record — which is what lets a discriminant carried by the copy decide.
     *
     * ⚠ And it measures a real limit rather than assuming one away: a discriminant says
     * what the copy *is*, not whether it can be addressed. r3 declares itself a `Place`
     * and still has no usable key, so it comes back with the truncated link. Guarding on
     * the key is what suppresses that; guarding on the type is a different question.
     *
     * @throws ArangoException
     */
    public function testWhenOnTheDiscriminantIsReadFromTheCopy() :void
    {
        $rows = $this->project
        (
            aqlFields( $this->recordDefinition( [ Field::WHEN => [ 'additionalType' , 'Place' ] ] ) , 'doc' ) ,
            self::RECORDS
        ) ;

        $this->assertSame
        (
            [
                [ '_key' => 'r1' , 'thing' => [ 'name' => 'Widget'     , 'url' => '/things/t9' ] ] ,
                [ '_key' => 'r2' , 'thing' => [ 'name' => 'Hand-typed' , 'url' => null ] ] ,
                [ '_key' => 'r3' , 'thing' => [ 'name' => 'Empty key'  , 'url' => '/things/' ] ] ,
            ] ,
            $rows
        ) ;
    }
}
