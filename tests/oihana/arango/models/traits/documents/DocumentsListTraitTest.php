<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\enums\Arango;

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

    public function testListWithLimitAndSort() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->documentsResult = [] ;

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
}
