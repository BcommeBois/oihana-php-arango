<?php

namespace oihana\arango\commands\actions\documents;

use oihana\commands\enums\ExitCode;
use oihana\enums\Boolean;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use UnexpectedValueException;
use function oihana\commands\helpers\info;
use function oihana\commands\helpers\notice;
use function oihana\commands\helpers\success;

trait DocumentsCommandTruncate
{
    use DocumentsCommandTrait ;

    /**
     * Truncate the collection and remove all documents.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed|null $option
     * @return int
     */
    protected function truncate( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        if( is_array( $option ) )
        {
            $option = $option[0] ?? null ;
        }

        $io = $this->getIO( $input , $output ) ;

        if( $input->isInteractive() )
        {
            $helper = $this->getQuestionHelper() ;

            $question = new ConfirmationQuestion( notice( 'Continue to truncate the collection ? (y/n) ' ) , false ) ;


            if ( !$helper->ask( $input, $output, $question ) )
            {
                $io->newLine() ;
                $io->text( info( "Truncation aborted. Your data remains intact." ) ) ;
                return ExitCode::SUCCESS ;
            }

            $option = Boolean::TRUE ;
        }

        $io = $this->getIO( $input , $output ) ;

        if( $option === Boolean::TRUE )
        {
            $this->assertDocuments() ;

            if( $this->documents->truncate() )
            {
                $io->newLine() ;
                $io->text( success( "Truncate operation succeed" ) ) ;
                return ExitCode::SUCCESS ;
            }

            throw new UnexpectedValueException( 'Truncate operation failed.' ) ;
        }
        else
        {
            $io->text( info( "Truncate nothing. Your data remains intact." ) ) ;
        }

        return ExitCode::SUCCESS ;
    }
}