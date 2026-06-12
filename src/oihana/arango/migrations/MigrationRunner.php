<?php

namespace oihana\arango\migrations ;

use ReflectionException;
use RuntimeException ;
use Throwable ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\db\ArangoDB ;
use oihana\arango\migrations\enums\MigrationKind ;
use oihana\arango\migrations\enums\MigrationStatus ;

/**
 * The migration engine — discovers the version files, compares them with the
 * tracking collection of the database, and applies / rolls back the
 * difference.
 *
 * Stateless with respect to time and identity: the run timestamps, the
 * acting `agent` (`user@host`) and the `gitCommit` are injected, so a host
 * can stamp them and the tests can pin them.
 *
 * @package oihana\arango\migrations
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class MigrationRunner
{
    /**
     * @param ArangoDB      $db        The façade handed to every {@see Migration}.
     * @param MigrationStore $store     The tracking-collection persistence.
     * @param string        $path      The directory holding the `Version*.php` files.
     * @param string        $namespace The PHP namespace the version classes live in (e.g. `fr\bouney\migrations`).
     * @param string|null   $gitCommit The current git commit hash, or null outside a repository.
     * @param string|null   $agent     The acting agent (`user@host`), or null.
     */
    public function __construct
    (
        protected ArangoDB       $db ,
        protected MigrationStore $store ,
        protected string         $path ,
        protected string         $namespace = '' ,
        protected ?string        $gitCommit = null ,
        protected ?string        $agent     = null ,
    )
    {
    }

    /**
     * Discovers the migration version files of the configured directory,
     * instantiated and sorted by version (the timestamp prefix orders them).
     *
     * @return array<string, Migration> The migrations keyed by version, in ascending order.
     *
     * @throws RuntimeException When a `Version*.php` file does not resolve to a {@see Migration} class.
     */
    public function discover() : array
    {
        $migrations = [] ;

        foreach ( $this->versionFiles() as $file )
        {
            $version = basename( $file , '.php' ) ;
            $class   = $this->namespace === '' ? $version : $this->namespace . '\\' . $version ;

            if ( !class_exists( $class ) )
            {
                require_once $file ;
            }

            if ( !class_exists( $class ) || !is_subclass_of( $class , Migration::class ) )
            {
                throw new RuntimeException( sprintf( 'The file "%s" does not define a %s subclass named "%s".' , $file , Migration::class , $class ) ) ;
            }

            $migrations[ $this->version( $version ) ] = new $class( $this->db ) ;
        }

        ksort( $migrations ) ;

        return $migrations ;
    }

    /**
     * Applies the pending migrations in order. For each: records an `active`
     * row, runs `up()`, then records `completed` — or `failed` (with the
     * error) and **stops immediately**, leaving the following migrations
     * untouched.
     *
     * @param string|null $now The run timestamp (ISO 8601) — injected for determinism; defaults to the current time.
     *
     * @return array<int, MigrationAction> The recorded actions, in run order (the last is `failed` when the run aborted).
     *
     * @throws ArangoException When the tracking collection cannot be written.
     * @throws ReflectionException
     */
    public function apply( ?string $now = null ) : array
    {
        $now    = $now ?? $this->now() ;
        $recorded = [] ;

        foreach ( $this->pending() as $version => $migration )
        {
            $action = $this->newAction( $version , $migration->description() , MigrationKind::MIGRATE ) ;
            $action->actionStatus = MigrationStatus::ACTIVE ;
            $action->startTime    = $now ;
            $this->store->save( $action ) ;

            try
            {
                $migration->up() ;

                $action->actionStatus = MigrationStatus::COMPLETED ;
                $action->endTime      = $this->now() ;
                $this->store->save( $action ) ;

                $recorded[] = $action ;
            }
            catch ( Throwable $exception )
            {
                $action->actionStatus = MigrationStatus::FAILED ;
                $action->endTime      = $this->now() ;
                $action->error        = $exception->getMessage() ;
                $this->store->save( $action ) ;

                $recorded[] = $action ;

                break ; // stop net : never run a migration after a failed one
            }
        }

        return $recorded ;
    }

    /**
     * Rolls back the `$count` most recently applied migrations (LIFO): runs
     * each `down()` then removes its tracking row. A migration whose file is
     * gone, or whose `down()` is the default no-op, is skipped from the
     * rollback effect but still un-tracked.
     *
     * @param int $count The number of migrations to roll back (the last ones).
     *
     * @return array<int, string> The versions rolled back, most-recent first.
     *
     * @throws ArangoException When the tracking collection cannot be read or written.
     * @throws ReflectionException
     */
    public function down( int $count = 1 ) : array
    {
        $discovered = $this->discover() ;
        $applied    = array_keys( $this->store->applied() ) ;
        rsort( $applied ) ; // most recent first

        $rolledBack = [] ;

        foreach ( array_slice( $applied , 0 , max( 0 , $count ) ) as $version )
        {
            if ( isset( $discovered[ $version ] ) )
            {
                $discovered[ $version ]->down() ;
            }

            $this->store->remove( $version ) ;
            $rolledBack[] = $version ;
        }

        return $rolledBack ;
    }

    /**
     * Removes a migration's tracking row **without** running its `down()` —
     * the rescue operation for a tracking collection that drifted from
     * reality (e.g. a migration undone by hand). Dangerous: the migration
     * will be considered pending again on the next run.
     *
     * @param string $version The migration version to forget.
     *
     * @return void
     *
     * @throws ArangoException When the tracking collection cannot be written.
     */
    public function forget( string $version ) : void
    {
        $this->store->remove( $version ) ;
    }

    /**
     * Returns the pending migrations — discovered but not yet applied, in
     * ascending order.
     *
     * @return array<string, Migration>
     *
     * @throws ArangoException When the tracking collection cannot be read.
     * @throws ReflectionException
     */
    public function pending() : array
    {
        $applied = $this->store->applied() ;
        $pending = [] ;

        foreach ( $this->discover() as $version => $migration )
        {
            if ( !isset( $applied[ $version ] ) )
            {
                $pending[ $version ] = $migration ;
            }
        }

        return $pending ;
    }

    /**
     * Returns the status of every migration — discovered files crossed with
     * the applied tracking rows.
     *
     * Each entry: `version`, `description`, `applied` (bool), `status`
     * (the tracking `actionStatus`, or null), `missingFile` (true for an
     * applied version whose file is gone).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ArangoException When the tracking collection cannot be read.
     * @throws ReflectionException
     */
    public function status() : array
    {
        $discovered = $this->discover() ;
        $applied    = $this->store->applied() ;

        $rows = [] ;

        foreach ( $discovered as $version => $migration )
        {
            $rows[ $version ] =
            [
                'version'     => $version ,
                'description' => $migration->description() ,
                'applied'     => isset( $applied[ $version ] ) ,
                'status'      => $applied[ $version ]->actionStatus ?? null ,
                'missingFile' => false ,
            ] ;
        }

        foreach ( $applied as $version => $action )
        {
            if ( !isset( $rows[ $version ] ) )
            {
                $rows[ $version ] =
                [
                    'version'     => $version ,
                    'description' => $action->description ?? '' ,
                    'applied'     => true ,
                    'status'      => $action->actionStatus ?? null ,
                    'missingFile' => true ,
                ] ;
            }
        }

        ksort( $rows ) ;

        return array_values( $rows ) ;
    }

    // ---- internals --------------------------------------------------------

    /**
     * Builds a fresh {@see MigrationAction} stamped with the run identity.
     *
     * @param string $version        The version (`_key`).
     * @param string $description     The human description.
     * @param string $additionalType  The event family ({@see MigrationKind}).
     *
     * @return MigrationAction
     */
    protected function newAction( string $version , string $description , string $additionalType ) : MigrationAction
    {
        $action = new MigrationAction() ;

        $action->_key           = $version ;
        $action->identifier     = $version ;
        $action->name           = $version ;
        $action->description    = $description ;
        $action->additionalType = $additionalType ;
        $action->agent          = $this->agent ;
        $action->gitCommit      = $this->gitCommit ;

        return $action ;
    }

    /**
     * The current ISO 8601 timestamp (overridable in tests).
     *
     * @return string
     */
    protected function now() : string
    {
        return date( 'c' ) ;
    }

    /**
     * Strips the `Version` prefix to get the tracking version / `_key`.
     *
     * @param string $className The `Version<timestamp>_<Label>` class/file name.
     *
     * @return string
     */
    protected function version( string $className ) : string
    {
        return str_starts_with( $className , 'Version' ) ? substr( $className , 7 ) : $className ;
    }

    /**
     * The `Version*.php` files of the configured directory.
     *
     * @return array<int, string>
     */
    protected function versionFiles() : array
    {
        if ( !is_dir( $this->path ) )
        {
            return [] ;
        }

        $files = glob( rtrim( $this->path , DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'Version*.php' ) ?: [] ;
        sort( $files ) ;

        return $files ;
    }
}
