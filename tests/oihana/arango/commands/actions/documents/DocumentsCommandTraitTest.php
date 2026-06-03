<?php

namespace tests\oihana\arango\commands\actions\documents;

use oihana\arango\commands\enums\DocumentsCommandParam;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use tests\oihana\arango\commands\actions\documents\mocks\MockDocumentsCommand;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Tier-5 coverage for the shared helpers of {@see \oihana\arango\commands\actions\documents\DocumentsCommandTrait}:
 * outputDocuments (table / JSON / empty), fetchDocuments and initializeDocuments.
 */
final class DocumentsCommandTraitTest extends TestCase
{
    private function input() :InputInterface
    {
        return new ArrayInput( [] ) ;
    }

    private function command( ?MockDocuments $documents = null ) :MockDocumentsCommand
    {
        return new MockDocumentsCommand( $documents ) ;
    }

    // ---------------------------------------------------------------- outputDocuments

    public function testOutputDocumentsEmptyReportsNone() :void
    {
        $output = new BufferedOutput() ;
        $this->command( $this->cmdModel() )->callOutputDocuments( [] , $this->input() , $output ) ;
        $this->assertStringContainsString( 'No documents found' , $output->fetch() ) ;
    }

    public function testOutputDocumentsVerboseRendersATable() :void
    {
        $output = new BufferedOutput() ;
        $output->setVerbosity( OutputInterface::VERBOSITY_VERBOSE ) ;

        $docs = [ (object) [ '_key' => 'a' , 'name' => 'Alpha' , 'created' => 'c' , 'modified' => 'm' ] ] ;
        $this->command( $this->cmdModel() )->callOutputDocuments( $docs , $this->input() , $output ) ;

        $text = $output->fetch() ;
        $this->assertStringContainsString( 'Documents Found' , $text ) ;
        $this->assertStringContainsString( 'Alpha' , $text ) ; // table cell
    }

    public function testOutputDocumentsVeryVerboseRendersJson() :void
    {
        $output = new BufferedOutput() ;
        $output->setVerbosity( OutputInterface::VERBOSITY_VERY_VERBOSE ) ;

        $docs = [ (object) [ '_key' => 'a' ] ] ;
        $this->command( $this->cmdModel() )->callOutputDocuments( $docs , $this->input() , $output ) ;

        $this->assertStringContainsString( '_key' , $output->fetch() ) ;
    }

    // ---------------------------------------------------------------- fetchDocuments

    public function testFetchDocumentsReturnsTheModelList() :void
    {
        $model = $this->cmdModel() ;
        $model->documentsResult = [ (object) [ '_key' => 'a' ] , (object) [ '_key' => 'b' ] ] ;

        $output = new BufferedOutput() ;
        $result = $this->command( $model )->callFetchDocuments( $output ) ;

        $this->assertSame( $model->documentsResult , $result ) ;
        $this->assertStringContainsString( 'Fetching' , $output->fetch() ) ;
    }

    public function testFetchDocumentsVerboseUsesAProgressIndicator() :void
    {
        $model = $this->cmdModel() ;
        $model->documentsResult = [ (object) [ '_key' => 'a' ] ] ;

        $output = new BufferedOutput() ;
        $output->setVerbosity( OutputInterface::VERBOSITY_VERBOSE ) ;

        $result = $this->command( $model )->callFetchDocuments( $output , null , 'places' ) ;
        $this->assertCount( 1 , $result ) ;
    }

    // ---------------------------------------------------------------- initializeDocuments

    public function testInitializeDocumentsResolvesTheServiceFromContainer() :void
    {
        $model     = $this->cmdModel() ;
        $container = new Container() ;
        $container->set( 'places.model' , $model ) ;

        $command = $this->command() ;
        $result  = $command->callInitializeDocuments( [ DocumentsCommandParam::DOCUMENTS => 'places.model' ] , $container ) ;

        $this->assertSame( $command , $result ) ;
        $this->assertSame( $model , $command->documents ) ;
    }

    public function testInitializeDocumentsNullsWhenUnresolved() :void
    {
        $command = $this->command() ;
        $command->callInitializeDocuments( [] , null ) ;
        $this->assertNull( $command->documents ) ;
    }

    private function cmdModel() :MockDocuments
    {
        return new MockDocuments( 'places' ) ;
    }
}
