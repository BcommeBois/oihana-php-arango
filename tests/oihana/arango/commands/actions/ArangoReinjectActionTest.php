<?php

namespace tests\oihana\arango\commands\actions;

use RuntimeException;

use oihana\arango\clients\aql\AqlQuery;
use oihana\arango\clients\collection\Collection;
use oihana\arango\clients\cursor\Cursor;
use oihana\arango\clients\Database;
use oihana\arango\commands\actions\ArangoReinjectAction;
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

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoReinjectAction::reinject()} to a fake database.
 */
class ArangoReinjectActionHost
{
    use ArangoReinjectAction ;
    use ArangoConfigTrait ;

    /** The fake database returned by the buildDatabase() seam (null : no client). */
    public ?Database $fakeDatabase = null ;

    public function __construct( array $preserves , array $protected = [] )
    {
        $this->database = 'mydb' ;
        $this->endpoint = 'tcp://127.0.0.1:8529' ;
        $this->password = 'secret' ;
        $this->username = 'root' ;
        $this->initializeArangoPreserves( [ ArangoCommandParam::PRESERVES => $preserves ] ) ;
        $this->initializeArangoOptions( [ ArangoCommandParam::RESTORE => [ ArangoCommandParam::PROTECTED => $protected ] ] ) ;
    }

    public function getQuestionHelper() :QuestionHelper
    {
        return new QuestionHelper() ;
    }

    protected function buildDatabase( string $endpoint , string $username , string $password , string $database ) :?Database
    {
        return $this->fakeDatabase ;
    }

    public function run( $input , $output ) :int
    {
        return $this->reinject( $input , $output ) ;
    }
}

