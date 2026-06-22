<?php

namespace tests\oihana\arango\commands;

use RuntimeException;
use Throwable;

use DI\Container;

use oihana\arango\clients\analyzer\IdentityAnalyzer;
use oihana\arango\commands\ArangoCommand;
use oihana\arango\commands\enums\ArangoAction;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\options\views\SearchAliasView;

use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test double for {@see ArangoCommand} exposing a controllable action.
 *
 * The `collections` action (a real ArangoAction) is overridden so execute()
 * can be driven through its success / ExitException / Throwable branches
 * without touching ArangoDB.
 */
class ArangoCommandDouble extends ArangoCommand
{
    public int        $collectionsStatus = ExitCode::SUCCESS ;
    public ?Throwable $collectionsThrow  = null ;

    protected function collections( InputInterface $input , OutputInterface $output ) :int
    {
        if ( $this->collectionsThrow !== null )
        {
            throw $this->collectionsThrow ;
        }
        return $this->collectionsStatus ;
    }
}

/**
 * Unit coverage for {@see ArangoCommand}.
 */
#[CoversClass(ArangoCommand::class)]
class ArangoCommandTest extends TestCase
{
    private function command() :ArangoCommandDouble
    {
        $command = new ArangoCommandDouble( ArangoCommand::NAME , new Container() ) ;
        $command->setApplication( new Application() ) ;
        return $command ;
    }

    // -------------------------------------------------------------- construct / configure

    public function testConstructsWithTheDefaultName() :void
    {
        $command = new ArangoCommand( ArangoCommand::NAME , new Container() ) ;
        $this->assertSame( 'command:arangodb' , $command->getName() ) ;
    }

    public function testConfigureRegistersTheActionArgumentAndOptions() :void
    {
        $definition = $this->command()->getDefinition() ;

        $this->assertTrue( $definition->hasArgument( CommandArg::ACTION ) ) ;

        $this->assertTrue( $definition->hasOption( ArangoCommandOption::ALL ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoCommandOption::COLLECTION ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoCommandOption::LIST ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoCommandOption::SYSTEM ) ) ;

        $this->assertTrue( $definition->hasOption( ArangoConfig::DATABASE ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoConfig::ENDPOINT ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoConfig::PASSWORD ) ) ;
        $this->assertTrue( $definition->hasOption( ArangoConfig::USER ) ) ;
    }

    // -------------------------------------------------------------- init registries

    private function analyzer( string $name ) :AnalyzerDefinition
    {
        return new AnalyzerDefinition( $name , new IdentityAnalyzer() ) ;
    }

    private function searchAliasView( string $name ) :SearchAliasView
    {
        return new SearchAliasView( $name , [ 'customers' => 'inv' ] ) ;
    }

    private function commandWithInit( array $init ) :ArangoCommand
    {
        return new ArangoCommand( ArangoCommand::NAME , new Container() , $init ) ;
    }

    public function testConstructWiresTheAnalyzersRegistryFromInit() :void
    {
        $command = $this->commandWithInit( [ ArangoCommandParam::ANALYZERS => [ $a = $this->analyzer( 'a' ) , $b = $this->analyzer( 'b' ) ] ] ) ;

        $this->assertSame( [ $a , $b ] , $command->getAnalyzerDefinitions() ) ;
    }

    public function testConstructToleratesASingleAnalyzerDefinitionFromInit() :void
    {
        $command = $this->commandWithInit( [ ArangoCommandParam::ANALYZERS => $single = $this->analyzer( 'solo' ) ] ) ;

        $this->assertSame( [ $single ] , $command->getAnalyzerDefinitions() ) ;
    }

    public function testConstructDefaultsTheAnalyzersRegistryToEmptyWithoutTheInitKey() :void
    {
        $this->assertSame( [] , $this->commandWithInit( [] )->getAnalyzerDefinitions() ) ;
    }

