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
     * 🚨 A `SUM` the store computed carries the noise of an addition of floats, and
     * nothing downstream reads a grouped row : the summed aggregates come back shed,
     * and nothing else is touched — a `max` is a stored value, a count an integer, a
     * dimension a stored value.
     */
    public function testAGroupedListShedsTheNoiseOfItsSumsAndAveragesOnly() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->groupable = [ 'year' => 'year' ] ;
        $model->documentsResult =
        [
            (object) [ 'year' => 2025 , 'total' => 1380913.4299999992 , 'mean' => 45292.249999999985 , 'biggest' => 202799.4000000001 , 'n' => 3 , 'ratio' => 1.23456789012345 ] ,
            (object) [ 'year' => 2026 , 'total' => 12400 , 'mean' => null , 'biggest' => 0.5 , 'n' => 1 , 'ratio' => 0.1 ] ,
        ];

        $rows = $model->list
        ([
            Arango::GROUP =>
            [
                Group::BY  => [ 'year' , 'ratio' ] ,
                Group::AGG => [ 'total' => 'sum:amount' , 'mean' => 'avg:amount' , 'biggest' => 'max:amount' ] ,
                Group::COUNT => 'n' ,
            ],
        ]);

        $this->assertTrue( $model->lastRaw ) ;

        $this->assertSame( 1380913.43 , $rows[ 0 ]->total ) ;
        $this->assertSame( 45292.25   , $rows[ 0 ]->mean ) ;

        // Untouched : the maximum, the count, the dimensions — and a sum that is an integer.
        $this->assertSame( 202799.4000000001 , $rows[ 0 ]->biggest ) ;
        $this->assertSame( 3 , $rows[ 0 ]->n ) ;
        $this->assertSame( 1.23456789012345 , $rows[ 0 ]->ratio ) ;
        $this->assertSame( 12400 , $rows[ 1 ]->total ) ;
        $this->assertNull( $rows[ 1 ]->mean ) ;
    }

    /**
     * A raw `Arango::COLLECT` spec is code written by hand : its author decides what it
     * serves, and nothing is shed on its behalf.
     */
    public function testARawCollectSpecIsNotShed() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [ (object) [ 'year' => 2025 , 'total' => 1380913.4299999992 ] ] ;

        $rows = $model->list( [ Arango::COLLECT => [ AQL::ASSIGN => [ 'year' => 'doc.year' ] , AQL::AGGREGATE => [ 'total' => 'SUM(doc.amount)' ] ] ] ) ;

        $this->assertTrue( $model->lastRaw ) ;
        $this->assertSame( 1380913.4299999992 , $rows[ 0 ]->total ) ;
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
