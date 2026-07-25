<?php

namespace tests\oihana\arango\models\helpers\facets;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;

use PHPUnit\Framework\TestCase;

use ReflectionException;
use function oihana\arango\models\helpers\facets\resolveFacetJoin;

/**
 * Coverage for {@see resolveFacetJoin()} — the shared anchor of the three
 * key-join facets (`JOIN`, `JOIN_AGGREGATE`, `JOIN_COMPLEX`): the joined
 * document reference, the `FOR … IN <collection>` clause and the join
 * predicate.
 *
 * The behaviour these assertions pin is the one the three builders relied on
 * before the extraction, so the generated AQL is expected byte-for-byte.
 *
 * @package tests\oihana\arango\models\helpers\facets
 * @author  Marc Alcaraz
 */
final class ResolveFacetJoinTest extends TestCase
{
    /**
     * @return void
     * @throws ReflectionException
     */
    public function testDefaultsJoinTheFacetKeyOnTheJoinedKey() :void
    {
        // Neither AQL::KEY nor Facet::PROPERTY declared: _key on the joined side,
        // the facet key itself on the main side.
        [ $docRef , $for , $match ] = resolveFacetJoin( 'place' , [ AQL::COLLECTION => 'places' ] , AQL::DOC ) ;

        $this->assertSame( 'doc_place'                      , $docRef ) ;
        $this->assertSame( 'FOR doc_place IN places'        , $for    ) ;
        $this->assertSame( 'doc_place._key == doc.place'    , $match  ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testDeclaredKeyAndPropertyDriveBothSides() :void
    {
        // The document holds the foreign key: doc.authorId == author._key.
        [ $docRef , $for , $match ] = resolveFacetJoin
        (
            'author' ,
            [
                AQL::COLLECTION => 'authors'  ,
                AQL::KEY        => '_key'     ,
                Facet::PROPERTY => 'authorId' ,
            ] ,
            AQL::DOC
        ) ;

        $this->assertSame( 'doc_author'                       , $docRef ) ;
        $this->assertSame( 'FOR doc_author IN authors'        , $for    ) ;
        $this->assertSame( 'doc_author._key == doc.authorId'  , $match  ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testReverseOneToManyJoinsOnTheJoinedSideAttribute() :void
    {
        // The joined documents reference the main one: comment.articleId == doc._key.
        [ , , $match ] = resolveFacetJoin
        (
            'comments' ,
            [
                AQL::COLLECTION => 'comments'  ,
                AQL::KEY        => 'articleId' ,
                Facet::PROPERTY => '_key'      ,
            ] ,
            AQL::DOC
        ) ;

        $this->assertSame( 'doc_comments.articleId == doc._key' , $match ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testArrayFlagTurnsTheEqualityIntoAMembershipTest() :void
    {
        [ , , $match ] = resolveFacetJoin
        (
            'tags' ,
            [
                AQL::COLLECTION => 'tags'   ,
                AQL::ARRAY      => true     ,
                Facet::PROPERTY => 'tagIds' ,
            ] ,
            AQL::DOC
        ) ;

        $this->assertSame( 'doc_tags._key IN doc.tagIds' , $match ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testArrayFlagExplicitlyFalseKeepsTheEquality() :void
    {
        [ , , $match ] = resolveFacetJoin( 'tags' , [ AQL::COLLECTION => 'tags' , AQL::ARRAY => false ] , AQL::DOC ) ;

        $this->assertSame( 'doc_tags._key == doc.tags' , $match ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testMainDocumentReferenceIsHonoured() :void
    {
        // Nested contexts pass their own document reference (a traversed vertex,
        // an item of an expanded array…): only the right-hand side moves.
        [ $docRef , $for , $match ] = resolveFacetJoin( 'place' , [ AQL::COLLECTION => 'places' ] , 'item' ) ;

        $this->assertSame( 'doc_place'                   , $docRef ) ;
        $this->assertSame( 'FOR doc_place IN places'     , $for    ) ;
        $this->assertSame( 'doc_place._key == item.place' , $match ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testMissingCollectionLeavesTheForClauseIncomplete() :void
    {
        // No AQL::COLLECTION declared — the helper does not invent one, exactly
        // like the three builders did before the extraction: aqlFor() drops the
        // `IN` altogether, so a malformed facet definition surfaces as an
        // invalid query rather than as a silent join.
        [ $docRef , $for , $match ] = resolveFacetJoin( 'place' , [] , AQL::DOC ) ;

        $this->assertSame( 'doc_place'                   , $docRef ) ;
        $this->assertSame( 'FOR doc_place'               , $for    ) ;
        $this->assertSame( 'doc_place._key == doc.place' , $match  ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testDottedFacetKeyIsUsedVerbatimOnBothSides() :void
    {
        // The facet key is config-trusted and reaches the reference as-is.
        [ $docRef , $for , $match ] = resolveFacetJoin( 'offers.seller' , [ AQL::COLLECTION => 'sellers' ] , AQL::DOC ) ;

        $this->assertSame( 'doc_offers.seller'                             , $docRef ) ;
        $this->assertSame( 'FOR doc_offers.seller IN sellers'              , $for    ) ;
        $this->assertSame( 'doc_offers.seller._key == doc.offers.seller'   , $match  ) ;
    }
}
