<?php

namespace oihana\arango\commands\actions\documents;

use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\models\enums\ModelParam;

use function oihana\commands\helpers\error;
use function oihana\commands\helpers\success;
use function oihana\commands\helpers\warning;

/**
 * Provides the implementation for a 'delete' command action.
 *
 * This trait should be used in a Symfony Console command to delete one or more
 * documents from the associated model based on their identifiers.
 */
trait DocumentsCommandDelete
{
    use DocumentsCommandTrait ;

    /**
     * Executes the action to delete one or more documents from the collection.
     *
     * Identifiers for the documents to be deleted should be provided as a single
     * comma-separated string. The command's exit code does not reflect whether
     * documents were actually deleted, only that the operation ran successfully.
     *
     * @param InputInterface $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param mixed|null $option A comma-separated string of document identifiers.
     *
     * @return int Always returns `ExitCode::SUCCESS`.
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     * @throws UnsupportedOperationException
     */
    protected function delete( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Delete the document(s) in the collection' ) ;

        $count = count( $option ) ;

        if( $count == 0 )
        {
            $io->text( warning( "No deletions occurred: no documents were specified." ) ) ;
            return ExitCode::SUCCESS ;
        }

        $result = $this->documents->delete([ ModelParam::VALUE => $option ]) ;

        if( isset( $result ) )
        {
            if( $count == 1 )
            {
                $io->text( success( sprintf( "The document `%s` is removed." , $option[0] ) ) ) ;
            }
            else
            {
                $io->text( success( "The documents have been removed." ) ) ;
            }
        }
        else
        {
            $io->text( error( "No deletions occurred." ) ) ;
        }

        return ExitCode::SUCCESS ;
    }
}