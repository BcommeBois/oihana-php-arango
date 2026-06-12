<?php

namespace oihana\arango\commands\traits;

use Symfony\Component\Console\Input\InputInterface;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoRestoreOption;

/**
 * Resolves the effective `arangodump` / `arangorestore` options by layering,
 * from the lowest to the highest precedence:
 *
 * ```
 * binary default (null)  →  [arango.dump] / [arango.restore] config  →  CLI
 * ```
 *
 * The `[arango.dump]` and `[arango.restore]` sections come from `config.toml`
 * (see `definitions/config.php`) and reach the command through the `dump` /
 * `restore` init keys ({@see ArangoCommandParam::DUMP},
 * {@see ArangoCommandParam::RESTORE}). The resolved connection settings and the
 * curated CLI flags always win over the configured defaults.
 *
 * The keys of the config sections are the option property names of
 * {@see ArangoDumpOptions} /
 * {@see ArangoRestoreOptions} (e.g. `threads`,
 * `overwrite`, `includeSystemCollections`). Unknown keys are silently ignored
 * by the options constructor.
 */
trait ArangoOptionsTrait
{
    /**
     * The `[arango.dump]` config defaults (option name => value).
     * @var array
     */
    protected array $dumpConfig = [] ;

    /**
     * The `[arango.restore]` config defaults (option name => value).
     * @var array
     */
    protected array $restoreConfig = [] ;

    /**
     * Captures the `[arango.dump]` / `[arango.restore]` config sections from the
     * init array. Non-array values are ignored, leaving the defaults empty.
     * @param array $init
     * @return static
     */
    public function initializeArangoOptions( array $init = [] ) :static
    {
        $dump = $init[ ArangoCommandParam::DUMP ] ?? null ;
        if( is_array( $dump ) )
        {
            $this->dumpConfig = $dump ;
        }

        $restore = $init[ ArangoCommandParam::RESTORE ] ?? null ;
        if( is_array( $restore ) )
        {
            $this->restoreConfig = $restore ;
        }

        return $this ;
    }

    /**
     * Layers the explicit dump options over the `[arango.dump]` config defaults,
     * then lets the curated CLI flags override everything.
     * @param array $explicit The options resolved by the action (connection, output directory, collection targeting).
     * @param InputInterface $input The current console input.
     * @return array
     */
    protected function resolveDumpOptions( array $explicit , InputInterface $input ) :array
    {
        $options = array_merge( $this->dumpConfig , $explicit ) ;

        if( $input->getOption( ArangoCommandOption::INCLUDE_SYSTEM ) )
        {
            $options[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] = true ;
        }

        if( $input->getOption( ArangoCommandOption::NO_VIEWS ) )
        {
            $options[ ArangoDumpOption::DUMP_VIEWS ] = false ;
        }

        if( $input->getOption( ArangoCommandOption::ALL_DATABASES ) )
        {
            $options[ ArangoDumpOption::ALL_DATABASES ] = true ;
        }

        if( $input->getOption( ArangoCommandOption::OVERWRITE ) )
        {
            $options[ ArangoDumpOption::OVERWRITE ] = true ;
        }

        $threads = $input->getOption( ArangoCommandOption::THREADS ) ;
        if( $threads !== null && $threads !== false )
        {
            $options[ ArangoDumpOption::THREADS ] = (int) $threads ;
        }

        return $options ;
    }

    /**
     * Layers the explicit restore options over the `[arango.restore]` config
     * defaults, then lets the curated CLI flags override everything.
     * @param array $explicit The options resolved by the action (connection, input directory, create flags, collection targeting).
     * @param InputInterface $input The current console input.
     * @return array
     */
    protected function resolveRestoreOptions( array $explicit , InputInterface $input ) :array
    {
        $options = array_merge( $this->restoreConfig , $explicit ) ;

        if( $input->getOption( ArangoCommandOption::INCLUDE_SYSTEM ) )
        {
            $options[ ArangoRestoreOption::INCLUDE_SYSTEM_COLLECTIONS ] = true ;
        }

        if( $input->getOption( ArangoCommandOption::ALL_DATABASES ) )
        {
            $options[ ArangoRestoreOption::ALL_DATABASES ] = true ;
        }

        $threads = $input->getOption( ArangoCommandOption::THREADS ) ;
        if( $threads !== null && $threads !== false )
        {
            $options[ ArangoRestoreOption::THREADS ] = (int) $threads ;
        }

        $view = [] ;
        foreach( (array) $input->getOption( ArangoCommandOption::VIEW ) as $value )
        {
            foreach( explode( ',' , (string) $value ) as $name )
            {
                $name = trim( $name ) ;
                if( $name !== '' )
                {
                    $view[] = $name ;
                }
            }
        }

        if( $view !== [] )
        {
            $options[ ArangoRestoreOption::VIEW ] = $view ;
        }

        return $options ;
    }
}
