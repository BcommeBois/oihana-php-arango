<?php

namespace tests\oihana\arango\commands\actions\documents;

use oihana\arango\commands\actions\documents\DocumentsCommandTruncate;
use oihana\arango\models\Documents;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\HelperTrait;

use oihana\enums\Boolean;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Host composing {@see DocumentsCommandTruncate}.
 *
 * The QuestionHelper is injected directly (the trait reads the public
 * `$questionHelper` slot), so the interactive confirmation branch can be
 * driven from a canned input stream without a full Symfony Application.
 */
class DocumentsCommandTruncateHost
{
    use DocumentsCommandTruncate ;
    use HelperTrait ;

    public function __construct( ?Documents $documents )
    {
        $this->documents = $documents ;
    }

    public function run( InputInterface $input , OutputInterface $output , mixed $option = null ) :int
    {
        return $this->truncate( $input , $output , $option ) ;
    }
}

/**
 * Unit coverage for {@see DocumentsCommandTruncate} (array-option + interactive branches).
 */
#[CoversTrait(DocumentsCommandTruncate::class)]
class DocumentsCommandTruncateTest extends TestCase
{
    private function host( bool $truncateResult = true ) :DocumentsCommandTruncateHost
    {
        $documents = new MockDocuments( 'places' ) ;
        $documents->truncateResult = $truncateResult ;

        $host = new DocumentsCommandTruncateHost( $documents ) ;
        $host->questionHelper = new QuestionHelper() ;
        return $host ;
    }

    /** A non-interactive input (no confirmation prompt). */
    private function input() :ArrayInput
    {
        $input = new ArrayInput( [] ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /** An interactive input whose question stream answers with $answer. */
    private function interactiveInput( string $answer ) :ArrayInput
    {
        $stream = fopen( 'php://memory' , 'r+' ) ;
        fwrite( $stream , $answer . "\n" ) ;
        rewind( $stream ) ;

        $input = new ArrayInput( [] ) ;
        $input->setStream( $stream ) ;
        $input->setInteractive( true ) ;
        return $input ;
    }

    public function testArrayOptionUnwrapsItsFirstElement() :void
    {
        $output = new BufferedOutput() ;

        // option passed as an array → first element is used → truncation runs.
        $code = $this->host()->run( $this->input() , $output , [ Boolean::TRUE ] ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Truncate operation succeed' , $output->fetch() ) ;
    }

    public function testInteractiveConfirmationTruncates() :void
    {
        $output = new BufferedOutput() ;

        $code = $this->host()->run( $this->interactiveInput( 'y' ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Truncate operation succeed' , $output->fetch() ) ;
    }

    public function testInteractiveDeclineAbortsAndKeepsData() :void
    {
        $output = new BufferedOutput() ;

        $code = $this->host()->run( $this->interactiveInput( 'n' ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'remains intact' , $output->fetch() ) ;
    }
}
