<?php

namespace tests\oihana\arango\commands\actions;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\actions\ArangoListCollectionsAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoListCollectionsAction::collections()} for testing.
 *
 * The HTTP bridge is the only external dependency, so the protected
 * {@see buildDatabase()} seam is overridden to return a caller-supplied
 * fake {@see Database} (or null) — no network I/O happens.
 */
class ArangoListCollectionsActionHost
{
    use ArangoListCollectionsAction ;
    use ArangoConfigTrait ;

    /** When true, the buildDatabase() seam returns null (no client). */
    public bool $returnNullDatabase = false ;

    /** The fake database returned by the buildDatabase() seam. */
    public ?Database $fakeDatabase = null ;

    public function __construct()
    {
        $this->database = 'mydb' ;
        $this->endpoint = 'tcp://127.0.0.1:8529' ;
        $this->password = 'secret' ;
        $this->username = 'root' ;
    }

    /** Public proxy to the protected action under test. */
    public function listCollections( $input , $output ) :int
    {
        return $this->collections( $input , $output ) ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->returnNullDatabase ? null : $this->fakeDatabase ;
    }
}

/**
 * Unit coverage for {@see ArangoListCollectionsAction}.
 */
#[CoversTrait(ArangoListCollectionsAction::class)]
#[AllowMockObjectsWithoutExpectations]
class ArangoListCollectionsActionTest extends TestCase
{
    /**
     * Full option surface read by collections(), so a plain ArrayInput can
     * answer every getOption() call.
     */
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::SYSTEM   , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::ALL      , null , InputOption::VALUE_NONE ) ,
        ]) ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /** A bare collection-like object exposing only getName(). */
    private function collection( string $name ) :object
    {
        return new class( $name )
        {
            public function __construct( private readonly string $name ) {}
            public function getName() :string { return $this->name ; }
        } ;
    }

    /** A Database double whose collections() returns the given names. */
    private function databaseReturning( array $names ) :Database
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willReturn( array_map( $this->collection( ... ) , $names ) ) ;
        return $db ;
    }

    public function testListsUserCollectionsByDefault() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'orders' , 'users' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ orders' , $text ) ;
        $this->assertStringContainsString( '→ users' , $text ) ;
    }

    public function testSortsTheCollectionNames() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $this->databaseReturning( [ 'zeta' , 'alpha' , 'mu' ] ) ;
        $output = new BufferedOutput() ;

        $host->listCollections( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertLessThan( strpos( $text , 'mu' )   , strpos( $text , 'alpha' ) ) ;
        $this->assertLessThan( strpos( $text , 'zeta' ) , strpos( $text , 'mu' ) ) ;
    }

    public function testSystemScopeKeepsOnlySystemCollections() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $this->databaseReturning( [ '_users' , 'orders' , '_graphs' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input( [ '--' . ArangoCommandOption::SYSTEM => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ _graphs' , $text ) ;
        $this->assertStringContainsString( '→ _users' , $text ) ;
        $this->assertStringNotContainsString( 'orders' , $text ) ;
    }

    public function testAllScopeKeepsSystemAndUserCollections() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $this->databaseReturning( [ '_users' , 'orders' ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input( [ '--' . ArangoCommandOption::ALL => true ] ) , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '→ _users' , $text ) ;
        $this->assertStringContainsString( '→ orders' , $text ) ;
    }

    public function testReportsWhenThereAreNoCollections() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $this->databaseReturning( [] ) ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no collections' , $output->fetch() ) ;
    }

    public function testFailsWhenNoHttpClientIsAvailable() :void
    {
        $host = new ArangoListCollectionsActionHost() ;
        $host->returnNullDatabase = true ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client available' , $output->fetch() ) ;
    }

    public function testFailsWhenTheHttpApiIsUnreachable() :void
    {
        $db = $this->createMock( Database::class ) ;
        $db->method( 'collections' )->willThrowException( new ArangoException( 'connection refused' ) ) ;

        $host = new ArangoListCollectionsActionHost() ;
        $host->fakeDatabase = $db ;
        $output = new BufferedOutput() ;

        $code = $host->listCollections( $this->input() , $output ) ;
        $text = $output->fetch() ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Unable to list the collections' , $text ) ;
        $this->assertStringContainsString( 'connection refused' , $text ) ;
    }
}
