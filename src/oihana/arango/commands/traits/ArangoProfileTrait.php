<?php

namespace oihana\arango\commands\traits;

use RuntimeException;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\db\enums\ArangoConfig;

use oihana\files\enums\FileExtension;

use function oihana\files\path\isAbsolutePath;
use function oihana\files\toml\resolveTomlConfig;

/**
 * Resolves the **named** and **external** dump/restore *profiles*.
 *
 * A profile is a reusable, named bundle that describes *what* to extract — and
 * optionally *from where* (the dump source). It carries:
 *
 *  - `collections` / `edges` — the positive selection (merged into one list) ;
 *  - `exclude` — names removed from the resolved set (set subtraction) ;
 *  - `endpoint` / `database` / `user` / `password` — an optional **source**
 *    connection, used by the `dump` action only (the `restore` always writes to
 *    the local target — it borrows the selection, never the connection).
 *
 * A profile lives either in the `[arango.profiles.<name>]` section of the
 * project `config.toml` (referenced by name) or in a standalone `.toml` file
 * (referenced by path, absolute or relative). Both parse to the same flat array.
 *
 * Selection rules:
 *  - a positive list (`collections` + `edges`) minus `exclude` ;
 *  - an `exclude`-only profile means "everything minus `exclude`" — the
 *    universe (server collections for `dump`, archive collections for
 *    `restore`) is supplied by the caller.
 */
trait ArangoProfileTrait
{
    /**
     * The declared named profiles, from the `[arango.profiles]` config section.
     * @var array
     */
    protected array $profiles = [] ;

    /**
     * Captures the `[arango.profiles]` config section from the init array.
     * @param array $init
     * @return static
     */
    public function initializeArangoProfiles( array $init = [] ) :static
    {
        $profiles = $init[ ArangoCommandParam::PROFILES ] ?? null ;
        if( is_array( $profiles ) )
        {
            $this->profiles = $profiles ;
        }
        return $this ;
    }

    /**
     * The optional **source** connection carried by a profile (dump only).
     * @param array $profile
     * @return array The present connection keys among endpoint/database/user/password.
     */
    public function profileConnection( array $profile ) :array
    {
        $connection = [] ;
        foreach ( [ ArangoConfig::ENDPOINT , ArangoConfig::DATABASE , ArangoConfig::USER , ArangoConfig::PASSWORD ] as $key )
        {
            if( isset( $profile[ $key ] ) && is_string( $profile[ $key ] ) )
            {
                $connection[ $key ] = $profile[ $key ] ;
            }
        }
        return $connection ;
    }

    /**
     * The `exclude` list of a profile.
     * @param array $profile
     * @return array
     */
    public function profileExclude( array $profile ) :array
    {
        return $this->normalizeProfileList( $profile[ ArangoCommandParam::PROFILE_EXCLUDE ] ?? [] ) ;
    }

    /**
     * The positive selection of a profile — `collections` + `edges` merged.
     * @param array $profile
     * @return array
     */
    public function profilePositive( array $profile ) :array
    {
        return array_values( array_unique( array_merge
        (
            $this->normalizeProfileList( $profile[ ArangoCommandParam::PROFILE_COLLECTIONS ] ?? [] ) ,
            $this->normalizeProfileList( $profile[ ArangoCommandParam::PROFILE_EDGES       ] ?? [] ) ,
        ) ) ) ;
    }

    /**
     * The effective collection list of a profile.
     *
     * A positive selection minus `exclude`; when the positive list is empty,
     * `$allCollections` (the universe) minus `exclude`.
     *
     * @param array $profile
     * @param array $allCollections The universe used for an exclude-only profile.
     * @return array
     */
    public function profileSelection( array $profile , array $allCollections = [] ) :array
    {
        $positive = $this->profilePositive( $profile ) ;
        $base     = $positive !== [] ? $positive : array_values( $allCollections ) ;
        return array_values( array_diff( $base , $this->profileExclude( $profile ) ) ) ;
    }

    /**
     * Resolves the `--profile` value to a profile array.
     *
     * A path-like value (absolute, containing a separator, or ending in
     * `.toml`) is loaded as an external file; otherwise it is looked up among
     * the declared named profiles.
     *
     * @param string|null $profile
     * @return array|null Null when no profile is requested.
     */
    public function resolveProfile( ?string $profile ) :?array
    {
        if( $profile === null || $profile === '' )
        {
            return null ;
        }

        if( $this->looksLikePath( $profile ) )
        {
            $base   = getcwd() ;
            $config = resolveTomlConfig( $profile , [] , $base === false ? null : $base ) ;
            return is_array( $config ) ? $config : [] ;
        }

        if( !isset( $this->profiles[ $profile ] ) || !is_array( $this->profiles[ $profile ] ) )
        {
            throw new RuntimeException
            (
                sprintf
                (
                    "Unknown dump/restore profile '%s'. Declared profiles: %s." ,
                    $profile ,
                    implode( ', ' , array_keys( $this->profiles ) ) ?: '(none)'
                )
            ) ;
        }

        return $this->profiles[ $profile ] ;
    }

    /**
     * True when a `--profile` value designates an external `.toml` file rather
     * than a named section.
     * @param string $value
     * @return bool
     */
    private function looksLikePath( string $value ) :bool
    {
        return isAbsolutePath( $value )
            || str_contains( $value , '/' )
            || str_contains( $value , '\\' )
            || str_ends_with( $value , FileExtension::TOML ) ;
    }

    /**
     * Flattens a profile list value: arrays and comma-separated strings → a
     * clean, de-duplicated list of trimmed names.
     * @param mixed $raw
     * @return array
     */
    private function normalizeProfileList( mixed $raw ) :array
    {
        $out = [] ;
        foreach ( (array) $raw as $value )
        {
            foreach ( explode( ',' , (string) $value ) as $name )
            {
                $name = trim( $name ) ;
                if( $name !== '' )
                {
                    $out[] = $name ;
                }
            }
        }
        return array_values( array_unique( $out ) ) ;
    }
}
