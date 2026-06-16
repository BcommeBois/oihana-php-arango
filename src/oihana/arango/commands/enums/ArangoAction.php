<?php

namespace oihana\arango\commands\enums;

use oihana\reflect\traits\ConstantsTrait;

/**
 * The command to manage an ArangoDB database.
 */
class ArangoAction
{
    use ConstantsTrait ;

    /**
     * Manage the custom ArangoSearch analyzers of the arangodb database
     * (list, diff, sync).
     */
    public const string ANALYZERS = 'analyzers' ;

    /**
     * Backup the arangodb database.
     */
    public const string BACKUP = 'backup' ;

    /**
     * List the collections of the arangodb database.
     */
    public const string COLLECTIONS = 'collections' ;

    /**
     * The default action.
     */
    public const string DEFAULT = 'default' ;

    /**
     * Diagnose / repair the declared structure of the arangodb database
     * (collections, indexes, views, orphans).
     */
    public const string DOCTOR = 'doctor' ;

    /**
     * Dump the arangodb database.
     */
    public const string DUMP = 'dump' ;

    /**
     * List the arangodb dumps.
     */
    public const string LIST_DUMPS = 'listDumps' ;

    /**
     * Apply / rollback the versioned data migrations of the arangodb database.
     */
    public const string MIGRATE = 'migrate' ;

    /**
     * Retore the arangodb database.
     */
    public const string RESTORE = 'restore' ;

    /**
     * Manage the ArangoSearch views of the arangodb database
     * (list, diff, sync, drop).
     */
    public const string VIEWS = 'views' ;
}