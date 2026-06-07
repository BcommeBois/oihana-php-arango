<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\ArrayMode;

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

    public function testUpsertSeedsDeclaredArrayFieldsOnInsertBranch() :void
    {
        $model = new MockDocuments( 'Playlist' ) ;
        $model->objectResult = (object) [ '_key' => 'p1' ] ;
        $model->arrays =
        [
            'tags'   => [ Arango::MODE => ArrayMode::SET  , Arango::COUNTER => null ] ,
            'tracks' => [ Arango::MODE => ArrayMode::LIST , Arango::COUNTER => 'numberOfTracks' ] ,
        ] ;

        $model->upsert
        ([
            Arango::SEARCH => [ 'name' => 'A' ] ,
            Arango::INSERT => [ 'name' => 'A' ] ,
            Arango::UPDATE => [ 'active' => true ] ,
        ]) ;

        // Only the INSERT branch is seeded (a brand-new document), not the UPDATE branch.
        $insert = $model->lastBinds[ 'insert' ] ;
        $this->assertSame( [] , $insert[ 'tags' ] ) ;
        $this->assertSame( [] , $insert[ 'tracks' ] ) ;
        $this->assertSame( 0 , $insert[ 'numberOfTracks' ] ) ;
        $this->assertArrayNotHasKey( 'tags' , $model->lastBinds[ 'update' ] ) ;
    }
}
