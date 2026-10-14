<?php

namespace oihana\arango\commands\actions;

use RuntimeException;
use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\clients\collection\enums\CollectionField;
use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\clients\Database;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\PreserveFilter;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoCollectionsTrait;
use oihana\arango\commands\traits\ArangoPreservesTrait;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

// Set aside the local documents named by [arango.preserves], before a restore
// $ php bin/console.php command:arangodb preserve                 (every declared rule)
// $ php bin/console.php command:arangodb preserve -c articles     (the named collections only)
// $ php bin/console.php command:arangodb preserve --dry-run       (the counts, nothing written)

/**
 * The `preserve` action : sets aside, in a backup collection, the local documents
 * a `[arango.preserves]` rule names — before a restore replaces their collection.
 * `reinject` puts them back afterwards ({@see ArangoReinjectAction}).
 *
 * For each collection, the backup is `<collection>__preserved`, created with the
 * type of the collection (an edge collection for edges, so `_from` and `_to`
 * survive). The copy and its counts run in one stream transaction : the number
 * copied, the number the rule names and the number in the backup must agree, or
 * nothing is set aside and the backup is dropped.
 *
 * Everything is checked before anything is written. A backup that already exists
 * is refused : it holds documents a previous `preserve` set aside and no
 * `reinject` has put back yet — overwriting it would lose them.
 *
 * It talks to ArangoDB over HTTP only : unlike `dump` / `restore`, it needs no
 * `arangodump` / `arangorestore` binary.
 *
 * @package oihana\arango\commands\actions
 * @since 1.10.0
 * @author Marc Alcaraz
 */
trait ArangoPreserveAction
{
    use ArangoClientTrait ,
        ArangoCollectionsTrait ,
        ArangoPreservesTrait ,
        IOTrait ;

    /**
     * Sets aside the local documents named by the `[arango.preserves]` rules.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable When a copy fails — the backup of that collection is dropped first.
     */
    protected function preserve( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;

        $io->section( sprintf( "Set aside the local documents of the '%s' database" , $database ) ) ;

        $filters = $this->preserveFilters( static::normalizeCollections( (array) $input->getOption( ArangoCommandOption::COLLECTION ) ) ) ;

        $db = $this->resolveDatabase( $input ) ;
        if( $db === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        $plan    = [] ;
        $waiting = [] ;

        foreach( $filters as $collection => $filter )
        {
            if( !$db->collection( $collection )->exists() )
            {
                $io->warning( sprintf( '%s : absent from the database — nothing to set aside.' , $collection ) ) ;
                continue ;
            }

            if( $db->collection( $filter->backup )->exists() )
            {
                $waiting[] = $filter->backup ;
                continue ;
            }

            $plan[ $collection ] = $filter ;

            $io->text( sprintf( '%s : %d document(s) match the rule' , $collection , $this->preserveCount( $db , $filter->countQuery() ) ) ) ;
        }

        if( $waiting !== [] )
        {
            $io->error( sprintf( 'A previous preserve still waits for its reinject — %s already exist(s). Run reinject first.' , implode( ', ' , $waiting ) ) ) ;
            return ExitCode::FAILURE ;
        }

        if( $plan === [] )
        {
            $io->error( 'Nothing to set aside : none of the collections exists in the database.' ) ;
            return ExitCode::FAILURE ;
        }

        if( $input->getOption( ArangoCommandOption::DRY_RUN ) )
        {
            $io->success( 'Dry run — nothing was set aside.' ) ;
            return ExitCode::SUCCESS ;
        }

        $io->newLine() ;

        foreach( $plan as $collection => $filter )
        {
            $io->text( sprintf( '%s : %d document(s) set aside in %s' , $collection , $this->setAside( $db , $filter ) , $filter->backup ) ) ;
        }

        $io->newLine() ;
        $io->success( 'The local documents are set aside — restore, then run reinject.' ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Creates the backup of a collection and copies into it the documents the rule names.
     *
     * @param Database       $db
     * @param PreserveFilter $filter
     * @return int The number of documents set aside.
     * @throws Throwable When the copy fails or its counts disagree — the backup is dropped first.
     */
    private function setAside( Database $db , PreserveFilter $filter ) :int
    {
        $type = $db->collection( $filter->collection )->properties()[ CollectionField::TYPE ] ?? CollectionType::DOCUMENT ;

        $db->collection( $filter->backup )->create( [ CollectionField::TYPE => $type ] ) ;

        try
        {
            return (int) $db->withTransaction
            (
                callback : function() use ( $db , $filter ) :int
                {
                    $copied   = $this->preserveCount( $db , $filter->copyQuery()        ) ;
                    $matching = $this->preserveCount( $db , $filter->countQuery()       ) ;
                    $kept     = $this->preserveCount( $db , $filter->backupCountQuery() ) ;

                    if( $copied !== $matching || $copied !== $kept )
                    {
                        throw new RuntimeException
                        (
                            sprintf
                            (
                                '%s : %d document(s) copied, %d named by the rule, %d in %s — nothing was set aside.' ,
                                $filter->collection , $copied , $matching , $kept , $filter->backup
                            )
                        ) ;
                    }

                    return $copied ;
                } ,
                write : [ $filter->backup ] ,
                read  : [ $filter->collection ] ,
            ) ;
        }
        catch( Throwable $exception )
        {
            $db->collection( $filter->backup )->drop() ;
            throw $exception ;
        }
    }
}
