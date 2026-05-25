<?php

namespace oihana\arango\commands\options;

use oihana\options\Option;
use function oihana\core\strings\dotKebab;
use function oihana\core\strings\hyphenate;

class ArangoDumpOption extends Option
{
    use ArangoCommonOption ;

    public const string COMPRESS_OUTPUT            = 'compressOutput';
    public const string DB_SERVER_PREFETCH_BATCHES = 'dbserverPrefetchBatches';
    public const string DB_SERVER_WORKER_THREADS   = 'dbserverWorkerThreads';
    public const string DOCS_PER_BATCH             = 'docsPerBatch';
    public const string DUMP_DATA                  = 'dumpData';
    public const string DUMP_VIEWS                 = 'dumpViews';
    public const string DUMP_VPACK                 = 'dumpVPack';
    public const string IGNORE_COLLECTION          = 'ignoreCollection';
    public const string INCLUDE_SYSTEM_COLLECTIONS = 'includeSystemCollections';
    public const string INITIAL_BATCH_SIZE         = 'initialBatchSize';
    public const string LOCAL_NETWORK_THREADS      = 'localNetworkThreads';
    public const string LOCAL_WRITER_THREADS       = 'localWriterThreads';
    public const string MASKINGS                   = 'maskings';
    public const string OUTPUT_DIRECTORY           = 'outputDirectory';
    public const string PARALLEL_DUMP              = 'parallelDump';
    public const string SHARD                      = 'shard';
    public const string SPLIT_FILES                = 'splitFiles';

    /**
     * Returns the command line option expression from a specific option.
     * @param string $option The name of the option to modify.
     * @return string
     */
    public static function getCommandOption( string $option ):string
    {
        return static::hasGroup( $option ) ? dotKebab( $option ) : hyphenate( $option ) ;
    }
}