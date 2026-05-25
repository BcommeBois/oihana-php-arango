<?php

namespace oihana\arango\commands\options;

use oihana\options\Options;
use ReflectionException;

/**
 * <p>The startup options of the arangorestore executable.</p>
 * <p><b>Usage:</b> <code>arangorestore [<options>]</code></p>
 * @see https://docs.arangodb.com/stable/components/tools/arangorestore/options/
 */
class ArangoRestoreOptions extends Options
{
    use ArangoCommonOptions ;

    /**
     * Clean up duplicate attributes (use first specified value) in input documents instead of making the restore operation fail.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $cleanupDuplicateAttributes ;

    /**
     * Continue the restore operation.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $continue;

    /**
     * Create collection structure.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $createCollection ;

    /**
     * Create the target database if it does not exist.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $createDatabase ;

    /**
     * The default numberOfShards value if not specified in the dump.
     * Default: 1
     * @var int|null
     */
    public int|null $defaultNumberOfShards ;

    /**
     * The default replicationFactor value if not specified in the dump.
     * Default: 1
     * @var int|null
     */
    public int|null $defaultReplicationFactor ;

    /**
     * <p>Enable revision trees for new collections if the collection attributes syncByRevision and usesRevisionsAsDocumentIds are missing.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * <p>Default: true</p>
     * @var bool|null
     */
    public bool|null $enableRevisionTrees ;

    /**
     * <p>Force the same database name as in the source dump.json file.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $forceSameDatabase ;

    /**
     * Import data into collection.
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $importData;

    /**
     * <p>Include system collections.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $includeSystemCollections;

    /**
     * <p>The number of connect retries for the initial connection.</p>
     * @var int|null
     */
    public int|null $initialConnectRetries ;

    /**
     * <p>The input directory.</p>
     * <p>Default: /dump</p>
     * @var string|null
     */
    public string|null $inputDirectory ;

    /**
     * Maximum cumulated size of spare in-memory buffers to keep.
     * Default: 536870912
     * @var int|null
     */
    public int|null $maxUnusedBuffersCapacity;

    /**
     * <p>Override the numberOfShards value (can be specified multiple times, e.g. --number-of-shards 2 --number-of-shards myCollection=3).</p>
     * @var int|null
     */
    public int|null $numberOfShards ;

    /**
     * Override the replicationFactor value (can be specified multiple times, e.g. --replication-factor 2 --replication-factor myCollection=3).
     * @var string|array|null
     */
    public string|array|null $replicationFactor;

    /**
     * <p>Restrict the restore to this view name (can be specified multiple times).</p>
     * @var string|array|null
     */
    public string|array|null $view ;

    /**
     * <p>Override the writeConcern value (can be specified multiple times, e.g. --write-concern 2 --write-concern myCollection=3).</p>
     * @var string|array|null
     */
    public string|array|null $writeConcern ;

    /**
     * The path for temporary files.
     * @var string|null
     */
    public string|null $tempPath ;

    /**
     * Returns the string expression of the object.
     * @return string
     * @throws ReflectionException
     */
    public function __toString() : string
    {
        return $this->getOptions( ArangoRestoreOption::class ) ;
    }
}