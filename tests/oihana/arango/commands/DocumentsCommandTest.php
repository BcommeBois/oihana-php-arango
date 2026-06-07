<?php

namespace tests\oihana\arango\commands;

use RuntimeException;
use Throwable;

use DI\Container;

use oihana\arango\commands\DocumentsCommand;
use oihana\arango\commands\enums\DocumentsCommandAction;
use oihana\arango\commands\enums\DocumentsCommandOption;

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
 * Test double for {@see DocumentsCommand} exposing a controllable action.
 *
 * The `count` action is overridden so execute() can be driven through its
 * success / ExitException / Throwable branches without a Documents model.
 */
class DocumentsCommandDouble extends DocumentsCommand
{
    public int        $countStatus  = ExitCode::SUCCESS ;
    public ?Throwable $countThrow   = null ;
    public ?int       $beforeStatus = null ;          // null => delegate to the real chain
    public ?int       $afterStatus  = null ;

    public function after( InputInterface $input , OutputInterface $output ) :int
    {
        return $this->afterStatus ?? parent::after( $input , $output ) ;
    }

    public function before( InputInterface $input , OutputInterface $output ) :int
    {
        return $this->beforeStatus ?? parent::before( $input , $output ) ;
    }

    protected function count( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        if ( $this->countThrow !== null )
        {
            throw $this->countThrow ;
        }
        return $this->countStatus ;
    }
}

/**
 * Unit coverage for {@see DocumentsCommand}.
 */
#[CoversClass(DocumentsCommand::class)]
class DocumentsCommandTest extends TestCase
{
    private function command() :DocumentsCommandDouble
    {
        $command = new DocumentsCommandDouble( 'places' , new Container() ) ;
        $command->setApplication( new Application() ) ;
        return $command ;
    }

    // -------------------------------------------------------------- construct / configure

    public function testConstructsWithTheGivenName() :void
    {
        $command = new DocumentsCommand( 'places' , new Container() ) ;
        $this->assertSame( 'places' , $command->getName() ) ;
    }

    public function testConfigureRegistersTheArgumentsAndOptions() :void
    {
        $definition = $this->command()->getDefinition() ;

        $this->assertTrue( $definition->hasArgument( CommandArg::ACTION ) ) ;
        $this->assertTrue( $definition->hasArgument( CommandArg::INIT ) ) ;
        $this->assertTrue( $definition->hasOption( DocumentsCommandOption::OPTIMIZED ) ) ;
    }

    // -------------------------------------------------------------- execute

    public function testExecuteDispatchesToTheActionAndReturnsItsStatus() :void
    {
        $command = $this->command() ;
        $command->countStatus = ExitCode::SUCCESS ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => DocumentsCommandAction::COUNT ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
    }

    public function testExecuteTreatsAnExitExceptionAsSuccess() :void
    {
        $command = $this->command() ;
        $command->countThrow = new ExitException( 'stop' ) ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => DocumentsCommandAction::COUNT ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
    }

    public function testExecuteReportsAThrownErrorAsFailure() :void
    {
        $command = $this->command() ;
        $command->countThrow = new RuntimeException( 'boom' ) ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => DocumentsCommandAction::COUNT ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'boom' , $tester->getDisplay() ) ;
    }

    public function testExecuteFallsBackToListForAnUnknownAction() :void
    {
        // An unknown action is not a method, so execute() falls back to list(),
        // which fails (no Documents model) and is reported as a failure.
        $command = $this->command() ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => 'zzz-unknown' ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
    }

    public function testExecuteShortCircuitsWhenABeforeHookFails() :void
    {
        $command = $this->command() ;
        $command->beforeStatus = ExitCode::FAILURE ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => DocumentsCommandAction::COUNT ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
    }

    public function testExecuteReturnsTheAfterHookStatusWhenItFails() :void
    {
        $command = $this->command() ;
        $command->afterStatus = ExitCode::FAILURE ;
        $tester  = new CommandTester( $command ) ;

        $code = $tester->execute( [ CommandArg::ACTION => DocumentsCommandAction::COUNT ] ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
    }
}
