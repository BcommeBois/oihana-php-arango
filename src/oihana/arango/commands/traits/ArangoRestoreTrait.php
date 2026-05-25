<?php

namespace oihana\arango\commands\traits;

use oihana\arango\commands\options\ArangoRestoreOptions;
use oihana\enums\Char;
use RuntimeException;
use function oihana\commands\helpers\silent;

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoRestoreTrait
{
    use ArangoConfigTrait ;

    /**
     * The arango restore command.
     */
    public const string ARANGO_RESTORE = 'arangorestore' ;

    /**
     * Run the 'arangorestore' command to restore the ArangoDB database.
     * @param array|ArangoRestoreOptions|null $options The arangorestore options definition.
     * @param bool $silent Indicates if the command is invoked silently.
     * @return int
     * @throws RuntimeException
     */
    public function arangoRestore( array|ArangoRestoreOptions|null $options = null , bool $silent = false ) :int
    {
        $command = $this->getArangoRestoreCommand( $options , $silent ) ;
        // echo PHP_EOL . '>>>>>>>>>> command : ' . $command . PHP_EOL ;
        system( $command  , $status ) ;
        if( $status == 0 )
        {
            return $status ;
        }
        else
        {
            throw new RuntimeException( 'The ArangoDB database restore command failed.' , $status ) ;
        }
    }

    public function getArangoRestoreCommand( array|ArangoRestoreOptions|null $options = null , bool $silent = false  ):string
    {
        // Default command
        $command = self::ARANGO_RESTORE . Char::SPACE . ArangoRestoreOptions::create( $options ) ;

        // Docker command
        // TODO special command when the arangodb use a Docker container
        //
        // $input       = $directory
        // $dockerInput = '/restore'; // The directory in the Docker container
        //
        // $command = <<<CMD
        // docker run --rm \
        // -v {$input}:{$dockerInput} \
        // arangodb/arangodb \
        // arangorestore \
        // --server.endpoint tcp://host.docker.internal:8529 \
        // --server.database {$database} \
        // --server.username root \
        // --server.password {$password} \
        // --input-directory {$dockerInput}
        // CMD;

        return silent( $command , $silent ) ;
    }
}