<?php

namespace oihana\arango\commands\options;

/**
 * <p>The trait to inject the common options of the arangorestore and arangodump executable.</p>
 * @see https://docs.arangodb.com/stable/components/tools/arangoredump/options/
 * @see https://docs.arangodb.com/stable/components/tools/arangorestore/options/
 */
trait ArangoCommonOptions
{
    /**
     * Whether to dump all databases.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $allDatabases ;

    /**
     * <p>The maximum size for individual data batches (in bytes).</p>
     * <p>Default: 67108864</p>
     * @var int|null
     */
    public int|null $batchSize ;

    /**
     * Check the configuration and exit.
     * This is a command, no value needs to be specified.
     * The process terminates after executing the command.
     * @var bool|null
     */
    public bool|null $checkConfiguration ;

    /**
     * Restrict the dump to this collection name (can be specified multiple times). Either --collection or --ignore-collection can be used at the same time
     * @var string|array|null
     */
    public string|array|null $collection ;

    /**
     * The HTTP request body size from which on requests are transparently compressed when sending them to the server.
     * @var int|null
     */
    public int|null $compressRequestThreshold;

    /**
     * Compress data for transport between arangodump and server.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $compressTransfer;

    /**
     * The configuration file or “none”.
     * @var string|null
     */
    public string|null $config;

    /**
     * The configuration file or “none”.
     * @var string|null
     */
    public string|null $configuration;

    /**
     * Define a value for a @key@ entry in the configuration file using the syntax "key=value"
     * @var string|array|null
     */
    public string|array|null $define;

    /**
     * <p>The minimum number of file descriptors needed to start (0 = no minimum)</p>
     * <p>Default: 8192</p>
     * @var int|null
     */
    public int|null $descriptorsMinimum;

    /**
     * <p>Dump the dependency graph of the feature phases (internal) and exit.</p>
     * <p>This is a command, no value needs to be specified. The process terminates after executing the command.</p>
     * @var bool|null
     */
    public bool|null $dumpDependencies;

    /**
     * <p>Whether to dump view definitions.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * <p>Default: true</p>
     * @var bool|null
     */
    public bool|null $dumpOptions;

    /**
     * <p>Continue dumping even in the face of some server-side errors.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $force;

    /**
     * <p>Allow hostname lookup configuration via /etc/nsswitch.conf if on Linux/glibc.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $honorNsswitch;

    /**
     * Continue dumping even if a sharding prototype collection is not backed up, too.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $ignoreDistributeShardsLikeErrors;

    /**
     * <p>Set the topic-specific log level, using --log level for the general topic or --log topic=level for the specified topic (can be specified multiple times).</p>
     * <p>Available log levels: fatal, error, warning, info, debug, trace.</p>
     * <p>Default: info</p>
     * @var string|array|null
     */
    public string|array|null $log;

    /**
     * <p>Overwrite data in the output directory.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $overwrite;

    /**
     * <p>Show the dump progress.</p>
     * <p>This option can be specified without a value to enable it.</p>
     * @var bool|null
     */
    public bool|null $progress;

    /**
     * <p>The maximum number of collections/shards to process in parallel.</p>
     * <p>Default: dynamic (e.g. 8)</p>
     * @var int|null
     */
    public int|null $threads;

    /**
     * <p>Use the splice() syscall for file copying (may not be supported on all filesystems).</p>
     * <p>This option can be specified without a value to enable it.</p>
     * <p>Default: true</p>
     * @var bool|null
     */
    public bool|null $useSpliceSyscall;

    /**
     * <p>Print the version and other related information, then exit.</p>
     * <p>This is a command, no value needs to be specified.
     * The process terminates after executing the command.</p>
     * @var bool|null
     */
    public bool|null $version;

    /**
     * <p>Print the version and other related information in JSON format, then exit.</p>
     * <p>This is a command, no value needs to be specified. The process terminates after executing the command.</p>
     * @var bool|null
     */
    public bool|null $versionJson;

