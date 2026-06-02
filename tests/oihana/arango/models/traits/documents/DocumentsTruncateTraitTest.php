<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\db\enums\AQL;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsTruncateTrait::truncate()}.
 *
 * truncate() forwards a collection name to collectionTruncate(); the harness
 * captures that name and returns a canned boolean.
 */
final class DocumentsTruncateTraitTest extends TestCase
{
    public function testTruncateUsesTheInstanceCollectionByDefault() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->truncateResult = true ;

        $this->assertTrue( $model->truncate( [] ) ) ;
        $this->assertSame( 'users' , $model->lastTruncatedCollection ) ;
    }

    public function testTruncateHonorsAnExplicitCollectionAndResult() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->truncateResult = false ;

        $this->assertFalse( $model->truncate( [ AQL::COLLECTION => 'sessions' ] ) ) ;
        $this->assertSame( 'sessions' , $model->lastTruncatedCollection ) ;
    }
}
