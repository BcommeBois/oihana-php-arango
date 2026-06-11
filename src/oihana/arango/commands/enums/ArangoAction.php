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
     * Retore the arangodb database.
     */
    public const string RESTORE = 'restore' ;

    /**
     * Manage the ArangoSearch views of the arangodb database
     * (list, diff, sync, drop).
     */
    public const string VIEWS = 'views' ;
}