<?php

namespace tests\oihana\arango\commands\actions\documents;

use JsonSerializable;

use oihana\arango\commands\actions\documents\DocumentsCommandHarvest;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\commands\enums\ExitCode;
use oihana\models\enums\ModelParam;
use oihana\models\interfaces\ListModel;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host wiring {@see DocumentsCommandHarvest::harvest()} for testing.
 *
 * Two framework / sibling seams are stubbed so the test focuses on the
 * harvest loop itself: Symfony's file lock ({@see lock()}/{@see release()})
 * and the upsert action ({@see upsert()}, which records its option payload
 * and returns a caller-controlled status). The list model and the harvested
 * documents are lightweight doubles.
 */
class DocumentsCommandHarvestHost
{
    use DocumentsCommandHarvest ;

    /** Controls the stubbed Symfony lock. */
    public bool $lockResult = true ;
    public bool $released   = false ;

    /** Records each upsert() option payload and the status to return. */
    public array $upsertCalls  = [] ;
    public int   $upsertReturn = ExitCode::SUCCESS ;

    public function __construct( ?Documents $documents , ?ListModel $list )
    {
        $this->documents = $documents ;
        $this->list      = $list ;
        $this->fields    = [] ;          // skip the verbose-table branch in outputDocuments()
    }

    public function callHarvest( InputInterface $input , OutputInterface $output ) :int
    {
        return $this->harvest( $input , $output ) ;
    }

    /** Stub Symfony LockableTrait::lock() — no real file lock acquired. */
    private function lock( ?string $name = null , bool $blocking = false ) :bool
    {
        return $this->lockResult ;
    }

    /** Stub Symfony LockableTrait::release(). */
    private function release() :void
    {
        $this->released = true ;
    }

    /** Stub the upsert action seam — records the payload, returns a status. */
    public function upsert( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        $this->upsertCalls[] = $option ;
        return $this->upsertReturn ;
    }
}

/**
 * Unit coverage for {@see DocumentsCommandHarvest}.
 */
#[CoversTrait(DocumentsCommandHarvest::class)]
class DocumentsCommandHarvestTest extends TestCase
{
    private function input() :ArrayInput
    {
        $input = new ArrayInput( [] , new InputDefinition( [] ) ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    private function buffer( bool $verbose = false ) :BufferedOutput
    {
        return new BufferedOutput
        (
            $verbose ? OutputInterface::VERBOSITY_VERBOSE : OutputInterface::VERBOSITY_NORMAL
        ) ;
    }

    /** A harvested document exposing ->id and ->jsonSerialize(). */
    private function thing( string $id ) :object
    {
        return new class( $id ) implements JsonSerializable
        {
            public function __construct( public string $id ) {}
            public function jsonSerialize() :array { return [ 'id' => $this->id , 'name' => 'n' ] ; }
        } ;
    }

    /** A ListModel double returning the given documents. */
    private function listModel( array $documents ) :ListModel
    {
        return new class( $documents ) implements ListModel
        {
            public function __construct( private array $documents ) {}
            public function list( array $init = [] ) :array { return $this->documents ; }
        } ;
    }

    private function host( array $documents ) :DocumentsCommandHarvestHost
    {
        return new DocumentsCommandHarvestHost
        (
            new MockDocuments() ,
            $this->listModel( $documents ) ,
        ) ;
    }

    public function testReturnsSuccessWhenTheLockIsAlreadyHeld() :void
    {
        $host = $this->host( [ $this->thing( 't/1' ) ] ) ;
        $host->lockResult = false ;
        $output = $this->buffer( true ) ;

        $code = $host->callHarvest( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertCount( 0 , $host->upsertCalls ) ;
        $this->assertStringContainsString( 'already running' , $output->fetch() ) ;
    }

    public function testReturnsSuccessWhenThereIsNothingToHarvest() :void
    {
        $host   = $this->host( [] ) ;
        $output = $this->buffer( true ) ;

        $code = $host->callHarvest( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertCount( 0 , $host->upsertCalls ) ;
        $this->assertStringContainsString( 'No document to harvest' , $output->fetch() ) ;
    }

    public function testHarvestsEachDocumentVerbose() :void
    {
        $host   = $this->host( [ $this->thing( 't/1' ) , $this->thing( 't/2' ) ] ) ;
        $output = $this->buffer( true ) ;

        $code = $host->callHarvest( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertCount( 2 , $host->upsertCalls ) ;
        $this->assertTrue( $host->released ) ;

        $first = $host->upsertCalls[ 0 ] ;
        $this->assertSame( [ ModelParam::ID => 't/1' ]        , $first[ Arango::SEARCH ] ) ;
        $this->assertSame( [ 'id' => 't/1' , 'name' => 'n' ]  , $first[ Arango::INSERT ] ) ;
        $this->assertSame( [ 'id' => 't/1' , 'name' => 'n' ]  , $first[ Arango::UPDATE ] ) ;
    }

    public function testHarvestsEachDocumentNonVerbose() :void
    {
        $host   = $this->host( [ $this->thing( 't/1' ) ] ) ;
        $output = $this->buffer( false ) ;

        $code = $host->callHarvest( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertCount( 1 , $host->upsertCalls ) ;
        $this->assertTrue( $host->released ) ;
    }

    public function testReturnsFailureWhenAnUpsertFails() :void
    {
        $host = $this->host( [ $this->thing( 't/1' ) ] ) ;
        $host->upsertReturn = ExitCode::FAILURE ;
        $output = $this->buffer( true ) ;

        $code = $host->callHarvest( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertTrue( $host->released ) ;
    }
}
