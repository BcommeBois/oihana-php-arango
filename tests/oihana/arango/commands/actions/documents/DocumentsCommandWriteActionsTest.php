<?php

namespace tests\oihana\arango\commands\actions\documents;

use oihana\enums\Boolean;

use UnexpectedValueException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

use tests\oihana\arango\commands\actions\documents\mocks\MockDocumentsCommand;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-5 coverage for the write document command actions (truncate / delete /
 * insert / update / replace / upsert) driven through MockDocumentsCommand.
 */
final class DocumentsCommandWriteActionsTest extends TestCase
{
    private function input() :InputInterface
    {
        $input = new ArrayInput( [] ) ;
        $input->setInteractive( false ) ; // skip confirmation prompts
        return $input ;
    }

    private function verboseOutput() :BufferedOutput
    {
        $output = new BufferedOutput() ;
        $output->setVerbosity( BufferedOutput::VERBOSITY_VERBOSE ) ;
        return $output ;
    }

    private function command( ?MockDocuments $documents ) :MockDocumentsCommand
    {
        return new MockDocumentsCommand( $documents ) ;
    }

    private function model() :MockDocuments
    {
        return new MockDocuments( 'places' ) ;
    }

    // ---------------------------------------------------------------- truncate

    public function testTruncateConfirmedSucceeds() :void
    {
        $model = $this->model() ;
        $model->truncateResult = true ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callTruncate( $this->input() , $output , Boolean::TRUE ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'Truncate operation succeed' , $output->fetch() ) ;
    }