    public function testConstructDropsNonDefinitionAnalyzerEntriesFromInit() :void
    {
        $command = $this->commandWithInit( [ ArangoCommandParam::ANALYZERS => [ 'bogus' , $a = $this->analyzer( 'a' ) , null ] ] ) ;

        $this->assertSame( [ $a ] , $command->getAnalyzerDefinitions() ) ;
    }

    public function testConstructWiresTheSearchAliasViewsRegistryFromInit() :void
    {
        $command = $this->commandWithInit( [ ArangoCommandParam::SEARCH_ALIAS_VIEWS => [ $a = $this->searchAliasView( 'a' ) , $b = $this->searchAliasView( 'b' ) ] ] ) ;

        $this->assertSame( [ $a , $b ] , $command->getSearchAliasViews() ) ;
    }

    public function testConstructDefaultsTheSearchAliasViewsRegistryToEmptyWithoutTheInitKey() :void
    {
        $this->assertSame( [] , $this->commandWithInit( [] )->getSearchAliasViews() ) ;
    }

    /**
     * Regression guard for the init-key wiring of the command's declarative
     * registries: each registry is supplied via its own `ArangoCommandParam`
     * key and must reach the command through the constructor. The bug this
     * guards against is a registry whose trait, getter and consuming action all
     * exist while the one constructor line pulling it from `$init` is forgotten
     * (the `analyzers` and `searchAliasViews` keys were both silently dropped
     * this way). A new registry added without its constructor line fails here.
     */
    public function testConstructWiresEveryDeclaredRegistryFromInit() :void
    {
        $command = $this->commandWithInit
        ([
            ArangoCommandParam::ANALYZERS          => [ $a = $this->analyzer( 'a' ) ] ,
            ArangoCommandParam::COLLECTION_INDEXES => $indexes = [ 'customers' => [] ] ,
            ArangoCommandParam::MODELS             => $models  = [ 'my.model.id' ] ,
            ArangoCommandParam::SEARCH_ALIAS_VIEWS => [ $v = $this->searchAliasView( 'v' ) ] ,
        ]) ;

        $this->assertSame( [ $a ]   , $command->getAnalyzerDefinitions() ) ;
        $this->assertSame( $indexes , $command->collectionIndexes ) ;
        $this->assertSame( $models  , $command->models ) ;
        $this->assertSame( [ $v ]   , $command->getSearchAliasViews() ) ;
    }

    // -------------------------------------------------------------- signals

    public function testGetSubscribedSignalsReturnsTheHandledSignals() :void
    {
        $signals = $this->command()->getSubscribedSignals() ;

        $this->assertCount( 5 , $signals ) ;
        $this->assertContains( SIGINT  , $signals ) ;
        $this->assertContains( SIGTERM , $signals ) ;
    }

    public function testHandleSignalReturnsFalseToContinueExecution() :void
    {
        $this->assertFalse( $this->command()->handleSignal( SIGINT ) ) ;
    }

    // -------------------------------------------------------------- execute

    public function testExecuteFailsOnAnUnknownAction() :void
    {
        $command = $this->command() ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => 'bogus-action' ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'not valid or not defined' , $tester->getDisplay() ) ;
    }

    public function testExecuteDispatchesToTheActionAndReturnsItsStatus() :void
    {
        $command = $this->command() ;
        $command->collectionsStatus = ExitCode::SUCCESS ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => ArangoAction::COLLECTIONS ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
    }

    public function testExecuteTreatsAnExitExceptionAsSuccess() :void
    {
        $command = $this->command() ;
        $command->collectionsThrow = new ExitException( 'stop' ) ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => ArangoAction::COLLECTIONS ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
    }

    public function testExecuteReportsAThrownErrorAsFailure() :void
    {
        $command = $this->command() ;
        $command->collectionsThrow = new RuntimeException( 'boom' ) ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => ArangoAction::COLLECTIONS ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'boom' , $tester->getDisplay() ) ;
    }
}
