<?php

namespace oihana\arango\commands\options;

use oihana\options\Option;
use function oihana\core\strings\dotKebab;
use function oihana\core\strings\hyphenate;

class ArangoRestoreOption extends Option
{
    use ArangoCommonOption ;

    public const string CLEANUP_DUPLICATE_ATTRIBUTES = 'cleanupDuplicateAttributes';
    public const string CONTINUE                     = 'continue';
    public const string CREATE_COLLECTION            = 'createCollection';
    public const string CREATE_DATABASE              = 'createDatabase';
    public const string DEFAULT_NUMBER_OF_SHARDS     = 'defaultNumberOfShards';
    public const string DEFAULT_REPLICATION_FACTOR   = 'defaultReplicationFactor';
    public const string ENABLE_REVISION_TREES        = 'enableRevisionTrees';
    public const string FORCE_SAME_DATABASE          = 'forceSameDatabase';
    public const string IMPORT_DATA                  = 'importData';
    public const string INCLUDE_SYSTEM_COLLECTIONS   = 'includeSystemCollections';
    public const string INITIAL_CONNECT_RETRIES      = 'initialConnectRetries';
    public const string INPUT_DIRECTORY              = 'inputDirectory';
    public const string MAX_UNUSED_BUFFERS_CAPACITY  = 'maxUnusedBuffersCapacity';
    public const string NUMBER_OF_SHARDS             = 'numberOfShards';
    public const string REPLICATION_FACTOR           = 'replicationFactor';
    public const string VIEW                         = 'view';
    public const string WRITE_CONCERN                = 'writeConcern';
    public const string TEMP_PATH                    = 'tempPath';

    /**
     * Returns the command line option expression from a specific option.
     * @param string $option The name of the option to modify.
     * @return string
     */
    public static function getCommandOption( string $option ):string
    {
        return static::hasGroup( $option , [ static::TEMP_PATH ] ) ? dotKebab( $option ) : hyphenate( $option ) ;
    }
}