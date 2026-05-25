<?php

namespace oihana\arango\commands\actions\documents;

use ReflectionException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\enums\Arango;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use oihana\exceptions\http\Error404;

use org\schema\constants\Schema;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the implementation for a 'last' command action.
 *
 * This trait should be used in a Symfony Console command that needs to
 * retrieve and display a last document modified in the model.
 *
 * ## Example 1 : use the 'modified' property by default.
 * ```shell
 * bin/console command:places last -v
 *
 * Command:places
 * ==============
 *
 * Fetching the last `modified` document.
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
 *
 *  ## Example 2 : use the 'created' property.
 *  ```shell
 *  bin/console command:places last created -v
 *
 *  Command:places
 *  ==============
 *
 *  Fetching the last `created` document.
 *  --------------------------------
 *
 *  Document Found: 1
 *  ---------- ----------- ---------------------- ----------------------
 *  _key       Name        Created                Modified
 *  ---------- ----------- ---------------------- ----------------------
 *  65910397   Marseille   2025-08-25T10:06:26Z   2025-08-25T10:06:26Z
 *  ---------- ----------- ---------------------- ----------------------
 *
 *  ✅  Done in 5 ms
 *  ----------------
 *
 *  Thank you and see you soon!
 *  ```
 */
trait DocumentsCommandLast
{
    use DocumentsCommandTrait ;

    /**
     * Executes the action to fetch and display the last document in the model.
     *
     * @param InputInterface  $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param array           $option An array of optional parameters to send in the command.
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
    protected function last( InputInterface $input, OutputInterface $output , array $option = [] ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $property =  $option[0] ?? Schema::MODIFIED ; // Default -> 'modified'

        $io->section( sprintf( 'Fetching the last `%s` document' , $property ) ) ;

        $document = $this->documents->last([ Arango::PROPERTY => $property ]) ;

        if( isset( $document ) )
        {
            $verbosity = $io->getVerbosity() ;
            if( $verbosity < OutputInterface::VERBOSITY_VERBOSE )
            {
                $io->text( $document->{ $property} ) ;
            }
            else
            {
                $this->outputDocuments( [ $document ] , $input , $output ) ;
            }
            return ExitCode::SUCCESS ;
        }
        
        throw new Error404( 'No document found' ) ;
    }
}