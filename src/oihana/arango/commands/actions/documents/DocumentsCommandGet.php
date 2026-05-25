<?php

namespace oihana\arango\commands\actions\documents;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error404;
use oihana\models\enums\ModelParam;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the implementation for a 'get' command action.
 *
 * This trait should be used in a Symfony Console command that needs to
 * retrieve and display a single document by its identifier.
 *
 * ## Example
 * ```shell
 * bin/console command:places get "65910397" -v
 *
 * Command:places
 * ==============
 *
 * Get a document of the collection
 * --------------------------------
 *
 * Document Found: 1
 * ---------- ----------- ---------------------- ----------------------
 * _key       Name        Created                Modified
 * ---------- ----------- ---------------------- ----------------------
 * 65910397   Marseille   2025-08-25T10:06:26Z   2025-08-25T10:06:26Z
 * ---------- ----------- ---------------------- ----------------------
 *
 * ✅  Done in 5 ms
 * ----------------
 *
 * Thank you and see you soon!
 * ```
 */
trait DocumentsCommandGet
{
    use DocumentsCommandTrait ;

    /**
     * Executes the action to fetch and display a single document by its identifier.
     *
     * @param InputInterface $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param array $option An array where the first element is the identifier of the document to retrieve.
     *
     * @return int The exit code for the command, typically `ExitCode::SUCCESS`.
     *
     * @throws Error404 If no document is found for the provided identifier.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     */
    protected function get( InputInterface $input, OutputInterface $output , array $option = [] ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Get a document of the collection' ) ;

        [ $value ] = $option ;

        $document = $this->documents->get( [ ModelParam::VALUE => $value ] ) ;

        if( isset( $document ) )
        {
            $this->outputDocuments( [ $document ] , $input , $output ) ;
            return ExitCode::SUCCESS ;
        }
        
        throw new Error404( 'No document found' ) ;
    }
}