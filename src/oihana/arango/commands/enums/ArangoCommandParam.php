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
     * The 'dump' parameter — the `[arango.dump]` config section (option
     * defaults applied to the `dump` action, overridable on the CLI).
     */
    public const string DUMP = 'dump' ;

    /**
     * The 'masking' key — the convenient masking table of a profile
     * (`[arango.profiles.<name>.masking]`) or of the dump defaults
     * (`[arango.dump.masking]`), compiled to a native `arangodump` maskings
     * file. Dump-only.
     */
    public const string MASKING = 'masking' ;

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
     * The 'profiles' parameter — the `[arango.profiles]` config section (named
     * dump/restore selection profiles, keyed by name).
     */
    public const string PROFILES = 'profiles' ;

    /**
     * The 'collections' key of a profile — the positive document-collection selection.
     */
    public const string PROFILE_COLLECTIONS = 'collections' ;

    /**
     * The 'edges' key of a profile — the positive edge-collection selection
     * (merged with the collections into a single list).
     */
    public const string PROFILE_EDGES = 'edges' ;

    /**
     * The 'exclude' key of a profile — names removed from the resolved selection.
     */
    public const string PROFILE_EXCLUDE = 'exclude' ;

    /**
     * The 'protected' key of the `[arango.restore]` config section — the list
     * of collection names the `restore` action refuses to overwrite unless
     * `--force` is passed (a deployment-level safety policy, never an option of
     * the `arangorestore` binary).
     */
    public const string PROTECTED = 'protected' ;

    /**
     * The 'restore' parameter — the `[arango.restore]` config section (option
     * defaults applied to the `restore` action, overridable on the CLI).
     */
    public const string RESTORE = 'restore' ;

    /**
     * The 'timezone' parameter.
     */
    public const string TIMEZONE = 'timezone' ;
}