<?php

namespace tests\oihana\arango\commands\actions\documents;

use oihana\exceptions\http\Error404;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use tests\oihana\arango\commands\actions\documents\mocks\MockDocumentsCommand;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-5 coverage for the read document command actions (count / exist / get /
 * list / last), driven through MockDocumentsCommand with a MockDocuments model
 * and a real Symfony ArrayInput / BufferedOutput.
 */
final class DocumentsCommandReadActionsTest extends TestCase
{
    private function input() :InputInterface
    {
        return new ArrayInput
        (
            [] ,
            new InputDefinition( [ new InputOption( 'optimized' , 'o' , InputOption::VALUE_NONE ) ] ) ,
        ) ;
    }

    private function command( ?MockDocuments $documents ) :MockDocumentsCommand
    {
        return new MockDocumentsCommand( $documents ) ;
    }

    private function model() :MockDocuments
    {
        return new MockDocuments( 'places' ) ;
    }

    // ---------------------------------------------------------------- count

    public function testCountReportsTheDocumentTotal() :void
    {
        $model = $this->model() ;
        $model->firstResult = 4 ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callCount( $this->input() , $output ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'The collection contains' , $output->fetch() ) ;
    }

    public function testCountWithoutDocumentsModelThrows() :void
    {
        $this->expectException( \UnexpectedValueException::class ) ;
        $this->command( null )->callCount( $this->input() , new BufferedOutput() ) ;
    }

    // ---------------------------------------------------------------- exist

    public function testExistConfirmsASingleDocument() :void
    {
        $model = $this->model() ;
        $model->firstResult = 1 ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callExist( $this->input() , $output , [ 'a' ] ) ;

        $this->assertStringContainsString( 'The document a exist' , $output->fetch() ) ;
    }

    public function testExistConfirmsMultipleDocuments() :void
    {
        $model = $this->model() ;
        $model->firstResult = 2 ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callExist( $this->input() , $output , [ 'a' , 'b' ] ) ;

        $this->assertStringContainsString( 'All the documents exist' , $output->fetch() ) ;
    }

    public function testExistReportsMissingDocument() :void
    {
        $model = $this->model() ;
        $model->firstResult = 0 ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callExist( $this->input() , $output , [ 'a' ] ) ;

        $this->assertStringContainsString( "doesn't exist" , $output->fetch() ) ;
    }

    public function testExistReportsAtLeastOneMissingForMultiple() :void
    {
        $model = $this->model() ;
        $model->firstResult = 1 ; // < 2 distinct values → ALL fails

        $output = new BufferedOutput() ;
        $this->command( $model )->callExist( $this->input() , $output , [ 'a' , 'b' ] ) ;

        $this->assertStringContainsString( 'At least one of the documents does not exist' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- get

    public function testGetOutputsTheFoundDocument() :void
    {
        $model = $this->model() ;
        $model->objectResult = (object) [ '_key' => 'k1' , 'name' => 'Place' ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callGet( $this->input() , $output , [ 'k1' ] ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    public function testGetThrows404WhenNotFound() :void
    {
        $model = $this->model() ;
        $model->objectResult = null ;

        $this->expectException( Error404::class ) ;
        $this->command( $model )->callGet( $this->input() , new BufferedOutput() , [ 'missing' ] ) ;
    }

    // ---------------------------------------------------------------- list

    public function testListOutputsTheDocuments() :void
    {
        $model = $this->model() ;
        $model->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callList( $this->input() , $output ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    public function testListReportsNoDocuments() :void
    {
        $model = $this->model() ;
        $model->documentsResult = [] ;

        $output = new BufferedOutput() ;
        $this->command( $model )->callList( $this->input() , $output ) ;

        $this->assertStringContainsString( 'No documents found' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- last

    public function testLastPrintsThePropertyValue() :void
    {
        $model = $this->model() ;
        $model->firstResult = (object) [ '_key' => 'k1' , 'modified' => '2026-01-01' ] ;

        $output = new BufferedOutput() ;
        $code   = $this->command( $model )->callLast( $this->input() , $output ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( '2026-01-01' , $output->fetch() ) ;
    }

    public function testLastInVerboseModeRendersTheDocument() :void
    {
        $model = $this->model() ;
        $model->documentsResult = [ (object) [ '_key' => 'k1' ] ] ; // outputDocuments path
        $model->firstResult = (object) [ '_key' => 'k1' , 'modified' => '2026-01-01' ] ;

        $output = new BufferedOutput() ;
        $output->setVerbosity( OutputInterface::VERBOSITY_VERBOSE ) ;

        $code = $this->command( $model )->callLast( $this->input() , $output ) ;

        $this->assertSame( 0 , $code ) ;
        $this->assertStringContainsString( 'Documents Found' , $output->fetch() ) ;
    }

    public function testLastThrows404WhenNoDocument() :void
    {
        $model = $this->model() ;
        $model->firstResult = null ;

        $this->expectException( Error404::class ) ;
        $this->command( $model )->callLast( $this->input() , new BufferedOutput() , [ 'created' ] ) ;
    }
}
