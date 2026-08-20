<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsListTrait::list()}.
 */
final class DocumentsListTraitTest extends TestCase
{
    public function testListReturnsDocumentsFromBuiltQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $result = $model->list( [] ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertSame( 'FOR doc IN @@collection RETURN doc' , $model->lastQuery ) ;
    }

    public function testListHydratesAnUngroupedResult() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->list( [] ) ;

        $this->assertFalse( $model->lastRaw ) ;
    }

    /**
     * A grouped line is not a document: the schema and the alters are skipped, so an
     * aggregate the schema class does not declare survives the read instead of being
     * dropped — or, when the name does collide, coerced into that property's type.
     */
    public function testListReadsAGroupedResultRaw() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->groupable = [ 'year' => 'year' ] ; // fail-closed: the dimension must be whitelisted

        $model->list( [ Arango::GROUP => [ Group::BY => 'year' , Group::AGG => [ 'total' => 'sum:amount' ] ] ] ) ;

        $this->assertTrue( $model->lastRaw ) ;
    }

    /**
     * The raw `Arango::COLLECT` spec is the other door into the same clause, and it
     * switches the read just the same.
     */
    public function testListReadsARawCollectSpecRaw() :void
    {
        $model = new MockDocuments( 'users' ) ;

        $model->list( [ Arango::COLLECT => [ AQL::ASSIGN => [ 'year' => 'doc.year' ] ] ] ) ;

        $this->assertTrue( $model->lastRaw ) ;
    }

    /**
     * The dimension is not whitelisted and there is no aggregate: no COLLECT is
     * emitted, the query still returns documents, and they are still hydrated.
     */
    public function testListHydratesWhenTheGroupSpecEmitsNoCollect() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->groupable = [ 'year' => 'year' ] ;

        $model->list( [ Arango::GROUP => [ Group::BY => 'unknown' ] ] ) ;

        $this->assertFalse( $model->lastRaw ) ;
    }

    public function testListWithLimitAndSort() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [] ;
        $model->sortable = [ 'name' => 'name' ] ; // fail-closed: the sort key must be whitelisted

        $model->list( [ Arango::LIMIT => 10 , Arango::OFFSET => 5 , 'sort' => 'name' ] ) ;

        $this->assertSame( 'FOR doc IN @@collection SORT doc.name ASC LIMIT 5, 10 RETURN doc' , $model->lastQuery ) ;
    }

    public function testListWithoutProfileDoesNotSetTheOption() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->list( [] ) ;
        $this->assertArrayNotHasKey( CursorField::PROFILE , $model->lastOptions ) ;
    }

    public function testListWithProfileTrueRequestsProfileLevelTwo() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->list( [ Arango::PROFILE => true ] ) ;
        $this->assertSame( 2 , $model->lastOptions[ CursorField::PROFILE ] ) ;
    }

    public function testListWithExplicitProfileLevel() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->list( [ Arango::PROFILE => 1 ] ) ;
        $this->assertSame( 1 , $model->lastOptions[ CursorField::PROFILE ] ) ;
    }

    public function testListForwardsTheInitAsAlterationContext() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $init  = [ Arango::SKIN => 'full' , Arango::LIMIT => 10 ] ;

        $model->list( $init ) ;

        // list() hands the whole $init to getDocuments as the alteration context,
        // so an Alter::MAP callback can read $context[ Arango::SKIN ].
        $this->assertSame( $init , $model->lastContext ) ;
    }
}
