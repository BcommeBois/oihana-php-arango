<?php

namespace tests\oihana\arango\commands;

use RuntimeException;
use Throwable;

use DI\Container;

use oihana\arango\commands\ArangoCommand;
use oihana\arango\commands\enums\ArangoAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\db\enums\ArangoConfig;

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
