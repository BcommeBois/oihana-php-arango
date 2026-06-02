<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsCountTrait::count()}.
 *
 * Verifies the orchestration: count() builds the count query, hands it to
 * getFirstResult(), and returns the scalar it yields; the optimized flag selects
 * the plain LENGTH(@@collection) form.
 */
final class DocumentsCountTraitTest extends TestCase
{
    public function testFullCountReturnsFirstResult() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 42 ;

        $this->assertSame( 42 , $model->count( [] ) ) ;
        $this->assertSame( 'RETURN LENGTH(FOR doc IN @@collection RETURN 1)' , $model->lastQuery ) ;
    }

    public function testOptimizedCountUsesPlainLength() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 7 ;

        $this->assertSame( 7 , $model->count( [ Arango::OPTIMIZED => true ] ) ) ;
        $this->assertSame( 'RETURN LENGTH(@@collection)' , $model->lastQuery ) ;
    }

    public function testDebugMockShortCircuitsToZero() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->firstResult = 999 ; // must be ignored

        $this->assertSame( 0 , $model->count( [ Arango::DEBUG => true , Arango::MOCK => true ] ) ) ;
    }
}
