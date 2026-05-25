<?php

namespace oihana\arango\commands\actions\documents;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function oihana\commands\helpers\warning;

/**
 * Provides the implementation for an 'exist' command action.
 *
 * This trait should be used in a Symfony Console command to check if one or
 * more documents exist based on a given set of identifiers.
 *
 * ## Example
 * ```shell
 * bin/console command:places exist "65910397"
 *
 * Command:places
 * ==============
 *
 * Check if the document(s) exist
 * ------------------------------
 *
 * The document 65910397 exist
 *
 * ✅  Done in 4 ms
 * ----------------
 *
 * Thank you and see you soon!
 * ```
 */
trait DocumentsCommandExist
{
    use DocumentsCommandTrait ;

    /**
     * Checks if all provided documents exist in the collection.
     *
     * This method takes a single identifier or an array of identifiers and verifies their existence.
     * The command's exit code does not reflect the outcome of the existence check;
     * it only indicates that the operation itself completed successfully.
     *
     * @param InputInterface $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param mixed|null $option A single identifier or an array of identifiers to check.
     *
     * @return int Always returns `ExitCode::SUCCESS`.
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     */
    protected function exist
    (
        InputInterface  $input  ,
        OutputInterface $output ,
        mixed           $option  = null
    ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Check if the document(s) exist' ) ;

        $value = $option ?? [] ;

        $count = count( $value ) ;
        $exist = $this->documents->exist( [ DocumentsCommandParam::VALUE => $value ] ) ; // ANY

        if( $exist )
        {
            if( $count == 1 )
            {
                $io->text( sprintf( "The document <info>%s</info> exist" , $value[0] ) ) ;
            }
            else
            {
                $io->text( sprintf( "All the documents exist: %s" , json_encode( $value ) ) ) ;
            }
        }
        else
        {
            if( $count == 1 )
            {
                $io->text( sprintf( warning('The document %s doesn\'t exist') , $value[0] ) ) ;
            }
            else
            {
                $io->text( warning( "At least one of the documents does not exist." ) ) ;
            }
        }

        return ExitCode::SUCCESS ;
    }
}