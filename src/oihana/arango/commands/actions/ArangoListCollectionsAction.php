<?php

namespace oihana\arango\commands\actions;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoCollectionsTrait;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

// List the collections of the database
// $ php bin/console.php command:arangodb collections            (user collections only)
// $ php bin/console.php command:arangodb collections --system   (system collections only)
// $ php bin/console.php command:arangodb collections --all      (all collections)

/**
 * Lists the collections of the ArangoDB database through the HTTP client.
 *
 * Unlike the dump/restore actions (which shell out to the arangodump /
 * arangorestore binaries), this action queries the live database via
 * {@see ArangoClientTrait::buildDatabase()} and prints the collection
 * names. Three scopes are available:
 *
 * - default       : user collections only (non-system).
 * - `--system`    : system collections only (names starting with `_`).
 * - `--all`       : every collection.
 *
 * @package oihana\arango\commands\actions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait ArangoListCollectionsAction
{
    use ArangoClientTrait ,
        ArangoCollectionsTrait ,
        IOTrait ;

    /**
     * Lists the collections of the database.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function collections( InputInterface $input, OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;
        $password = $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ;
        $username = $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ;

        $io->section( sprintf( "List the collections of the '%s' database" , $database ) ) ;

        $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
        if( $db === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        $onlySystem    = (bool) $input->getOption( ArangoCommandOption::SYSTEM ) ;
        $includeSystem = $onlySystem || (bool) $input->getOption( ArangoCommandOption::ALL ) ;

        try
        {
            $names = array_map( fn( $collection ) => $collection->getName() , $db->collections( $includeSystem ) ) ;
        }
        catch( ArangoException $exception )
        {
            $io->error( 'Unable to list the collections — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }

        if( $onlySystem )
        {
            $names = array_values( array_filter( $names , fn( $name ) => static::isSystemCollection( $name ) ) ) ;
        }

        sort( $names ) ;

        if( $names === [] )
        {
            $io->text( 'There are no collections in the database.' ) ;
        }
        else
        {
            foreach( $names as $name )
            {
                $io->text( '→ ' . $name ) ;
            }
        }

        $io->newLine() ;

        return ExitCode::SUCCESS ;
    }
}
