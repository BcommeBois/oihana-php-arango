<?php

namespace oihana\arango\commands\actions\documents;

use ReflectionException;
use UnexpectedValueException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\commands\enums\DocumentsCommandParam;
use oihana\arango\models\Documents;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\commands\traits\IOTrait;
use oihana\commands\traits\JsonStyleTrait;
use oihana\enums\Char;
use oihana\exceptions\BindException;

use org\schema\constants\Schema;

use function oihana\commands\helpers\info;
use function oihana\commands\helpers\success;

/**
 * Provides common properties and methods for console commands that interact with documents.
 *
 * This trait streamlines operations like model initialization, data validation,
 * and console output for document-related commands.
 */
trait DocumentsCommandTrait
{
    use JsonStyleTrait ,
        IOTrait ;

    /**
     * The DocumentsModel instance for data operations.
     * @var Documents|null
     */
    public ?Documents $documents ;

    /**
     * A list of properties to exclude during data manipulation operations like insert, update, replace, or upsert.
     * @var array|mixed|null
     */
    public ?array $excludes = null ;

    /**
     * The document's fields to display in tabular output.
     * @var array
     */
    public array $fields = [ Schema::_KEY , Schema::NAME , Schema::CREATED , Schema::MODIFIED ] ;

    /**
     * A list of properties to remove during data manipulation operations like insert, update, replace, or upsert.
     * @var array|mixed|null
     */
    public ?array $removeKeys = null ;

    /**
     * Asserts that the 'documents' property has been initialized.
     *
     * @return void
     * @throws UnexpectedValueException If the 'documents' property is not set.
     */
    protected function assertDocuments():void
    {
        if( !isset( $this->documents ) )
        {
            throw new UnexpectedValueException( 'The documents property is not set.' ) ;
        }
    }

    /**
     * Fetch all documents from the OpenEdge database.
     *
     * @param OutputInterface $output  The console output instance.
     * @param ?Documents      $ref     The optional Documents reference to use (default, use $this->documents)
     * @param string          $name    The optional name of the documents (default "documents").
     * @param array           $init    An associative array of optional settings to define the query - {@see Documents::list()}.
     *
     * @return array
     *
     * @throws BindException
     * @throws ContainerExceptionInterface
     * @throws ArangoException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function fetchDocuments
    (
        OutputInterface $output ,
        ?Documents      $ref    = null ,
        string          $name   = 'documents' ,
        array           $init   = [] ,
    )
    :array
    {
        $output->writeln( sprintf('🔍 Fetching %s from ArangoDB...' , $name ) );

        $verbose = $output->isVerbose();
        $indicator = null;

        if ( $verbose )
        {
            $indicator = new ProgressIndicator( $output ) ;
            $indicator->start( sprintf( 'Fetching %s, please wait...' , $name ) ) ;
        }

        $documents = ( $ref ?? $this->documents )?->list( $init ) ?? [] ;
        $count     = count( $documents ) ;

        if ( $verbose && $indicator )
        {
            $indicator->finish( sprintf('Fetching completed - Found <info>%d</info> %s(s) in ArangoDB' . PHP_EOL , $count , $name ) , '🎉' ) ;
        }
        else
        {
            $output->writeln( sprintf('🎉 Fetching completed - Found %d %s(s) in ArangoDB' , $count , $name ) );
        }

        $output->writeln( '' ) ;

        return $documents ;
    }

    /**
     * Initializes the 'documents' property.
     *
     * This method attempts to find a service name in the '$init' array and
     * uses the provided PSR container to retrieve the DocumentsModel instance.
     *
     * @param array                   $init      An array of initialization parameters, expected to contain the documents service name.
     * @param ContainerInterface|null $container An optional PSR-11 container for service resolution.
     *
     * @return static The instance of the class using this trait, for method chaining.
     *
     * @throws ContainerExceptionInterface If an error occurs while retrieving the entry from the container.
     * @throws NotFoundExceptionInterface  If no entry was found in the container for the given service name.
     */
    protected function initializeDocuments( array $init = [] , ?ContainerInterface $container = null ) :static
    {
        $documents = $init[ DocumentsCommandParam::DOCUMENTS ] ?? null ;
        if( is_string( $documents ) && $documents != Char::EMPTY && $container?->has( $documents ) )
        {
            $documents = $container->get( $documents ) ;
        }
        $this->documents = $documents instanceof Documents ? $documents : null ;
        return $this ;
    }

    /**
     * Renders a list of documents to the console output.
     *
     * The output format is determined by the verbosity level:
     * - Default: Displays the total count of documents found.
     * - Verbose (`-v`): Renders a table with the fields defined in the `$this->fields` property.
     * - Very Verbose (`-vv`): Outputs the full documents as a prettyprint JSON array.
     *
     * @param array           $documents The array of document objects to display.
     * @param InputInterface  $input     The console input instance.
     * @param OutputInterface $output    The console output instance.
     * @param ?array          $fields    The optional fields to display (by default, use the $this->fields property).
     * @return void
     */
    protected function outputDocuments
    (
        array           $documents ,
        InputInterface  $input     ,
        OutputInterface $output    ,
        ?array          $fields    = null ,
    )
    :void
    {
        $io = $this->getIO( $input , $output ) ;

        $count = count( $documents ) ;

        if( $count == 0 )
        {
            $io->text( info( "No documents found" ) ) ;
            return ;
        }

        $io->text( sprintf
        (
            "Documents Found: %s" ,
            success( $count )
        ) ) ;

        $verbosity = $io->getVerbosity() ;

        $fields ??= $this->fields ;

        if( $verbosity == OutputInterface::VERBOSITY_VERBOSE && count( $fields ) > 0 )
        {
            $headers = [] ;
            foreach ( $fields as $field )
            {
                $headers[] = ucfirst( $field ) ;
            }

            $table = [] ;
            foreach ( $documents as $thing )
            {
                $columns = [] ;
                foreach ( $fields as $field )
                {
                    $columns[] = $thing->{ $field } ?? null ;
                }
                $table[] = $columns ;
            }

            $io->newLine();
            $io->table( $headers , $table ) ;
        }
        else if( $verbosity == OutputInterface::VERBOSITY_VERY_VERBOSE )
        {
            $io->newLine();
            $this->getJson( $output )
                ->writeJson( $documents , true ,OutputInterface::VERBOSITY_VERY_VERBOSE ) ;
        }
    }
}