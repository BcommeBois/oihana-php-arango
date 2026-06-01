<?php

namespace oihana\arango\commands\traits;

use ReflectionException;
use RuntimeException;

use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\options\ArangoRestoreOptions;

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoRestoreTrait
{
    use ArangoConfigTrait ,
        ArangoProcessTrait ;

    /**
     * The arango restore command.
     */
    public const string ARANGO_RESTORE = 'arangorestore' ;

    /**
     * Run the 'arangorestore' command to restore the ArangoDB database.
     *
     * @param array|ArangoRestoreOptions|null $options The arangorestore options definition.
     * @param bool $silent Indicates if the command is invoked silently.
     *
     * @return int
     *
     * @throws ReflectionException
     */
    public function arangoRestore( array|ArangoRestoreOptions|null $options = null , bool $silent = false ) :int
    {
        $status = static::runProcess( $this->getArangoRestoreArguments( $options ) , $silent ) ;
        if( $status !== 0 )
        {
            throw new RuntimeException( 'The ArangoDB database restore command failed.' , $status ) ;
        }
        return $status ;
    }

    /**
     * Builds the `arangorestore` argument vector (argv[0] = binary name).
     *
     * The vector is executed without a shell (see {@see ArangoProcessTrait::runProcess()}),
     * so option values are passed verbatim and never re-interpreted.
     *
     * @param array|ArangoRestoreOptions|null $options
     * @return array<int, string>
     * @throws ReflectionException
     */
    public function getArangoRestoreArguments( array|ArangoRestoreOptions|null $options = null ) :array
    {
        return [ self::ARANGO_RESTORE , ...static::optionsToArguments( ArangoRestoreOptions::create( $options ) , ArangoRestoreOption::class ) ] ;
    }
}
