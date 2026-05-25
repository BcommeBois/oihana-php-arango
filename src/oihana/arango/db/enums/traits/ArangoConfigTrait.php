<?php

namespace oihana\arango\db\enums\traits;

use oihana\reflect\traits\ConstantsTrait;

trait ArangoConfigTrait
{
    use ConstantsTrait ;

    public const string BATCH_SIZE  = 'batchSize' ;
    public const string CONNECTION  = 'connection' ;
    public const string CREATE      = 'create' ;
    public const string DATABASE    = 'database' ;
    public const string DEBUG       = 'debug' ;
    public const string ENDPOINT    = 'endpoint' ;
    public const string ENCRYPT     = 'encrypt' ;
    public const string LAZY        = 'lazy' ;
    public const string MAX_RUNTIME = 'maxRuntime' ;
    public const string PASSWORD    = 'password' ;
    public const string RECONNECT   = 'reconnect' ;
    public const string TIMEOUT     = 'timeout' ;
    public const string TYPE        = 'type' ;
    public const string USER        = 'user' ;
}