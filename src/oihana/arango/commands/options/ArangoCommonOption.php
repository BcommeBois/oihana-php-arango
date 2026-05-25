<?php

namespace oihana\arango\commands\options;

trait ArangoCommonOption
{
    public const string ALL_DATABASES                        = 'allDatabases';
    public const string BATCH_SIZE                           = 'batchSize';
    public const string CHECK_CONFIGURATION                  = 'checkConfiguration';
    public const string COLLECTION                           = 'collection';
    public const string COMPRESS_REQUEST_THRESHOLD           = 'compressRequestThreshold';
    public const string COMPRESS_TRANSFER                    = 'compressTransfer';
    public const string CONFIG                               = 'config';
    public const string CONFIGURATION                        = 'configuration';
    public const string DEFINE                               = 'define';
    public const string DESCRIPTORS_MINIMUM                  = 'descriptorsMinimum';
    public const string DUMP_DEPENDENCIES                    = 'dumpDependencies';
    public const string DUMP_OPTIONS                         = 'dumpOptions';
    public const string FORCE                                = 'force';
    public const string HONOR_NSSWITCH                       = 'honorNsswitch';
    public const string IGNORE_DISTRIBUTE_SHARDS_LIKE_ERRORS = 'ignoreDistributeShardsLikeErrors';
    public const string LOG                                  = 'log';
    public const string OVERWRITE                            = 'overwrite';
    public const string PROGRESS                             = 'progress';
    public const string THREADS                              = 'threads';
    public const string USE_SPLICE_SYSCALL                   = 'useSpliceSyscall';
    public const string VERSION                              = 'version';
    public const string VERSION_JSON                         = 'versionJson';

    // === Encryption ===

    public const string ENCRYPTION_KEY_GENERATOR = 'encryptionKeyGenerator';
    public const string ENCRYPTION_KEYFILE       = 'encryptionKeyfile';

    // === Log ===

    public const string LOG_COLOR                 = 'logColor';
    public const string LOG_ESCAPE_CONTROL_CHARS  = 'logEscapeControlChars';
    public const string LOG_ESCAPE_UNICODE_CHARS  = 'logEscapeUnicodeChars';
    public const string LOG_FILE                  = 'logFile';
    public const string LOG_FILE_GROUP            = 'logFileGroup';
    public const string LOG_FILE_MODE             = 'logFileMode';
    public const string LOG_FORCE_DIRECT          = 'logForceDirect';
    public const string LOG_FOREGROUND_TTY        = 'logForegroundTty';
    public const string LOG_HOSTNAME              = 'logHostname';
    public const string LOG_IDS                   = 'logIds';
    public const string LOG_LEVEL                 = 'logLevel';
    public const string LOG_LINE_NUMBER           = 'logLineNumber';
    public const string LOG_MAX_ENTRY_LENGTH      = 'logMaxEntryLength';
    public const string LOG_MAX_QUEUED_ENTRIES    = 'logMaxQueuedEntries';
    public const string LOG_OUTPUT                = 'logOutput';
    public const string LOG_PERFORMANCE           = 'logPerformance';
    public const string LOG_PREFIX                = 'logPrefix';
    public const string LOG_PROCESS               = 'logProcess';
    public const string LOG_REQUEST_PARAMETERS    = 'logRequestParameters';
    public const string LOG_ROLE                  = 'logRole';
    public const string LOG_SHORTEN_FILENAMES     = 'logShortenFilenames';
    public const string LOG_STRUCTURED_PARAM      = 'logStructuredParam';
    public const string LOG_THREAD                = 'logThread';
    public const string LOG_THREAD_NAME           = 'logThreadName';
    public const string LOG_TIME_FORMAT           = 'logTimeFormat';
    public const string LOG_USE_JSON_FORMAT       = 'logUseJsonFormat';
    public const string LOG_USE_LOCAL_TIME        = 'logUseLocalTime';
    public const string LOG_USE_MICROTIME         = 'logUseMicrotime';

    // === Random ===
    public const string RANDOM_GENERATOR  = 'randomGenerator';

    // === Server ===
    public const string SERVER_ASK_JWT_SECRET       = 'serverAskJwtSecret';
    public const string SERVER_AUTHENTICATION       = 'serverAuthentication';
    public const string SERVER_CONNECTION_TIMEOUT   = 'serverConnectionTimeout';
    public const string SERVER_DATABASE             = 'serverDatabase';
    public const string SERVER_ENDPOINT             = 'serverEndpoint';
    public const string SERVER_JWT_SECRET_KEYFILE   = 'serverJwtSecretKeyfile';
    public const string SERVER_MAX_PACKET_SIZE      = 'serverMaxPacketSize';
    public const string SERVER_PASSWORD             = 'serverPassword';
    public const string SERVER_REQUEST_TIMEOUT      = 'serverRequestTimeout';
    public const string SERVER_USERNAME             = 'serverUsername';

    // === SSL ===
    public const string SSL_PROTOCOL = 'sslProtocol';

    /**
     * Returns true if the option is register in a group.
     * @param string $option
     * @param array $more
     * @return bool
     */
    public static function hasGroup( string $option , array $more = [] ):bool
    {
        if( in_array( $option , $more )  )
        {
            return true ;
        }

        return match( $option )
        {
            // === Encryption ===
            static::ENCRYPTION_KEY_GENERATOR    ,
            static::ENCRYPTION_KEYFILE          ,
                // === Log ===
            static::LOG_COLOR                   ,
            static::LOG_ESCAPE_CONTROL_CHARS    ,
            static::LOG_ESCAPE_UNICODE_CHARS    ,
            static::LOG_FILE                    ,
            static::LOG_FILE_GROUP              ,
            static::LOG_FILE_MODE               ,
            static::LOG_FORCE_DIRECT            ,
            static::LOG_FOREGROUND_TTY          ,
            static::LOG_HOSTNAME                ,
            static::LOG_IDS                     ,
            static::LOG_LEVEL                   ,
            static::LOG_LINE_NUMBER             ,
            static::LOG_MAX_ENTRY_LENGTH        ,
            static::LOG_MAX_QUEUED_ENTRIES      ,
            static::LOG_OUTPUT                  ,
            static::LOG_PERFORMANCE             ,
            static::LOG_PREFIX                  ,
            static::LOG_PROCESS                 ,
            static::LOG_REQUEST_PARAMETERS      ,
            static::LOG_ROLE                    ,
            static::LOG_SHORTEN_FILENAMES       ,
            static::LOG_STRUCTURED_PARAM        ,
            static::LOG_THREAD                  ,
            static::LOG_THREAD_NAME             ,
            static::LOG_TIME_FORMAT             ,
            static::LOG_USE_JSON_FORMAT         ,
            static::LOG_USE_LOCAL_TIME          ,
            static::LOG_USE_MICROTIME           ,
                // === Random ===
            static::RANDOM_GENERATOR            ,
                // === Server ===
            static::SERVER_ASK_JWT_SECRET       ,
            static::SERVER_AUTHENTICATION       ,
            static::SERVER_CONNECTION_TIMEOUT   ,
            static::SERVER_DATABASE             ,
            static::SERVER_ENDPOINT             ,
            static::SERVER_JWT_SECRET_KEYFILE   ,
            static::SERVER_MAX_PACKET_SIZE      ,
            static::SERVER_PASSWORD             ,
            static::SERVER_REQUEST_TIMEOUT      ,
            static::SERVER_USERNAME             ,
                // === SSL ===
            static::SSL_PROTOCOL                => true ,
            default                             => false
        };
    }

}