<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\clients\cursor\enums\CursorField;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsGetTrait::get()}.
 *
 * The exact AQL of buildGetQuery is already locked by GetQueryTraitTest; here we
 * verify the orchestration: get() builds the query, hands it to getObject(), and
 * returns whatever the fetch seam yields (object or null).
 */
final class DocumentsGetTraitTest extends TestCase
{
    public function testGetWiresBuiltQueryToGetObjectAndReturnsResult() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' , 'name' => 'Marc' ] ;

        $result = $model->get( [ Arango::VALUE => 'k1' ] ) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertMatchesRegularExpression
        (
            '/^FOR doc IN @@collection FILTER doc\._key == @q_\d+ RETURN doc$/' ,
            $model->lastQuery ,
        ) ;
        $this->assertArrayHasKey( '@collection' , $model->lastBinds ) ;
        $this->assertContains( 'k1' , $model->lastBinds ) ;
    }

    public function testGetReturnsNullWhenNoDocument() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = null ;

        $this->assertNull( $model->get( [ Arango::VALUE => 'missing' ] ) ) ;
    }

    public function testGetWithoutProfilePassesNoProfileOption() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->get( [ Arango::VALUE => 'k1' ] ) ;
        $this->assertArrayNotHasKey( CursorField::PROFILE , $model->lastOptions ) ;
    }

    public function testGetWithProfileThreadsTheProfileOption() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->get( [ Arango::VALUE => 'k1' , Arango::PROFILE => true ] ) ;
        $this->assertSame( 2 , $model->lastOptions[ CursorField::PROFILE ] ) ;
    }
}