    // === Encryption ===

    /**
     * A program providing the encryption key on stdout. If set, encryption at rest is enabled.
     * @var string|null
     */
    public string|null $encryptionKeyGenerator;

    /**
     * The path to the file that contains the encryption key.
     * Must contain 32 bytes of data. If set, encryption at rest is enabled.
     * @var string|null
     */
    public string|null $encryptionKeyfile;

    // === Log ===

    /**
     * Use colors for TTY logging.
     * This option can be specified without a value to enable it.
     * Default: dynamic (e.g. true)
     * @var bool|null
     */
    public bool|null $logColor;

    /**
     * Escape control characters in log messages.
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $logEscapeControlChars;

    /**
     * Escape Unicode characters in log messages.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logEscapeUnicodeChars;

    /**
     * Shortcut for --log.output file://<filename>
     * @var string|null
     */
    public string|null $logFile;

    /**
     * The group to use for a new log file. The user must be a member of this group.
     * @var string|null
     */
    public string|null $logFileGroup;

    /**
     * The mode to use for a new log file. The umask is applied as well.
     * @var string|null
     */
    public string|null $logFileMode;

    /**
     * Do not start a separate thread for logging.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logForceDirect;

    /**
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logForegroundTty;

    /**
     * The hostname to use in log message.
     * Leave empty for none, use “auto” to automatically determine a hostname.
     * @var string|null
     */
    public string|null $logHostname;

    /**
     * Log unique message IDs.
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $logIds;

    /**
     * <p>Set the topic-specific log level, using --log.level level for the general topic or --log.level topic=level for the specified topic (can be specified multiple times).</p>
     *
     * <p>Available log levels: fatal, error, warning, info, debug, trace.</p>
     *
     * <p>Available log topics: all, agency, agencycomm, agencystore, aql, audit-authentication, audit-authorization, audit-collection, audit-database, audit-document, audit-hotbackup, audit-service, audit-view, authentication, authorization, backup, bench, cache, cluster, communication, config, crash, deprecation, development, dump, engines, flush, general, graphs, heartbeat, httpclient, license, maintenance, memory, queries, rep-state, rep-wal, replication, replication2, requests, restore, rocksdb, security, ssl, startup, statistics, supervision, syscall, threads, trx, ttl, v8, validation, views.</p>
     *
     * <p>Default: info</p>
     * @var string|array|null
     */
    public string|array|null $logLevel;

    /**
     * Include the function name, file name, and line number of the source code that issues the log message. Format: [func@FileName.cpp:123]
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logLineNumber;

    /**
     * The maximum length of a log entry (in bytes).
     * Default: 134217728
     * @var int|null
     */
    public int|null $logMaxEntryLength;

    /**
     * Upper limit of log entries that are queued in a background thread.
     * Default: 16384
     * @var int|null
     */
    public int|null $logMaxQueuedEntries;

    /**
     * Log destination(s), e.g. file:///path/to/file (any occurrence of $PID is replaced with the process ID).
     * @var string|array|null
     */
    public string|array|null $logOutput;

    /**
     * Shortcut for --log.level performance=trace.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logPerformance;

    /**
     * Prefix log message with this string.
     * @var string|null
     */
    public string|null $logPrefix;

    /**
     * Show the process identifier (PID) in log messages.
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $logProcess;

    /**
     * include full URLs and HTTP request parameters in trace logs
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $logRequestParameters;

    /**
     * Log the server role.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logRole;

    /**
     * Shorten filenames in log output (use with --log.line-number).
     * This option can be specified without a value to enable it.
     * Default: true
     * @var bool|null
     */
    public bool|null $logShortenFilenames;

    /**
     * Toggle the usage of the log category parameter in structured log messages.
     * @var string|array|null
     */
    public string|array|null $logStructuredParam;

