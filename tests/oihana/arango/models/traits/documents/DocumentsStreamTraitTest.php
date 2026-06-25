<?php

namespace tests\oihana\arango\models\traits\documents;

use Generator;

use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-2 coverage for {@see \oihana\arango\models\traits\documents\DocumentsStreamTrait::stream()}.
 *
 * stream() builds the list query then yields from streamDocuments(); the harness
 * captures the query and yields canned documents.
 */
final class DocumentsStreamTraitTest extends TestCase
{
    public function testStreamYieldsDocumentsFromBuiltQuery() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->streamResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $generator = $model->stream( [] ) ;

        $this->assertInstanceOf( Generator::class , $generator ) ;
        $this->assertSame( $model->streamResult , iterator_to_array( $generator ) ) ;
        $this->assertSame( 'FOR doc IN @@collection RETURN doc' , $model->lastQuery ) ;
    }

    public function testStreamBuildsTheListQueryWithLimit() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->streamResult = [] ;

        iterator_to_array( $model->stream( [ Arango::LIMIT => 5 ] ) ) ;

        $this->assertSame( 'FOR doc IN @@collection LIMIT 5 RETURN doc' , $model->lastQuery ) ;
    }

    public function testStreamForwardsTheInitAsAlterationContext() :void
    {
        $model = new MockDocuments( 'users' ) ;
        $model->streamResult = [] ;
        $init = [ Arango::SKIN => 'full' , Arango::LIMIT => 5 ] ;

        // streamDocuments() is a generator: the context is captured only once iterated.
        iterator_to_array( $model->stream( $init ) ) ;

        $this->assertSame( $init , $model->lastContext ) ;
    }
}
