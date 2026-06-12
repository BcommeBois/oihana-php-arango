<?php

namespace oihana\arango\commands\actions;

use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoMigrationsTrait;
use oihana\arango\migrations\enums\MigrationStatus;
use oihana\arango\migrations\MigrationGenerator;
use oihana\arango\migrations\MigrationRunner;
use oihana\arango\migrations\MigrationStore;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

use oihana\enums\Char;

// Apply / roll back the versioned data migrations
// $ php bin/console.php command:arangodb migrate                      (apply the pending migrations, with confirmation)
// $ php bin/console.php command:arangodb migrate --yes                (apply without the prompt — bun pull / CI)
// $ php bin/console.php command:arangodb migrate --status             (table : applied / pending)
// $ php bin/console.php command:arangodb migrate --dry-run            (list what would run, without running it)
// $ php bin/console.php command:arangodb migrate --down               (roll back the last applied migration)
// $ php bin/console.php command:arangodb migrate --down=3             (roll back the last 3)
// $ php bin/console.php command:arangodb migrate --forget=VERSION     (rescue : drop a tracking row without running down())
// $ php bin/console.php command:arangodb migrate --create "add kind"  (generate an empty migration shell)

/**
 * Applies the versioned data migrations of the database — the imperative
 * counterpart of the declarative `doctor` action.
 *
 * Where `doctor` reconciles the *structure* declared in the DI definitions,
 * `migrate` runs hand-written PHP migrations that transform *data already in
 * the database* (a backfill, a field reshape, a one-off cleanup) — things
 * that cannot be expressed as configuration. Each migration runs once per
 * database, tracked in the `migrations` collection
 * ({@see MigrationStore} / {@see \oihana\arango\migrations\MigrationAction}).
 *
 * Modes:
 * - default       : applies the pending migrations, **after confirmation**
 *                   ({@see ArangoCommandOption::YES} skips the prompt; a
 *                   non-interactive run without `--yes` stops, by safety).
 * - `--status`    : the applied / pending table.
 * - `--dry-run`   : lists the pending migrations without running them.
 * - `--down[=n]`  : rolls back the last (n) applied migrations (LIFO).
 * - `--forget=V`  : rescue — drops a tracking row without running its `down()`.
 * - `--create "…"`: generates an empty migration shell (the engine never
 *                   fills `up()` / `down()`).
 *
 * The migrations folder, namespace and tracking collection are supplied via
 * the command init keys ({@see ArangoMigrationsTrait}).
 *
 * @package oihana\arango\commands\actions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
trait ArangoMigrateAction
{
    use ArangoClientTrait ,
        ArangoMigrationsTrait ,
        IOTrait ;

    /**
     * Applies / inspects / rolls back the data migrations.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int
     */
    protected function migrate( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        // --create does not need a live database : it only writes a file.
        $create = $input->getOption( ArangoCommandOption::CREATE ) ;
        if( is_string( $create ) )
        {
            return $this->migrateCreate( $io , $create ) ;
        }

        $runner = $this->buildRunner( $input , $io ) ;
        if( $runner === null )
        {
            return ExitCode::FAILURE ;
        }

        try
        {
            $forget = $input->getOption( ArangoCommandOption::FORGET ) ;
            if( is_string( $forget ) )
            {
                $runner->forget( $forget ) ;
                $io->text( sprintf( '<info>✓</info> %s — forgotten (tracking row removed, down() NOT run)' , $forget ) ) ;
                $io->newLine() ;
                return ExitCode::SUCCESS ;
            }

            $down = $input->getOption( ArangoCommandOption::DOWN ) ;
            if( $down !== false )
            {
                return $this->migrateDown( $io , $runner , is_string( $down ) ? max( 1 , (int) $down ) : 1 ) ;
            }

            if( $input->getOption( ArangoCommandOption::STATUS ) )
            {
                return $this->migrateStatus( $io , $runner ) ;
            }

            $pending = $runner->pending() ;

            if( $input->getOption( ArangoCommandOption::DRY_RUN ) )
            {
                return $this->migrateDryRun( $io , $pending ) ;
            }

            return $this->migrateApply( $input , $output , $io , $runner , $pending ) ;
        }
        catch( ArangoException $exception )
        {
            $io->error( 'ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }
        catch( Throwable $exception )
        {
            $io->error( $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }
    }

    /**
     * Builds the migration runner from the resolved façade and the command
     * configuration, or null (with an error printed) when no database or no
     * migrations path is available.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     * @return MigrationRunner|null
     */
    private function buildRunner( InputInterface $input , SymfonyStyle $io ) :?MigrationRunner
    {
        if( empty( $this->migrationsPath ) )
        {
            $io->error( 'No migrations path configured — pass it via the `migrationsPath` init key of the command.' ) ;
            return null ;
        }

        $facade = $this->resolveFacade( $input ) ;
        if( $facade === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return null ;
        }

        $store = new MigrationStore( $facade->database() , $this->migrationsCollection ) ;

        return new MigrationRunner
        (
            db        : $facade ,
            store     : $store ,
            path      : $this->migrationsPath ,
            namespace : $this->migrationsNamespace ,
            gitCommit : $this->gitCommit() ,
            agent     : $this->agent() ,
        ) ;
    }

    /**
     * Generates an empty migration shell.
     *
     * @param SymfonyStyle $io
     * @param string       $description
     * @return int
     */
    private function migrateCreate( SymfonyStyle $io , string $description ) :int
    {
        if( empty( $this->migrationsPath ) )
        {
            $io->error( 'No migrations path configured — pass it via the `migrationsPath` init key of the command.' ) ;
            return ExitCode::FAILURE ;
        }

        try
        {
            $file = new MigrationGenerator( $this->migrationsPath , $this->migrationsNamespace )->create( $description ) ;

            $io->text( sprintf( '<info>→ Created</info> %s' , $file ) ) ;
            $io->text( 'Fill its up() (and down() if reversible), then run `migrate`.' ) ;
            $io->newLine() ;
            return ExitCode::SUCCESS ;
        }
        catch( Throwable $exception )
        {
            $io->error( $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }
    }

    /**
     * Rolls back the last `$count` applied migrations.
     *
     * @param SymfonyStyle    $io
     * @param MigrationRunner $runner
     * @param int             $count
     * @return int
     * @throws ArangoException
     */
    private function migrateDown( SymfonyStyle $io , MigrationRunner $runner , int $count ) :int
    {
        $io->section( sprintf( 'Roll back the last %d migration(s)' , $count ) ) ;

        $rolledBack = $runner->down( $count ) ;

        if( $rolledBack === [] )
        {
            $io->text( 'There is nothing to roll back.' ) ;
        }
        else
        {
            foreach( $rolledBack as $version )
            {
                $io->text( sprintf( '<info>✓</info> %s — rolled back' , $version ) ) ;
            }
        }

        $io->newLine() ;
        return ExitCode::SUCCESS ;
    }

    /**
     * Prints the applied / pending status table.
     *
     * @param SymfonyStyle    $io
     * @param MigrationRunner $runner
     * @return int
     * @throws ArangoException
     */
    private function migrateStatus( SymfonyStyle $io , MigrationRunner $runner ) :int
    {
        $io->section( 'Migrations status' ) ;

        $rows = $runner->status() ;

        if( $rows === [] )
        {
            $io->text( 'There are no migrations.' ) ;
            $io->newLine() ;
            return ExitCode::SUCCESS ;
        }

        foreach( $rows as $row )
        {
            $mark = match( true )
            {
                $row[ 'missingFile' ]              => '<error>?</error>' ,
                $row[ 'applied' ]                  => '<info>✓</info>' ,
                default                            => '<comment>·</comment>' ,
            } ;

            $state = $row[ 'missingFile' ] ? 'applied — file missing'
                   : ( $row[ 'applied' ] ? 'applied' : 'pending' ) ;

            $io->text( sprintf( '%s %s — %s (%s)' , $mark , $row[ 'version' ] , $row[ 'description' ] , $state ) ) ;
        }

        $io->newLine() ;
        return ExitCode::SUCCESS ;
    }

    /**
     * Lists the pending migrations without running them.
     *
     * @param SymfonyStyle              $io
     * @param array<string, mixed>      $pending
     * @return int
     */
    private function migrateDryRun( SymfonyStyle $io , array $pending ) :int
    {
        $io->section( 'Dry run — pending migrations' ) ;

        if( $pending === [] )
        {
            $io->text( 'The database is up to date — nothing to apply.' ) ;
        }
        else
        {
            foreach( $pending as $version => $migration )
            {
                $io->text( sprintf( '<comment>·</comment> %s — %s' , $version , $migration->description() ) ) ;
            }
        }

        $io->newLine() ;
        return ExitCode::SUCCESS ;
    }

    /**
     * Applies the pending migrations, after confirmation.
     *
     * @param InputInterface         $input
     * @param OutputInterface        $output
     * @param SymfonyStyle           $io
     * @param MigrationRunner        $runner
     * @param array<string, mixed>   $pending
     * @return int
     * @throws ArangoException
     */
    private function migrateApply( InputInterface $input , OutputInterface $output , SymfonyStyle $io , MigrationRunner $runner , array $pending ) :int
    {
        $io->section( 'Apply the pending migrations' ) ;

        if( $pending === [] )
        {
            $io->text( 'The database is up to date — nothing to apply.' ) ;
            $io->newLine() ;
            return ExitCode::SUCCESS ;
        }

        $io->text( sprintf( 'À appliquer (%d) :' , count( $pending ) ) ) ;
        foreach( $pending as $version => $migration )
        {
            $io->text( sprintf( '  · %s — %s' , $version , $migration->description() ) ) ;
        }

        if( !$input->getOption( ArangoCommandOption::YES ) )
        {
            if( !$input->isInteractive() )
            {
                $io->error( 'Refusing to apply migrations without confirmation — rerun with --yes.' ) ;
                return ExitCode::FAILURE ;
            }

            $question = new ConfirmationQuestion( sprintf( 'Appliquer ces %d migration(s) ? [y/N] ' , count( $pending ) ) , false ) ;
            if( !$this->getQuestionHelper()->ask( $input , $output , $question ) )
            {
                $io->text( 'Aborted.' ) ;
                $io->newLine() ;
                return ExitCode::SUCCESS ;
            }
        }

        $io->newLine() ;

        $recorded = $runner->apply() ;
        $failed   = false ;

        foreach( $recorded as $action )
        {
            if( $action->actionStatus === MigrationStatus::COMPLETED )
            {
                $io->text( sprintf( '<info>✓</info> %s — applied' , $action->_key ) ) ;
            }
            else
            {
                $io->text( sprintf( '<error>✗</error> %s — failed : %s' , $action->_key , $action->error ?? Char::EMPTY ) ) ;
                $failed = true ;
            }
        }

        $io->newLine() ;
        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS ;
    }
}
