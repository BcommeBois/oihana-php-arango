<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The command to manage an ArangoDB database.
 */
class ArangoCommandParam
{
    use ConstantsTrait ;

    /**
     * The 'dateFormat' parameter.
     */
    public const string DATE_FORMAT = 'dateFormat' ;

    /**
     * The 'directory' parameter.
     */
    public const string DIRECTORY = 'directory' ;

    /**
     * The 'timezone' parameter.
     */
    public const string TIMEZONE = 'timezone' ;
}