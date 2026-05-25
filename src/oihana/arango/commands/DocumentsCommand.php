<?php

namespace oihana\arango\commands;

use Throwable;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use oihana\arango\commands\actions\documents\DocumentsCommandActions;
use oihana\arango\commands\enums\DocumentsCommandAction;
use oihana\arango\commands\enums\DocumentsCommandOption;
use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;
use oihana\commands\Kernel;
use oihana\commands\options\CommandOption;
use oihana\commands\traits\ChainedCommandsTrait;
use oihana\enums\Char;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function oihana\commands\helpers\clearConsole;

/**
 * Manages Documents within an Documents collection via console commands.
 *
 * This command provides a command-line interface (CLI) for common CRUD (Create, Read, Update, Delete)
 * operations on a specific ArangoDB document collection, likely representing "Things".
 * It leverages the Symfony Console component for command-line interaction and a custom
 * `Documents` model for database operations.
 *
 * It acts as a wrapper for various document management functionalities,
 * allowing users to count, get, insert, delete, check existence, list, and truncate documents
 * directly from the command line.
 *
 * @package oihana\commands\documents
 * @extends Kernel
 *
 * @example
 * ### Set the IoC definition of the places command
 * ```shell
 * return
 * [
 *  Commands::PLACES => fn( Container $container ) => new DocumentsCommand
 *  (
 *      Commands::PLACES ,
 *      $container ,
 *      [
 *          DocumentsCommandParam::DOCUMENTS   => Models::PLACES ,
 *          DocumentsCommandParam::EXCLUDES    => [ Prop::AT_CONTEXT  , Prop::AT_TYPE , Prop::AT_ID ] ,
 *          DocumentsCommandParam::FIELDS      => [ Prop::_KEY , Prop::NAME , Prop::CREATED , Prop::MODIFIED ] ,
 *          DocumentsCommandParam::DESCRIPTION => 'The places collection helper.' ,
 *          DocumentsCommandParam::HELP        => 'This command allows search and manage the places collection.' ,
 *      ]
 *  )
 * ];
 *
 * #### Executes the command with it's options
 *
 * List all documents
 * ```shell
 * $ bun places -v
 * $ bun places list
 * $ bun places list -v
 * ```
 *
 * Calculates the number of documents in the collection
 * ```shell
 * $ bun places count
 * $ bun places count --optimized
 * $ bun places count -o
 * ```
 *
 * Get a specific document
 * ```shell
 * $ bun places get 59943726 -v
 * $ bun places get 59943726 --verbose
 * ```
 *
 * Get the last document in the collection (by default with the property 'modified')
 * ```shell
 * $ bun places last
 * $ bun places last created
 * ```
 *
 *
 * Check if a document or a set of documents exists.
 * ```shell
 * $ bun places exist 59918826
 * $ bun places exist 59918826 59943726 555 60280105
 * ```
 *
 * Insert a new document in the collection
 * ```shell
 * $ bun places insert '{"id":"55525","name":"Marseille","path":"places","address":{"postalCode":"13013"}}' -v
 * ```
 *
 * Update an existing document in the collection
 * ```shell
 * $ bun places update 55525 '{"name":"Marseille !"}'
 * ```
 *
 * Replace an existing document in the collection
 * ```shell
 * $ bun places replace 55525 '{"id":"55525",name":"Marseille !","path":"places","address":{"postalCode":"13012"}}' -v
 * ```
 *
 * Delete a document in the collection
 * ```shell
 * $ bun places delete 60280116
 * ```
 *
 * Delete a set of documents in the collection
 * ```shell
 * $ bun places delete 60280116 60280105
 * ```
 *
 * [warning] Remove all documents in the collection
 * ```shell
 * $ bun places truncate --accept true
 * ```
 */
class DocumentsCommand extends Kernel
{
    /**
     * Creates a new DocumentsCommand instance.
     * @param string|null $name
     * @param Container|null $container
     * @param array $init
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function __construct( ?string $name , ?Container $container = null , array $init = [] )
    {
        parent::__construct( $name , $container , $init ) ;
        $this->excludes   = $init[ DocumentsCommandParam::EXCLUDES    ] ?? $this->excludes   ;
        $this->fields     = $init[ DocumentsCommandParam::FIELDS      ] ?? $this->fields     ;
        $this->removeKeys = $init[ DocumentsCommandParam::REMOVE_KEYS ] ?? $this->removeKeys ;
        $this->initializeDocuments( $init , $container )
             ->initializeChain( $init ) ;
    }

    use DocumentsCommandActions,
        ChainedCommandsTrait ;

    /**
     * Configures the current command.
     * @return void
     */
    protected function configure(): void
    {
        // ------------------ Arguments

        CommandArg::configureAction
        (
            command     : $this ,
            description : 'Action to perform a document command.' ,
            default     : DocumentsCommandAction::LIST ,
            mode        : InputArgument::OPTIONAL ,
        ) ;

        $this->addArgument
        (
            name        : CommandArg::INIT ,
            mode        : InputArgument::IS_ARRAY ,
            description : 'The init parameters or values to passed-in the documents method'
        ) ;

        // ------------------ Options

        CommandOption::configureClear( $this ) ;

        // --env
        CommandOption::configureEnv( $this ) ;

        // --force | -f
        CommandOption::configureForce( $this , shortcut: CommandOption::FORCE_SHORTCUT ) ;

        $this->addOption // --optimized | -o
        (
            name        : DocumentsCommandOption::OPTIMIZED ,
            shortcut    : DocumentsCommandOption::OPTIMIZED_SHORTCUT ,
            mode        : InputOption::VALUE_NONE ,
            description : 'Indicates if the method is optimized, usage in the count method.'
        ) ;
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int 0 if everything went fine, or an exit code
     *
     * @throws ExceptionInterface
     *
     * @see setCode()
     */
    protected function execute( InputInterface $input, OutputInterface $output ): int
    {
        if( $input->hasOption( CommandOption::CLEAR ) )
        {
            clearConsole( $input->getOption( CommandOption::CLEAR ) ?? $this->commandOptions?->clearable ?? false ) ;
        }

        $this->initializeConsoleLogger( $output ) ;

        // -------- BEFORE HOOKS

        $status = $this->before( $input , $output ) ;
        if ( $status !== ExitCode::SUCCESS )
        {
            return $status;
        }

        // -------- START COMMAND

        [ $io , $timestamp ] = $this->startCommand( $input , $output ) ;

        try
        {
            $this->action = $input->getArgument(CommandArg::ACTION ) ?? Char::EMPTY ;

            $this->assertActions( $this->action );

            if( is_string( $this->action ) && method_exists( $this , $this->action ) )
            {
                $status = $this->{ $this->action }( $input , $output , $input->getArgument(CommandArg::INIT ) ) ;
            }
            else
            {
                $status = $this->list( $input , $output ) ;
            }
        }
        catch( ExitException )
        {
            //
        }
        catch ( Throwable $exception )
        {
            $io->error( sprintf( 'The command failed :  %s' , $exception->getMessage() ) ) ;
            $status = ExitCode::FAILURE ;
        }

        $io->newLine() ;

        $exitCode = $this->endCommand( $input , $output , $status , $timestamp ) ;

        // --- AFTER HOOKS ---
        
        $status = $this->after( $input , $output ) ;
        if ( $status !== ExitCode::SUCCESS )
        {
            return $status ;
        }

        return $exitCode ;
    }
}