/**
 * Unit coverage for {@see ArangoReinjectAction} : what is refused before anything
 * is written, the confirmation, and what is asked of the database. The transaction
 * itself is proven against a live server by `PreserveReinjectIntegrationTest`.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversTrait(ArangoReinjectAction::class)]
class ArangoReinjectActionTest extends TestCase
{
    /** @var array<string, Collection&MockObject> */
    private array $collections = [] ;

    private function host( array $protected = [] ) :ArangoReinjectActionHost
    {
        return new ArangoReinjectActionHost
        (
            [
                'articles' =>
                [
                    ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] ,
                    ArangoCommandParam::PRESERVE_FILTER  => '{"key":"_key","op":"nsw","val":"src:"}' ,
                ] ,
            ] ,
            $protected ,
        ) ;
    }

    private function input( array $options = [] , bool $interactive = false ) :ArrayInput
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
            new InputOption( ArangoCommandOption::FORCE      , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::YES        , null , InputOption::VALUE_NONE ) ,
        ]) ) ;
        $input->setInteractive( $interactive ) ;
        return $input ;
    }

    /**
     * A fake database : `$exists` tells which collections exist ; the collection
     * holds `$total` documents, the rule names 2 of them, the backup holds 3, and
     * the put back copies 3.
     */
    private function database( array $exists , int $total = 100 ) :Database&MockObject
    {
        $this->collections = [] ;
        foreach( $exists as $name => $present )
        {
            $collection = $this->createMock( Collection::class ) ;
            $collection->method( 'exists' )->willReturn( $present ) ;
            $this->collections[ $name ] = $collection ;
        }

        $db = $this->createMock( Database::class ) ;
        $db->method( 'collection' )->willReturnCallback( fn( string $name ) => $this->collections[ $name ] ) ;
        $db->method( 'query' )->willReturnCallback( function( AqlQuery $query ) use ( $total )
        {
            $cursor = $this->createMock( Cursor::class ) ;
            $copiesTheBackup = str_contains( $query->query , 'LENGTH( @@backup )' ) || str_contains( $query->query , 'INTO @@collection' ) ;
            $countsAll       = $query->query === 'RETURN LENGTH( @@collection )' ;
            $cursor->method( 'all' )->willReturn( [ $countsAll ? $total : ( $copiesTheBackup ? 3 : 2 ) ] ) ;
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

    public function testACollectionWithoutABackupIsLeftUntouched() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => false ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'query' ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $text = preg_replace( '/\s+/' , ' ' , $output->fetch() ) ;
        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'articles : nothing was set aside (articles__preserved does not exist) — left untouched.' , $text ) ;
        $this->assertStringContainsString( 'Nothing to reinject' , $text ) ;
    }

    public function testABackupWhoseCollectionIsAbsentIsRefused() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => false , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Absent from the database : articles — restore them before reinject.' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
    }

    public function testAProtectedCollectionIsRefusedWithoutForce() :void
    {
        $host = $this->host( [ 'articles' ] ) ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Refusing to reinject into protected collection(s) : articles' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
    }

    public function testAProtectedCollectionIsWrittenWithForce() :void
    {
        $host = $this->host( [ 'articles' ] ) ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->once() )->method( 'withTransaction' )->willReturn( [ 2 , 3 ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true , '--' . ArangoCommandOption::FORCE => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'this WILL write into protected collection(s) : articles' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
    }

    public function testWithoutYesANonInteractiveRunIsRefused() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Refusing to reinject without confirmation — rerun with --yes.' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
    }

    public function testAnInteractiveRunAnsweredNoWritesNothing() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $input = $this->input( [] , true ) ;
        $input->setStream( $this->stream( "n\n" ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $input , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Aborted.' , $output->fetch() ) ;
    }

    public function testADryRunSummarizesAndWritesNothing() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Target  : mydb @ tcp://127.0.0.1:8529' , $text ) ;
        $this->assertStringContainsString( 'articles : 2 to remove (2.0 % of 100) · 3 to put back' , $text ) ;
        $this->assertStringContainsString( 'Dry run — nothing was put back.' , $text ) ;
    }

    public function testThePutBackRunsInATransactionThenDropsTheBackup() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->expects( $this->once() )->method( 'withTransaction' )
            ->with( $this->isCallable() , [ 'articles' ] , [ 'articles__preserved' ] )
            ->willReturn( [ 2 , 3 ] ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->once() )->method( 'drop' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 2 removed · 3 put back · articles__preserved dropped' , $output->fetch() ) ;
    }

    public function testAFailedPutBackKeepsTheBackup() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->method( 'withTransaction' )->willThrowException( new RuntimeException( 'unique constraint violated' ) ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'unique constraint violated' ) ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , new BufferedOutput() ) ;
    }

    public function testABackupThatCannotBeDroppedIsAWarningNotAFailure() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->method( 'withTransaction' )->willReturn( [ 2 , 3 ] ) ;
        $this->collections[ 'articles__preserved' ]->method( 'drop' )->willThrowException( new RuntimeException( 'busy' ) ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $text = preg_replace( '/\s+/' , ' ' , $output->fetch() ) ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 2 removed · 3 put back' , $text ) ;
        $this->assertStringContainsString( 'articles__preserved could not be dropped (busy)' , $text ) ;
    }

    public function testTheCountsOfTheTransactionMustAgree() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase->method( 'withTransaction' )->willReturnCallback( fn( callable $callback ) => $callback() ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;

        // The put back copies 3 documents, but the rule then names only 2 of the
        // collection : the rule no longer names what was set aside.
        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'articles : 3 document(s) put back, but 2 named by the rule — has the rule changed since preserve ? Nothing was changed.' ) ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , new BufferedOutput() ) ;
    }

    // ---------------------------------------------------------------- the share guard

    public function testARuleNamingMoreThanItsShareIsRefusedBeforeAnyWrite() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] , 3 ) ;
        $host->fakeDatabase->expects( $this->never() )->method( 'withTransaction' ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $text = preg_replace( '/\s+/' , ' ' , $output->fetch() ) ;
        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'articles : the rule names 2 of 3 documents (66.7 %), above its maxPercent of 50 — is the rule right ?' , $text ) ;
        $this->assertStringContainsString( 'Nothing was changed. Rerun with --force to override.' , $text ) ;
    }

    public function testTheShareGuardFailsTheDryRunToo() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] , 3 ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringNotContainsString( 'Dry run' , $text ) ;
    }

    public function testExactlyTheShareIsAccepted() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] , 4 ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 2 to remove (50.0 % of 4) · 3 to put back' , $output->fetch() ) ;
    }

    public function testForceLetsARuleNameMoreThanItsShare() :void
    {
        $host = $this->host() ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] , 3 ) ;
        $host->fakeDatabase->expects( $this->once() )->method( 'withTransaction' )->willReturn( [ 2 , 3 ] ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true , '--' . ArangoCommandOption::FORCE => true ] ) , $output ) ;

        $text = preg_replace( '/\s+/' , ' ' , $output->fetch() ) ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '--force : the rule names more than its maxPercent of the collection.' , $text ) ;
        $this->assertStringContainsString( 'articles : 2 removed · 3 put back' , $text ) ;
    }

    public function testAMaxPercentOfAHundredTurnsTheGuardOff() :void
    {
        $host = new ArangoReinjectActionHost
        ([
            'articles' =>
            [
                ArangoCommandParam::PRESERVE_FILTERS     => [ '_key' => FilterType::STRING ] ,
                ArangoCommandParam::PRESERVE_FILTER      => '{"key":"_key","op":"nsw","val":"src:"}' ,
                ArangoCommandParam::PRESERVE_MAX_PERCENT => 100 ,
            ] ,
        ]) ;
        $host->fakeDatabase = $this->database( [ 'articles' => true , 'articles__preserved' => true ] , 2 ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::DRY_RUN => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 2 to remove (100.0 % of 2) · 3 to put back' , $output->fetch() ) ;
    }

    public function testTheShareGuardIsCheckedAgainInTheTransaction() :void
    {
        $host = $this->host() ;

        // The summary sees 2 of 100 ; in the transaction, the collection holds 3 and 2 are removed.
        $totals = [ 100 , 3 ] ;
        $db = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;
        $host->fakeDatabase = $this->createMock( Database::class ) ;
        $host->fakeDatabase->method( 'collection' )->willReturnCallback( fn( string $name ) => $this->collections[ $name ] ) ;
        $host->fakeDatabase->method( 'query' )->willReturnCallback( function( AqlQuery $query ) use ( &$totals , $db )
        {
            if( $query->query === 'RETURN LENGTH( @@collection )' )
            {
                $cursor = $this->createMock( Cursor::class ) ;
                $cursor->method( 'all' )->willReturn( [ array_shift( $totals ) ] ) ;
                return $cursor ;
            }
            return $db->query( $query ) ;
        }) ;
        $host->fakeDatabase->method( 'withTransaction' )->willReturnCallback( fn( callable $callback ) => $callback() ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->never() )->method( 'drop' ) ;

        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( 'articles : 2 of 3 documents removed (66.7 %), above its maxPercent of 50 — nothing was changed.' ) ;

        $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , new BufferedOutput() ) ;
    }

    /**
     * The transaction itself, run to its end : 2 documents removed, the 3 set aside
     * put back, and the rule then names the 3 it put back — the counts agree, the
     * put back is kept and its counts are what the action reports.
     */
    public function testTheCountsOfTheTransactionAgreeAndThePutBackIsKept() :void
    {
        $host = $this->host() ;
        $db   = $this->database( [ 'articles' => true , 'articles__preserved' => true ] ) ;

        $putBack = false ;

        $host->fakeDatabase = $this->createMock( Database::class ) ;
        $host->fakeDatabase->method( 'collection' )->willReturnCallback( fn( string $name ) => $this->collections[ $name ] ) ;
        $host->fakeDatabase->method( 'query' )->willReturnCallback( function( AqlQuery $query ) use ( &$putBack , $db )
        {
            if ( str_contains( $query->query , 'INTO @@collection' ) )
            {
                $putBack = true ;
            }
            else if ( $putBack && $query->query !== 'RETURN LENGTH( @@collection )' )
            {
                // Once the backup is back, the rule names what was put back.
                $cursor = $this->createMock( Cursor::class ) ;
                $cursor->method( 'all' )->willReturn( [ 3 ] ) ;
                return $cursor ;
            }

            return $db->query( $query ) ;
        }) ;
        $host->fakeDatabase->method( 'withTransaction' )->willReturnCallback( fn( callable $callback ) => $callback() ) ;
        $this->collections[ 'articles__preserved' ]->expects( $this->once() )->method( 'drop' ) ;
        $output = new BufferedOutput() ;

        $code = $host->run( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 2 removed · 3 put back · articles__preserved dropped' , $output->fetch() ) ;
    }

    /**
     * An in-memory input stream holding `$answers`.
     * @return resource
     */
    private function stream( string $answers )
    {
        $stream = fopen( 'php://memory' , 'r+' ) ;
        fwrite( $stream , $answers ) ;
        rewind( $stream ) ;
        return $stream ;
    }
}
