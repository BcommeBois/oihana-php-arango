<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsRepsertTrait::repsert()}.
 *
 * repsert() mirrors upsert() but selects the REPLACE branch of buildUpsertQuery.
 */
final class DocumentsRepsertTraitTest extends TestCase
{
    public function testRepsertBuildsReplaceModeQueryAndReturnsObject() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->repsert
        ([
            Arango::SEARCH  => [ '_key' => '1' ] ,
            Arango::INSERT  => [ 'v' => 1 ] ,
            Arango::REPLACE => [ 'v' => 2 ] ,
        ]) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame( 'UPSERT @search INSERT @insert REPLACE @replace IN users RETURN NEW' , $model->lastQuery ) ;
    }
}
