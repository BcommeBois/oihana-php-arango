<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The enumeration of actions in the DocumentsCommand.
 *
 * @package oihana\robots\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
class DocumentsCommandAction
{
    use ConstantsTrait ;

    /**
     * The 'count' action.
     */
    public const string COUNT = 'count' ;

    /**
     * The 'delete' action.
     */
    public const string DELETE = 'delete' ;

    /**
     * The 'exist' action.
     */
    public const string EXIST = 'exist' ;

    /**
     * The 'get' action.
     */
    public const string GET = 'get' ;

    /**
     * The 'harvest' action.
     */
    public const string HARVEST = 'harvest' ;

    /**
     * The 'insert' action.
     */
    public const string INSERT = 'insert' ;

    /**
     * The 'list' action.
     */
    public const string LIST = 'list' ;

    /**
     * The 'replace' action.
     */
    public const string REPLACE = 'replace' ;

    /**
     * The 'repsert' action.
     */
    public const string REPSERT = 'repsert' ;

    /**
     * The 'truncate' action.
     */
    public const string TRUNCATE = 'truncate' ;

    /**
     * The 'update' action.
     */
    public const string UPDATE = 'update' ;

    /**
     * The 'upsert' action.
     */
    public const string UPSERT = 'upsert' ;
}