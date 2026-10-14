<?php

namespace tests\oihana\arango\commands\actions;

use RuntimeException;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\collection\enums\CollectionField;
use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\Database;
use oihana\arango\commands\actions\ArangoPreserveAction;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\models\enums\filters\FilterType;

use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoPreserveAction::preserve()} to a fake database.
 */
class ArangoPreserveActionHost
{
    use ArangoPreserveAction ;
    use ArangoConfigTrait ;

    /** The fake database returned by the buildDatabase() seam (null : no client). */
    public ?Database $fakeDatabase = null ;

    public function __construct( array $preserves )
    {
        $this->database = 'mydb' ;
        $this->endpoint = 'tcp://127.0.0.1:8529' ;
        $this->password = 'secret' ;
        $this->username = 'root' ;
        $this->initializeArangoPreserves( [ ArangoCommandParam::PRESERVES => $preserves ] ) ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->fakeDatabase ;
    }

    public function run( $input , $output ) :int
    {
        return $this->preserve( $input , $output ) ;
    }
}

/**
 * Unit coverage for {@see ArangoPreserveAction} : what is refused before anything
 * is written, and what is asked of the database. The transaction itself is
 * proven against a live server by `PreserveReinjectIntegrationTest`.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversTrait(ArangoPreserveAction::class)]
class ArangoPreserveActionTest extends TestCase
{
    /** @var array<string, Collection&MockObject> */
    private array $collections = [] ;

    private function host() :ArangoPreserveActionHost
    {
        return new ArangoPreserveActionHost
        ([
            'articles' =>
            [
                ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] ,
                ArangoCommandParam::PRESERVE_FILTER  => '{"key":"_key","op":"nsw","val":"src:"}' ,
            ] ,
        ]) ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $input = new ArrayInput( $options , new InputDefinition
        ([
            new InputArgument( CommandArg::ACTION , InputArgument::OPTIONAL ) ,
            new InputOption( ArangoCommandOption::COLLECTION , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::DATABASE   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DRY_RUN    , null , InputOption::VALUE_NONE ) ,
        ]) ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /**
     * A fake database : `$exists` tells which collections exist ; every count
     * query answers `$count`, or the next number of `$count` when it is a list.
     */
    private function database( array $exists , int|array $count = 3 ) :Database&MockObject
    {
        $this->collections = [] ;
        foreach( $exists as $name => $present )
        {
            $collection = $this->createMock( Collection::class ) ;
            $collection->method( 'exists' )->willReturn( $present ) ;
            $collection->method( 'properties' )->willReturn( [ CollectionField::TYPE => CollectionType::EDGE ] ) ;
            $this->collections[ $name ] = $collection ;
        }

        $answers = is_array( $count ) ? $count : null ;

        $db = $this->createMock( Database::class ) ;
        $db->method( 'collection' )->willReturnCallback( fn( string $name ) => $this->collections[ $name ] ) ;
        $db->method( 'query' )->willReturnCallback( function( AqlQuery $query ) use ( &$answers , $count )
        {
            $cursor = $this->createMock( Cursor::class ) ;
            $cursor->method( 'all' )->willReturn( [ $answers === null ? $count : ( array_shift( $answers ) ?? 0 ) ] ) ;
            return $cursor ;
        }) ;
        return $db ;
    }

    public function testWithoutAClientNothingIsDone() :void
    {
        $output = new BufferedOutput() ;

        $code = $this->host()->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'No ArangoDB HTTP client available' , $output->fetch() ) ;
    }

    public function testAnExistingBackupIsRefusedAndNothingIsWritten() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'create' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'articles__preserved already exist(s). Run reinject first.' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
    }

    public function testAnAbsentCollectionLeavesNothingToDoAndFails() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => false , 'articles__preserved' => false ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'create' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'articles : absent from the database' , $text ) ;
        $this->assertStringContainsString( 'Nothing to set aside' , $text ) ;
    }

    public function testADryRunCountsAndWritesNothing() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => false ] , 3 ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'create' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 3 document(s) match the rule' , $text ) ;
        $this->assertStringContainsString( 'Dry run — nothing was set aside.' , $text ) ;
    }

    public function testTheBackupTakesTheTypeOfTheCollectionAndTheCopyRunsInATransaction() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => false ] ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->once() )->method( 'create' )->with( [ CollectionField::TYPE => CollectionType::EDGE ] ) ;
        $host->fakeDatabase->expects( $this->once() )->method( 'withTransaction' )
            ->with( $this->isCallable() , [ 'articles__preserved' ] , [ 'articles' ] )
            ->willReturn( 4 ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 4 document(s) set aside in articles__preserved' , $output->fetch() ) ;
    }

    public function testAFailedCopyDropsTheBackupItCreated() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => false ] ) ;
        $host->fakeDatabase->method( 'withTransaction' )->willThrowException( new RuntimeException( 'copy failed' ) ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->once() )->method( 'drop' ) ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'copy failed' ) ;

        $host->run( $this->input() , new BufferedOutput() ) ;
    }

    public function testTheCountsOfTheTransactionMustAgree() :void
    {
        $host = $this->host() ;
        // The plan counts 3 ; in the transaction the copy answers 5, the rule and
        // the backup answer 3 : the transaction must refuse.
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => false ] , [ 3 , 5 , 3 , 3 ] ) ;
        $host->fakeDatabase->method( 'withTransaction' )->willReturnCallback( fn( callable $callback ) => $callback() ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->once() )->method( 'drop' ) ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'articles : 5 document(s) copied, 3 named by the rule, 3 in articles__preserved — nothing was set aside.' ) ;

        $host->run( $this->input() , new BufferedOutput() ) ;
    }
}
