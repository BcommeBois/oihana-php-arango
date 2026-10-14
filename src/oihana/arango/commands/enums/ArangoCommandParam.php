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
     * The 'analyzers' parameter — the database-level registry of declared
     * **custom** analyzers reconciled by the `arango:analyzers` action (and
     * signalled by the `doctor`): a list of
     * {@see \oihana\arango\db\options\analyzers\AnalyzerDefinition} (a single
     * one is tolerated in place of a one-element list). Analyzers are shared
     * and database-scoped, so they are declared once here rather than per model.
     */
    public const string ANALYZERS = 'analyzers' ;

    /**
     * The 'collectionIndexes' parameter — the collection→indexes registry
     * reconciled by the `doctor` action, declared independently of the models:
     * a map `collectionName => IndexOptions[]` (a single `IndexOptions` is
     * tolerated in place of a one-element list). Lets a collection's indexes be
     * declared once, in one place, even when several models back it (or none
     * declares `AQL::INDEXES`).
     */
    public const string COLLECTION_INDEXES = 'collectionIndexes' ;

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
     * The 'preserves' parameter — the `[arango.preserves]` config section : the
     * rule naming, collection by collection, the local documents the `preserve`
     * action sets aside and the `reinject` action puts back (keyed by collection).
     */
    public const string PRESERVES = 'preserves' ;

    /**
     * The 'filter' key of a `[arango.preserves.<collection>]` rule — the documents
     * to preserve, written in the `?filter=` grammar (a JSON string).
     */
    public const string PRESERVE_FILTER = 'filter' ;

    /**
     * The 'filters' key of a `[arango.preserves.<collection>]` rule — the keys the
     * filter may name and their {@see \oihana\arango\models\enums\filters\FilterType},
     * the counterpart of `AQL::FILTERS` on a model.
     */
    public const string PRESERVE_FILTERS = 'filters' ;

    /**
     * The 'maxPercent' key of a `[arango.preserves.<collection>]` rule — the largest
     * share of the collection, an integer percent from 1 to 100, the rule may name
     * when `reinject` runs (50 by default ; 100 turns the guard off).
     */
    public const string PRESERVE_MAX_PERCENT = 'maxPercent' ;

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
     * The 'retention' key of the `[arango.dump]` config section — the archive
     * rotation policy ({@see RetentionOption}: `keep` / `max_age` / `max_total`
     * / `auto` / `buckets`) of the `dump` action.
     */
    public const string RETENTION = 'retention' ;

    /**
     * The 'searchAliasViews' init key — the database-level registry of declared
     * `search-alias` views ({@see \oihana\arango\db\options\views\SearchAliasView}),
     * consumed by the `views` action.
     */
    public const string SEARCH_ALIAS_VIEWS = 'searchAliasViews' ;

    /**
     * The 'timezone' parameter.
     */
    public const string TIMEZONE = 'timezone' ;
}