<?php

namespace oihana\arango\commands\traits;

use InvalidArgumentException;

use oihana\enums\Char;

/**
 * Pure, side-effect-free helpers shared by the `command:arangodb`
 * dump / restore / collections actions.
 *
 * Everything here is deterministic and server-free so it can be unit
 * tested in isolation:
 *
 * - {@see normalizeCollections()} turns the raw Symfony `VALUE_IS_ARRAY`
 *   input into a clean collection-name list, accepting both repeated
 *   flags (`--collection a --collection b`) and comma-separated values
 *   (`--collection=a,b`), or any mix of the two.
 * - {@see assertCollectionTargeting()} guards the mutually-exclusive
 *   `--collection` / `--ignore-collection` pair (an `arangodump`
 *   constraint).
 * - {@see missingCollections()} diffs a requested set against the set of
 *   collections actually present in the database (validation before a
 *   targeted dump).
 * - {@see sanitizeLabel()} validates the optional `--label` suffix so it
 *   stays filesystem-safe.
 * - {@see getArchiveNameSuffix()} builds the archive name suffix
 *   (`-{database}[-partial][-{label}]`, without file extension) shared by
 *   the dump output name and the restore-by-date lookup.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait ArangoCollectionsTrait
{
    /**
     * Marker appended to the archive name when the dump targets a subset
     * of collections (either `--collection` or `--ignore-collection`).
     */
    public const string PARTIAL_MARKER = 'partial' ;

    /**
     * Builds the archive name suffix (without file extension) shared by
     * the dump output name and the restore-by-date lookup.
     *
     * Shape: `-{database}[-partial][-{label}]`, e.g.
     * - `-mydb`                         (full dump)
     * - `-mydb-partial`                 (targeted dump, no label)
     * - `-mydb-partial-pre-migration`   (targeted dump with a label)
     *
     * @param string      $database The database name.
     * @param bool        $partial  Whether the dump targets a subset of collections.
     * @param string|null $label    Optional label (validated via {@see sanitizeLabel()}).
     * @return string
     * @throws InvalidArgumentException When the label is invalid.
     */
    protected static function getArchiveNameSuffix( string $database , bool $partial = false , ?string $label = null ) :string
    {
        $suffix = Char::DASH . $database ;

        if ( $partial )
        {
            $suffix .= Char::DASH . self::PARTIAL_MARKER ;
        }

        $label = static::sanitizeLabel( $label ) ;
        if ( $label !== null )
        {
            $suffix .= Char::DASH . $label ;
        }

        return $suffix ;
    }

    /**
     * Asserts that `--collection` and `--ignore-collection` are not used
     * together — `arangodump` rejects that combination.
     *
     * @param array<int, string> $collection
     * @param array<int, string> $ignore
     * @return void
     * @throws InvalidArgumentException When both lists are non-empty.
     */
    protected static function assertCollectionTargeting( array $collection , array $ignore ) :void
    {
        if ( $collection !== [] && $ignore !== [] )
        {
            throw new InvalidArgumentException
            (
                'The --collection and --ignore-collection options cannot be used together.'
            ) ;
        }
    }

    /**
     * Returns the available collection names minus the excluded ones,
     * order-preserving (case-sensitive).
     *
     * Used to resolve `--ignore-collection` client-side: `arangodump` has
     * no exclusion option, so the complement is computed here and passed
     * back as an explicit `--collection` list.
     *
     * @param array<int, string> $available The collections that exist.
     * @param array<int, string> $exclude   The collections to drop.
     * @return array<int, string>
     */
    protected static function excludeCollections( array $available , array $exclude ) :array
    {
        return array_values
        (
            array_filter( $available , fn( $name ) => !in_array( $name , $exclude , true ) )
        ) ;
    }

    /**
     * Returns true when the given name is an ArangoDB system collection
     * (its name starts with an underscore, e.g. `_jobs`, `_apps`).
     *
     * @param string $name
     * @return bool
     */
    protected static function isSystemCollection( string $name ) :bool
    {
        return str_starts_with( $name , '_' ) ;
    }

    /**
     * Returns the requested collection names that are NOT present in the
     * available set (case-sensitive, as ArangoDB collection names are).
     *
     * Order-preserving and de-duplicated.
     *
     * @param array<int, string> $requested The collections asked for on the CLI.
     * @param array<int, string> $available The collections that actually exist.
     * @return array<int, string> The unknown collection names (empty when all exist).
     */
    protected static function missingCollections( array $requested , array $available ) :array
    {
        $missing = [] ;
        foreach ( $requested as $name )
        {
            if ( !in_array( $name , $available , true ) )
            {
                $missing[ $name ] = true ;
            }
        }
        return array_keys( $missing ) ;
    }

    /**
     * Normalizes a raw collection option into a clean, de-duplicated,
     * order-preserving list of collection names.
     *
     * Accepts both syntaxes (and any mix):
     * - repeated flags    : `['users', 'products']`
     * - comma-separated   : `['users,products']`
     * - mixed/with spaces  : `[' users , products ', 'customers']`
     *
     * Empty fragments are dropped; the first occurrence order is kept.
     *
     * @param array<int|string, mixed> $raw
     * @return array<int, string>
     */
    protected static function normalizeCollections( array $raw ) :array
    {
        $names = [] ;
        foreach ( $raw as $entry )
        {
            foreach ( explode( Char::COMMA , (string) $entry ) as $name )
            {
                $name = trim( $name ) ;
                if ( $name !== Char::EMPTY )
                {
                    $names[ $name ] = true ; // de-duplicate, keep first-seen order
                }
            }
        }
        return array_keys( $names ) ;
    }

    /**
     * Validates and normalizes the optional archive label.
     *
     * Returns null for a null/empty value. Otherwise the label must only
     * contain letters, digits, dot, underscore and hyphen so it stays
     * safe inside a filename.
     *
     * @param string|null $label
     * @return string|null
     * @throws InvalidArgumentException When the label contains unsafe characters.
     */
    protected static function sanitizeLabel( ?string $label ) :?string
    {
        if ( $label === null )
        {
            return null ;
        }

        $label = trim( $label ) ;
        if ( $label === Char::EMPTY )
        {
            return null ;
        }

        if ( preg_match( '/^[A-Za-z0-9._-]+$/' , $label ) !== 1 )
        {
            throw new InvalidArgumentException
            (
                sprintf
                (
                    'Invalid label "%s": only letters, digits, dot (.), underscore (_) and hyphen (-) are allowed.' ,
                    $label
                )
            ) ;
        }

        return $label ;
    }
}
