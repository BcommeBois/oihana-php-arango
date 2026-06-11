<?php

namespace oihana\arango\commands\options;

use oihana\arango\db\enums\traits\ArangoConfigTrait;

class ArangoCommandOption
{
    use ArangoConfigTrait ;

    public const string ALL               = 'all' ;
    public const string COLLECTION        = 'collection' ;
    public const string DATE              = 'date' ;
    public const string DIFF              = 'diff' ;
    public const string DIRECTORY         = 'directory' ;
    public const string DROP              = 'drop' ;
    public const string FILE              = 'file' ;
    public const string IGNORE_COLLECTION = 'ignore-collection' ;
    public const string LABEL             = 'label' ;
    public const string LAST              = 'last' ;
    public const string LIST              = 'list' ;
    public const string SYNC              = 'sync' ;
    public const string SYSTEM            = 'system' ;

}