    public function testTruncateFailureThrows() :void
    {
        $model = $this->model() ;
        $model->truncateResult = false ;

        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $model )->callTruncate( $this->input() , new BufferedOutput() , Boolean::TRUE ) ;
    }

    public function testTruncateNotConfirmedDoesNothing() :void
    {
        $output = new BufferedOutput() ;
        $code   = $this->command( $this->model() )->callTruncate( $this->input() , $output , null ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'Truncate nothing' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- delete

    public function testDeleteRemovesASingleDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callDelete( $this->input() , $output , [ 'k1' ] ) ;

        $this->assertStringContainsString( 'is removed' , $output->fetch() ) ;
    }

    public function testDeleteRemovesMultipleDocuments() :void
    {
        $model = $this->model() ;
        $model->documentsResult = [ (object) [ '_key' => 'k1' ] , (object) [ '_key' => 'k2' ] ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callDelete( $this->input() , $output , [ 'k1' , 'k2' ] ) ;

        $this->assertStringContainsString( 'The documents have been removed' , $output->fetch() ) ;
    }

    public function testDeleteReportsWhenNothingWasRemoved() :void
    {
        $model = $this->model() ;
        $model->objectResult = null ; // delete() returns null

        $output = new BufferedOutput() ;
        $this->command( $model )->callDelete( $this->input() , $output , [ 'k1' ] ) ;

        $this->assertStringContainsString( 'No deletions occurred' , $output->fetch() ) ;
    }

    public function testDeleteWithNoValuesIsANoOp() :void
    {
        $output = new BufferedOutput() ;
        $this->command( $this->model() )->callDelete( $this->input() , $output , [] ) ;

        $this->assertStringContainsString( 'No deletions occurred' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- insert

    public function testInsertCreatesADocumentFromJsonString() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callInsert( $this->input() , $output , '{"name":"Place"}' ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'The document is inserted' , $output->fetch() ) ;
    }

    public function testInsertRejectsNonStringNonArrayOption() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callInsert( $this->input() , new BufferedOutput() , 123 ) ;
    }

    public function testInsertRejectsUndefinedDocument() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callInsert( $this->input() , new BufferedOutput() , 'null' ) ;
    }

    public function testInsertRejectsNullDocumentFromEmptyArray() :void
    {
        // option [] → document null → json_decode('') → null → guard throws (no deprecation).
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callInsert( $this->input() , new BufferedOutput() , [] ) ;
    }

    public function testInsertVerboseRendersTheInsertedDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'new1' ] ;

        $output = $this->verboseOutput() ;
        $this->command( $model )->callInsert( $this->input() , $output , '{"name":"Place"}' ) ;

        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- update

    public function testUpdateModifiesAnIdentifiedDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callUpdate( $this->input() , $output , [ 'k1' , '{"name":"New"}' ] ) ;

        $this->assertStringContainsString( 'is updated' , $output->fetch() ) ;
    }

    public function testUpdateRequiresAnIdentifier() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callUpdate( $this->input() , new BufferedOutput() , [] ) ;
    }

    public function testUpdateRequiresADocument() :void
    {
        // value present, document missing → second guard.
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callUpdate( $this->input() , new BufferedOutput() , [ 'k1' ] ) ;
    }

    public function testUpdateAcceptsAPipeSeparatedStringOption() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callUpdate( $this->input() , $output , 'k1|{"name":"New"}' ) ;

        $this->assertStringContainsString( 'is updated' , $output->fetch() ) ;
    }

    public function testUpdateRejectsNonStringNonArrayOption() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callUpdate( $this->input() , new BufferedOutput() , 123 ) ;
    }

    public function testUpdateVerboseRendersTheDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = $this->verboseOutput() ;
        $this->command( $model )->callUpdate( $this->input() , $output , [ 'k1' , '{"name":"New"}' ] ) ;

        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- replace

    public function testReplaceReplacesAnIdentifiedDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callReplace( $this->input() , $output , [ 'k1' , '{"name":"New"}' ] ) ;

        $this->assertStringContainsString( 'is replaced' , $output->fetch() ) ;
    }

    public function testReplaceRequiresADocument() :void
    {
        // value present but document missing → second guard.
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callReplace( $this->input() , new BufferedOutput() , [ 'k1' ] ) ;
    }

    public function testReplaceRequiresAnIdentifier() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callReplace( $this->input() , new BufferedOutput() , [] ) ;
    }

    public function testReplaceAcceptsAPipeSeparatedStringOption() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callReplace( $this->input() , $output , 'k1|{"name":"New"}' ) ;

        $this->assertStringContainsString( 'is replaced' , $output->fetch() ) ;
    }

    public function testReplaceRejectsNonStringNonArrayOption() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callReplace( $this->input() , new BufferedOutput() , 123 ) ;
    }

    public function testReplaceVerboseRendersTheDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = $this->verboseOutput() ;
        $this->command( $model )->callReplace( $this->input() , $output , [ 'k1' , '{"name":"New"}' ] ) ;

        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- upsert

    public function testUpsertWritesADocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callUpsert
        (
            $this->input() ,
            $output ,
            [ 'search' => [ '_key' => '1' ] , 'insert' => [ 'v' => 1 ] , 'update' => [ 'v' => 2 ] ] ,
        ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'The document is upserted' , $output->fetch() ) ;
    }

    public function testUpsertRejectsNonArrayOption() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        $this->command( $this->model() )->callUpsert( $this->input() , new BufferedOutput() , 42 ) ;
    }

    public function testUpsertVerboseRendersJson() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = $this->verboseOutput() ;
        $this->command( $model )->callUpsert
        (
            $this->input() ,
            $output ,
            [ 'search' => [ '_key' => '1' ] , 'insert' => [ 'v' => 1 ] , 'update' => [ 'v' => 2 ] ] ,
        ) ;

        $this->assertStringContainsString( 'k1' , $output->fetch() ) ;
    }

    public function testUpsertAcceptsAJsonStringOption() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callUpsert
        (
            $this->input() ,
            $output ,
            '{"search":{"_key":"1"},"insert":{"v":1},"update":{"v":2}}' ,
        ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'The document is upserted' , $output->fetch() ) ;
    }
}
