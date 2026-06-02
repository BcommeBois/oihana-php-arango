<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsUpsertTrait::upsert()}.
 *
 * The exact UPSERT AQL is locked by UpsertQueryTraitTest; here we verify upsert()
 * selects the UPDATE branch and returns the getObject() result.
 */
final class DocumentsUpsertTraitTest extends TestCase
{
    public function testUpsertBuildsUpdateModeQueryAndReturnsObject() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $result = $model->upsert
        ([
            Arango::SEARCH => [ 'email' => 'a@b.c' ] ,
            Arango::INSERT => [ 'name' => 'A' ] ,
            Arango::UPDATE => [ 'active' => true ] ,
        ]) ;

        $this->assertSame( $model->objectResult , $result ) ;
        $this->assertSame( 'UPSERT @search INSERT @insert UPDATE @update IN users RETURN NEW' , $model->lastQuery ) ;
    }
}
