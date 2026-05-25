<?php

namespace oihana\arango\commands\actions\documents;

use DI\DependencyException;
use DI\NotFoundException;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\DocumentsCommandOption;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;
use oihana\models\enums\ModelParam;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the implementation for a 'count' command action.
 *
 * This trait should be used in a Symfony Console command to count the total
 * number of documents in the associated model.
 *
 * ## Example
 * ```shell
 * bin/console command:places count
 *
 * Command:places
 * ==============
 *
 * Count the documents in the collection
 * -------------------------------------
 *
 * The collection contains 4 document(s)
 *
 * ✅  Done in 3 ms
 * ----------------
 *
 * Thank you and see you soon!
 * ``
 */
trait DocumentsCommandCount
{
    use DocumentsCommandTrait ;

    /**
     * Count the number of documents in the model.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed|null $option
     *
     * @return int
     *
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ArangoException
     * @throws BindException
     * @throws ReflectionException
     */
    protected function count( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Counting documents in the collection' ) ;

        $optimized = $input->getOption( DocumentsCommandOption::OPTIMIZED )  ;

        $count = $this->documents->count( [ ModelParam::OPTIMIZED => $optimized ] )  ;

        $io->text( sprintf
        (
            "The collection contains <fg=%s>%d</> document%s." ,
            $count == 0 ? 'red' : 'green' ,
            $count ,
            $count > 1 ? 's' : ''
        )) ;

        return ExitCode::SUCCESS ;
    }
}