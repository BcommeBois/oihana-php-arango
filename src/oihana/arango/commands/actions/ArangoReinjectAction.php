<?php

namespace oihana\arango\commands\actions;

use RuntimeException;
use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

use oihana\arango\clients\Database;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\PreserveFilter;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoCollectionsTrait;
use oihana\arango\commands\traits\ArangoOptionsTrait;
use oihana\arango\commands\traits\ArangoPreservesTrait;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

// Put back the documents set aside by `preserve`, once the restore is done
// $ php bin/console.php command:arangodb reinject                 (every declared rule, with confirmation)
// $ php bin/console.php command:arangodb reinject -c articles     (the named collections only)
// $ php bin/console.php command:arangodb reinject --yes           (no confirmation)
// $ php bin/console.php command:arangodb reinject --dry-run       (the counts, nothing written)

/**
 * The `reinject` action : puts back the documents `preserve` set aside
 * ({@see ArangoPreserveAction}), once a restore has replaced their collection.
 *
 * For each collection, in one stream transaction :
 *
 *  1. remove from the collection the documents the rule names — those the
 *     restore brought from elsewhere ;
 *  2. copy the whole backup back ; a duplicate `_key` is an error, never an
 *     overwrite ;
 *  3. count : the documents the rule now names must be exactly those of the
 *     backup — a rule changed since `preserve` shows here.
 *
 * Any failure aborts the transaction : the collection is left as it was and the
 * backup is kept. Only a committed transaction drops `<collection>__preserved`.
 *
 * 🚨 A collection without a backup is **left untouched** : removing what the rule
 * names with nothing to put back would erase the local documents.
 *
 * It can be run again safely : step 1 removes everything the rule names, a
 * half put back included, before step 2 puts the whole backup back.
 *
 * @package oihana\arango\commands\actions
 * @since 1.10.0
 * @author Marc Alcaraz
 */
trait ArangoReinjectAction
{
    use ArangoClientTrait ,
        ArangoCollectionsTrait ,
        ArangoOptionsTrait ,
        ArangoPreservesTrait ,
        IOTrait ;

    /**
     * Puts back the documents set aside by `preserve`.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     * @throws Throwable When a put back fails — its transaction is aborted and the backup kept.
     */
    protected function reinject( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;

        $io->section( sprintf( "Put back the documents set aside in the '%s' database" , $database ) ) ;

        $filters = $this->preserveFilters( static::normalizeCollections( (array) $input->getOption( ArangoCommandOption::COLLECTION ) ) ) ;

        $db = $this->resolveDatabase( $input ) ;
        if( $db === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        $plan    = [] ;
        $absent  = [] ;
        $summary = [] ;

        foreach( $filters as $collection => $filter )
        {
            if( !$db->collection( $filter->backup )->exists() )
            {
                $io->warning( sprintf( '%s : nothing was set aside (%s does not exist) — left untouched.' , $collection , $filter->backup ) ) ;
                continue ;
            }

            if( !$db->collection( $collection )->exists() )
            {
                $absent[] = $collection ;
                continue ;
            }

            $plan[ $collection ] = $filter ;

            $summary[] = sprintf
            (
                '%s : %d to remove · %d to put back' ,
                $collection ,
                $this->preserveCount( $db , $filter->countQuery()       ) ,
                $this->preserveCount( $db , $filter->backupCountQuery() ) ,
            ) ;
        }

        if( $absent !== [] )
        {
            $io->error( sprintf( 'Absent from the database : %s — restore them before reinject.' , implode( ', ' , $absent ) ) ) ;
            return ExitCode::FAILURE ;
        }

        if( $plan === [] )
        {
            $io->error( 'Nothing to reinject : no collection was set aside.' ) ;
            return ExitCode::FAILURE ;
        }

        $blocked = array_values( array_intersect( array_keys( $plan ) , $this->restoreProtectedCollections() ) ) ;
        $forced  = (bool) $input->getOption( ArangoCommandOption::FORCE ) ;

        if( $blocked !== [] && !$forced )
        {
            $io->error( sprintf( 'Refusing to reinject into protected collection(s) : %s — rerun with --force to override.' , implode( ', ' , $blocked ) ) ) ;
            return ExitCode::FAILURE ;
        }

        $io->text( sprintf( 'Target  : %s @ %s' , $database , $endpoint ) ) ;
        $io->text( $summary ) ;

        if( $blocked !== [] )
        {
            $io->warning( '--force : this WILL write into protected collection(s) : ' . implode( ', ' , $blocked ) ) ;
        }

        if( $input->getOption( ArangoCommandOption::DRY_RUN ) )
        {
            $io->success( 'Dry run — nothing was put back.' ) ;
            return ExitCode::SUCCESS ;
        }

        if( !$input->getOption( ArangoCommandOption::YES ) )
        {
            if( !$input->isInteractive() )
            {
                $io->error( 'Refusing to reinject without confirmation — rerun with --yes.' ) ;
                return ExitCode::FAILURE ;
            }

            $question = new ConfirmationQuestion( sprintf( "Put back into '%s' ? [y/N] " , $database ) , false ) ;
            if( !$this->getQuestionHelper()->ask( $input , $output , $question ) )
            {
                $io->text( 'Aborted.' ) ;
                $io->newLine() ;
                return ExitCode::SUCCESS ;
            }
        }

        $io->newLine() ;

        foreach( $plan as $collection => $filter )
        {
            [ $removed , $back ] = $this->putBack( $db , $filter ) ;

            try
            {
                $db->collection( $filter->backup )->drop() ;
                $io->text( sprintf( '%s : %d removed · %d put back · %s dropped' , $collection , $removed , $back , $filter->backup ) ) ;
            }
            catch( Throwable $exception )
            {
                $io->text( sprintf( '%s : %d removed · %d put back' , $collection , $removed , $back ) ) ;
                $io->warning( sprintf( '%s could not be dropped (%s) — the documents are back ; run reinject again to drop it.' , $filter->backup , $exception->getMessage() ) ) ;
            }
        }

        $io->newLine() ;
        $io->success( 'The documents set aside are back.' ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Removes from a collection the documents the rule names, then copies its whole
     * backup back — in one transaction.
     *
     * @param Database       $db
     * @param PreserveFilter $filter
     * @return array{0:int,1:int} The number of documents removed, then put back.
     * @throws Throwable When a step fails or the counts disagree — the transaction is aborted.
     */
    private function putBack( Database $db , PreserveFilter $filter ) :array
    {
        return $db->withTransaction
        (
            callback : function() use ( $db , $filter ) :array
            {
                $removed  = $this->preserveCount( $db , $filter->removeQuery()  ) ;
                $back     = $this->preserveCount( $db , $filter->putBackQuery() ) ;
                $matching = $this->preserveCount( $db , $filter->countQuery()   ) ;

                if( $matching !== $back )
                {
                    throw new RuntimeException
                    (
                        sprintf
                        (
                            '%s : %d document(s) put back, but %d named by the rule — has the rule changed since preserve ? Nothing was changed.' ,
                            $filter->collection , $back , $matching
                        )
                    ) ;
                }

                return [ $removed , $back ] ;
            } ,
            write : [ $filter->collection ] ,
            read  : [ $filter->backup ] ,
        ) ;
    }
}
