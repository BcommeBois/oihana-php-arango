<?php

namespace oihana\arango\commands\options;

use oihana\options\Options;
use ReflectionException;

/**
 * <p>The startup options of the arangodump executable.</p>
 * <p><b>Usage:</b> <code>arangodump [<options>]</code></p>
 * @see https://docs.arangodb.com/stable/components/tools/arangodump/options/
 */
class ArangoDumpOptions extends Options
{
    use ArangoCommonOptions ;

    /**
     * Compress files containing collection contents using the gzip format.
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $compressOutput ;

    /**
     * Number of batches to prefetch on each DB-Server.
     * Default: 5
     * @var int|null
     */
    public int|null $dbserverPrefetchBatches;

    /**
     * Number of worker threads on each DB-Server.
     * @var int|null
     */
    public int|null $dbserverWorkerThreads ;

    /**
     * <p>The maximum number of documents to be returned per batch.</p>
     * <p>Default: 10000</p>
     * @var int|null
     */
    public int|null $docsPerBatch;

    /**
     * <p>Whether to dump collection data.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $dumpData;

    /**
     * <p>Dump all available startup options in JSON format and exit.</p>
     * <p>This is a command, no value needs to be specified. The process terminates after executing the command.</p>
     * @var bool|null
     */
    public bool|null $dumpViews;

    /**
     * <p>Dump collection data in velocypack format (more compact than JSON, but requires ArangoDB 3.12 or higher to restore)</p>
     * <p></p>This option can be specified without a value to enable it.<p>
     * @var bool|null
     */
    public bool|null $dumpVPack;

    /**
     * Include system collections.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $includeSystemCollections;

    /**
     * <p>The initial size for individual data batches (in bytes).</p>
     * Default: 8388608
     * @var int|null
     */
    public int|null $initialBatchSize;

    /**
     * <p>Number of local network threads, i.e. how many requests are sent in parallel.</p>
     * Default: 5
     * @var int|null
     */
    public int|null $localNetworkThreads;

    /**
     * <p>Number of local writer threads.</p>
     * Default: 5
     * @var int|null
     */
    public int|null $localWriterThreads;

    /**
     * A path to a file with masking definitions.
     * @var string|null
     */
    public string|null $maskings;

    /**
     * <p>The folder path to write the dump to.</p>
     * <p>Default: /dump</p>
     * @var string|null
     */
    public string|null $outputDirectory;

    /**
     * <p>Enable highly parallel dump behavior.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * <p>Default: true</p>
     * @var bool|null
     */
    public bool|null $parallelDump;

    /**
     * Restrict the dump to this shard (can be specified multiple times).
     * @var string|array|null
     */
    public string|array|null $shard;

    /**
     * <p>Split a collection in multiple files to increase throughput.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $splitFiles;

    /**
     * Returns the string expression of the object.
     * @return string
     * @throws ReflectionException
     */
    public function __toString() : string
    {
        return $this->getOptions( ArangoDumpOption::class ) ;
    }

}