    /**
     * Show the thread identifier in log messages.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logThread;

    /**
     * Show thread name in log messages.
     *
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logThreadName;

    /**
     * The time format to use in logs.
     * Default: utc-datestring-micros
     * Possible values: “local-datestring”, “timestamp”, “timestamp-micros”, “timestamp-millis”, “uptime”, “uptime-micros”, “uptime-millis”, “utc-datestring”, “utc-datestring-micros”, “utc-datestring-millis”
     * @var string|null
     */
    public string|null $logTimeFormat;

    /**
     * Use JSON as output format for logging.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logUseJsonFormat;

    /**
     * Use the local timezone instead of UTC.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logUseLocalTime;

    /**
     * Use Unix timestamps in seconds with microsecond precision.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $logUseMicrotime;

    // === Random ===

    /**
     * <p>The random number generator to use :</p>
     * <ul>
     *     <li>1 = MERSENNE</li>
     *     <li>2 = RANDOM</li>
     *     <li>3 = URANDOM</li>
     *     <li>4 = COMBINED</li>
     * </ul>
     * <p>The options 2, 3, and 4 are deprecated and will be removed in a future version.</p>
     * <p>Default: 1</p>
     * <p></p>Possible values: 1, 2, 3, 4</p>
     * @var int|null
     */
    public int|null $randomGenerator;

    // === Server ===

    /**
     * If enabled, you are prompted for a JWT secret.
     * This option is not compatible with --server.username and --server.password.
     * If specified, it is used for all connections - even if a new connection to another server is created.
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $serverAskJwtSecret;

    /**
     * Require authentication credentials when connecting (does not affect the server-side authentication settings).
     * This option can be specified without a value to enable it.
     * @var bool|null
     */
    public bool|null $serverAuthentication;

    /**
     * The connection timeout (in seconds).
     * Default: 5
     * @var float|null
     */
    public float|null $serverConnectionTimeout;

    /**
     * The database name to use when connecting.
     * Default: _system
     * @var string|null
     */
    public string|null $serverDatabase;

    /**
     * The endpoint to connect to. Use ’none’ to start without a server.
     * Use http+ssl:// as schema to connect to an SSL-secured server endpoint, otherwise http+tcp:// or unix://
     * Default: http+tcp://127.0.0.1:8529
     * @var string|array|null
     */
    public string|array|null $serverEndpoint;

    /**
     * If enabled, the JWT secret is loaded from the given file.
     * This option is not compatible with --server.ask-jwt-secret, --server.username and --server.password.
     * If specified, it is used for all connections - even if a new connection to another server is created.
     * @var string|null
     */
    public string|null $serverJwtSecretKeyfile;

    /**
     * The maximum packet size (in bytes) for client/server communication.
     * Default: 1073741824
     * @var int|null
     */
    public int|null $serverMaxPacketSize;

    /**
     * The password to use when connecting.
     * If not specified and authentication is required, you are prompted for a password.
     * In startup options, you can wrap the names of environment variables in at signs to use their value, like @ARANGO_PASSWORD@.
     * This helps to expose the password less, like to the process list. Literal @ need to be escaped as @@.
     * @var string|null
     */
    public string|null $serverPassword;

    /**
     * The request timeout (in seconds).
     * Default: 1200
     * @var float|null
     */
    public float|null $serverRequestTimeout;

    /**
     * The username to use when connecting.
     * Default: root
     * @var string|null
     */
    public string|null $serverUsername;

    // === SSL ===

    /**
     * <p></p>The SSL protocol :</p>
     * <ul>
     *   <li>1 = SSLv2 (unsupported),</li>
     *   <li>2 = SSLv2 or SSLv3 (negotiated)</li>
     *   <li>3 = SSLv3</li>
     *   <li>4 = TLSv1</li>
     *   <li>5 = TLSv1.2</li>
     *   <li>6 = TLSv1.3</li>
     *   <li>9 = generic TLS (negotiated))</li>
     * </ul>
     * <p>Default: 5</p>
     * <p></p>Possible values: 1, 2, 3, 4, 5, 6, 9</p>
     * @var int|null
     */
    public int|null $sslProtocol;
}