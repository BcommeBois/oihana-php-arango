<?php

namespace oihana\arango\commands\traits;

use oihana\arango\commands\options\ArangoDumpOptions;
use oihana\enums\Char;
use RuntimeException;
use function oihana\commands\helpers\silent;

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoDumpTrait
{
    use ArangoConfigTrait ;

    /**
     * The arango dump command.
     */
    public const string ARANGO_DUMP = 'arangodump' ;

    /**
     * Run the 'arangodump' command to dump the ArangoDB database.
     * @param array|ArangoDumpOptions|null $options The arangodump options definition.
     * @param bool $silent Indicates if the command is invoked silently.
     * @return int
     */
    public function arangoDump(  array|ArangoDumpOptions|null $options = null , bool $silent = false ) :int
    {
        system( $this->getArangoDumpCommand( $options , $silent ) , $status ) ;
        if( $status == 0 )
        {
            return $status ;
        }
        else
        {
            throw new RuntimeException( 'The ArangoDB database dump command failed.' , $status ) ;
        }
    }

    /**
     * Generates the arangodump command expression.
     * @param array|ArangoDumpOptions|null $options
     * @param bool $silent
     * @return string
     */
    public function getArangoDumpCommand( array|ArangoDumpOptions|null $options = null , bool $silent = false  ):string
    {
        // Default command
        $command = self::ARANGO_DUMP . Char::SPACE . ArangoDumpOptions::create( $options ) ;

        // Docker command
        // TODO special command when the arangodb use a Docker container
        //
        // $output       = $directory
        // $dockerOutput = '/dumps'; // The directory in the Docker container
        //
        // $command = <<<CMD
        // docker run --rm \
        // -v {$output}:{$dockerOutput} \
        // arangodb/arangodb \
        // arangodump \
        // --server.endpoint tcp://host.docker.internal:8529 \
        // --server.database {$database} \
        // --server.username root \
        // --server.password {$password} \
        // --input-directory {$dockerOutput}
        // CMD;

        return silent( $command , $silent ) ;
    }
}