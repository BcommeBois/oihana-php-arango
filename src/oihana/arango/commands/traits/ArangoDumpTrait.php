<?php

namespace oihana\arango\commands\traits;

use ReflectionException;
use RuntimeException;

use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoDumpOptions;

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoDumpTrait
{
    use ArangoConfigTrait ,
        ArangoProcessTrait ;

    /**
     * The arango dump command.
     */
    public const string ARANGO_DUMP = 'arangodump' ;

    /**
     * Run the 'arangodump' command to dump the ArangoDB database.
     *
     * @param array|ArangoDumpOptions|null $options The arangodump options definition.
     * @param bool $silent Indicates if the command is invoked silently.
     *
     * @return int
     *
     * @throws ReflectionException
     */
    public function arangoDump( array|ArangoDumpOptions|null $options = null , bool $silent = false ) :int
    {
        $status = static::runProcess( $this->getArangoDumpArguments( $options ) , $silent ) ;
        if( $status !== 0 )
        {
            throw new RuntimeException( 'The ArangoDB database dump command failed.' , $status ) ;
        }
        return $status ;
    }

    /**
     * Builds the `arangodump` argument vector (argv[0] = binary name).
     *
     * The vector is executed without a shell (see {@see ArangoProcessTrait::runProcess()}),
     * so option values are passed verbatim and never re-interpreted.
     *
     * @param array|ArangoDumpOptions|null $options
     *
     * @return array<int, string>
     *
     * @throws ReflectionException
     */
    public function getArangoDumpArguments( array|ArangoDumpOptions|null $options = null ) :array
    {
        return [ self::ARANGO_DUMP , ...static::optionsToArguments( ArangoDumpOptions::create( $options ) , ArangoDumpOption::class ) ] ;
    }
}
