<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsLastTrait::last()}.
 */
final class DocumentsLastTraitTest extends TestCase
{
    public function testLastReturnsFirstResultOfTheLatestQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = (object) [ '_key' => 'latest' ] ;

        $result = $model->last( [] ) ;

        $this->assertSame( $model->firstResult , $result ) ;
        $this->assertSame( 'FOR doc IN @@collection SORT doc.modified DESC LIMIT 1 RETURN doc' , $model->lastQuery ) ;
    }

    public function testLastHonorsCustomSortProperty() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->last( [ Arango::PROPERTY => 'created' ] ) ;

        $this->assertSame( 'FOR doc IN @@collection SORT doc.created DESC LIMIT 1 RETURN doc' , $model->lastQuery ) ;
    }
}
