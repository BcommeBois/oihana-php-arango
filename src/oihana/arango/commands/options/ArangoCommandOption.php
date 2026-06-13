<?php

namespace oihana\arango\commands\options;

use oihana\arango\db\enums\traits\ArangoConfigTrait;

class ArangoCommandOption
{
    use ArangoConfigTrait ;

    public const string ALL               = 'all' ;
    public const string ALL_DATABASES     = 'all-databases' ;
    public const string APPLY             = 'apply' ;
    public const string COLLECTION        = 'collection' ;
    public const string COMPLETE          = 'complete' ;
    public const string CREATE            = 'create' ;
    public const string DATE              = 'date' ;
    public const string DIFF              = 'diff' ;
    public const string DIRECTORY         = 'directory' ;
    public const string DOWN              = 'down' ;
    public const string DROP              = 'drop' ;
    public const string DRY_RUN           = 'dry-run' ;
    public const string FILE              = 'file' ;
    public const string FORCE             = 'force' ;
    public const string FORGET            = 'forget' ;
    public const string IGNORE_COLLECTION = 'ignore-collection' ;
    public const string INCLUDE_SYSTEM    = 'include-system' ;
    public const string LABEL             = 'label' ;
    public const string LAST              = 'last' ;
    public const string LIST              = 'list' ;
    public const string NO_VIEWS          = 'no-views' ;
    public const string OVERWRITE         = 'overwrite' ;
    public const string PROFILE           = 'profile' ;
    public const string PRUNE             = 'prune' ;
    public const string STATUS            = 'status' ;
    public const string SYNC              = 'sync' ;
    public const string SYSTEM            = 'system' ;
    public const string THREADS           = 'threads' ;
    public const string VIEW              = 'view' ;
    public const string YES               = 'yes' ;

}