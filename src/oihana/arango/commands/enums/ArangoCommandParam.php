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
     * The 'migrationsCollection' parameter — the tracking collection name
     * of the `migrate` action (default `migrations`).
     */
    public const string MIGRATIONS_COLLECTION = 'migrationsCollection' ;

    /**
     * The 'migrationsNamespace' parameter — the PHP namespace the version
     * classes of the `migrate` action are declared in.
     */
    public const string MIGRATIONS_NAMESPACE = 'migrationsNamespace' ;

    /**
     * The 'migrationsPath' parameter — the directory holding the
     * `Version*.php` files of the `migrate` action.
     */
    public const string MIGRATIONS_PATH = 'migrationsPath' ;

    /**
     * The 'models' parameter — the container ids of the `Documents`
     * models whose View declarations the `views` action inspects.
     */
    public const string MODELS = 'models' ;

    /**
     * The 'timezone' parameter.
     */
    public const string TIMEZONE = 'timezone' ;
}