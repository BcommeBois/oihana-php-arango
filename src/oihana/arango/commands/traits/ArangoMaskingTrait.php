<?php

namespace oihana\arango\commands\traits;

use InvalidArgumentException;
use Random\RandomException;

use oihana\arango\maskings\enums\Masker;
use oihana\arango\maskings\enums\MaskingMode;

use function oihana\arango\maskings\maskDocument;

/**
 * Compiles the **convenient** masking table and applies it to a dump with the
 * portable PHP masking engine (dump-only — the dump itself is anonymized).
 *
 * The convenient table uses flat, dotted keys:
 *
 * ```toml
 * [arango.profiles.test-local.masking]
 * "*"             = "structure"                              # collection mode (default for all)
 * "users"         = "masked"                                 # collection mode
 * "users.email"   = "email"                                  # attribute rule (simple masker)
 * "users.card"    = { type = "xifyFront", unmaskedLength = 4 } # attribute rule (parameterized)
 * ```
 *
 * Compilation rules:
 *  - a key **without a dot** is a collection name (or `*`, the default for all
 *    collections); its value is a document-level **mode**
 *    ({@see MaskingMode}: `exclude` / `structure` / `masked` / `full`) ;
 *  - a key **with a dot** is `<collection>.<path>` (the first segment is the
 *    collection, the rest is the attribute path); its value is a masker name
 *    ({@see Masker}) or an inline table carrying `type` plus the masker
 *    parameters. The collection mode defaults to `masked`.
 *
 * The compiled structure is, per collection:
 * `{ "type": <mode>, "maskings": [ { "path": <path>, "type": <masker>, … } ] }`.
 *
 * It is then applied to the `*.data.json` files of a dump by the portable engine
 * ({@see oihana\arango\maskings\maskDocument()}) — which works on **any**
 * ArangoDB edition (the native `--maskings` file is Enterprise-only). The PHP
 * engine masks `masked` collections only; selecting/excluding collections is the
 * job of `--collection` / the profile selection.
 */
trait ArangoMaskingTrait
{
    /**
     * Compiles the convenient masking table into the masking structure
     * (collection name => `{ type, maskings[] }`).
     *
     * @param array $table The convenient `[…masking]` table.
     * @return array<string,array{type:string,maskings:array<int,array<string,mixed>>}>
     * @throws InvalidArgumentException When a mode or masker is unknown, or a key is malformed.
     */
    public function compileMaskings( array $table ) :array
    {
        $result = [] ;

        foreach( $table as $key => $value )
        {
            $key = (string) $key ;
            $dot = strpos( $key , '.' ) ;

            if( $dot === false )
            {
                if( !is_string( $value ) || !MaskingMode::includes( $value , true ) )
                {
                    throw new InvalidArgumentException
                    (
                        sprintf
                        (
                            "Invalid masking mode for '%s': expected one of %s." ,
                            $key ,
                            implode( ', ' , MaskingMode::getConstantValues() )
                        )
                    ) ;
                }

                $result[ $key ] ??= [ 'type' => MaskingMode::MASKED , 'maskings' => [] ] ;
                $result[ $key ][ 'type' ] = $value ;

                continue ;
            }

            $collection = substr( $key , 0 , $dot ) ;
            $path       = substr( $key , $dot + 1 ) ;

            if( $collection === '' || $path === '' )
            {
                throw new InvalidArgumentException( sprintf( "Malformed masking key '%s': expected '<collection>.<path>'." , $key ) ) ;
            }

            $result[ $collection ] ??= [ 'type' => MaskingMode::MASKED , 'maskings' => [] ] ;
            $result[ $collection ][ 'maskings' ][] = $this->maskingRule( $path , $value ) ;
        }

        return $result ;
    }

    /**
     * Applies the compiled masking to the `*.data.json` files of a dump, in place.
     *
     * Each data file is paired with its `*.structure.json` to resolve the
     * collection name, then matched against the compiled rules (an explicit
     * entry, otherwise the `*` default). Only `masked` collections are processed
     * — a non-`masked` mode raises, since collection selection/exclusion is done
     * by `--collection` / the profile, not by the engine. Files are expected
     * uncompressed (the dump action forces `compressOutput = false`).
     *
     * @param string $directory The dump output directory holding the `*.data.json` files.
     * @param array  $compiled  The compiled masking structure ({@see compileMaskings()}).
     * @return int The number of data files masked.
     * @throws InvalidArgumentException When a targeted collection declares a non-`masked` mode.
     * @throws RandomException
     */
    public function maskDumpDirectory( string $directory , array $compiled ) :int
    {
        if( $compiled === [] )
        {
            return 0 ;
        }

        $masked = 0 ;
        foreach( glob( $directory . DIRECTORY_SEPARATOR . '*.data.json' ) ?: [] as $dataFile )
        {
            $collection = $this->dumpCollectionName( $dataFile ) ;
            $entry      = $compiled[ $collection ] ?? $compiled[ '*' ] ?? null ;

            if( $entry === null )
            {
                continue ;
            }

            if( ( $entry[ 'type' ] ?? MaskingMode::MASKED ) !== MaskingMode::MASKED )
            {
                throw new InvalidArgumentException
                (
                    sprintf
                    (
                        "Masking mode '%s' is not supported by the PHP masking engine (only '%s'); use --collection / the profile selection to exclude collections." ,
                        $entry[ 'type' ] ,
                        MaskingMode::MASKED
                    )
                ) ;
            }

            $maskings = $entry[ 'maskings' ] ?? [] ;
            if( $maskings === [] )
            {
                continue ;
            }

            $lines = [] ;
            foreach( file( $dataFile , FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [] as $line )
            {
                $document = json_decode( $line , true ) ;
                $lines[]  = is_array( $document )
                          ? json_encode( maskDocument( $document , $maskings ) , JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
                          : $line ;
            }

            file_put_contents( $dataFile , $lines === [] ? '' : implode( "\n" , $lines ) . "\n" ) ;
            $masked++ ;
        }

        return $masked ;
    }

    /**
     * Resolves the collection name a `*.data.json` file belongs to, by reading
     * the `parameters.name` of its paired `*.structure.json` (falling back to
     * the file-name prefix before the dump hash).
     *
     * @param string $dataFile The path to a `<name>_<hash>.data.json` file.
     * @return string
     */
    private function dumpCollectionName( string $dataFile ) :string
    {
        $base          = substr( $dataFile , 0 , -strlen( '.data.json' ) ) ;
        $structureFile = $base . '.structure.json' ;

        if( is_file( $structureFile ) )
        {
            $data = json_decode( (string) file_get_contents( $structureFile ) , true ) ;
            $name = is_array( $data ) ? ( $data[ 'parameters' ][ 'name' ] ?? null ) : null ;
            if( is_string( $name ) && $name !== '' )
            {
                return $name ;
            }
        }

        // Fallback: "<name>_<hash>" → drop the trailing "_<hash>".
        $stem = basename( $base ) ;
        $cut  = strrpos( $stem , '_' ) ;
        return $cut === false ? $stem : substr( $stem , 0 , $cut ) ;
    }

    /**
     * Builds — and validates — a single attribute masking rule from a path and
     * its value (a masker name string, or an inline table carrying `type`).
     *
     * @param string $path
     * @param mixed  $value
     * @return array<string,mixed> `{ path, type, …params }`
     * @throws InvalidArgumentException When the masker is unknown or the value malformed.
     */
    private function maskingRule( string $path , mixed $value ) :array
    {
        if( is_string( $value ) )
        {
            $type = $value ;
            $rule = [ 'path' => $path , 'type' => $value ] ;
        }
        elseif( is_array( $value ) )
        {
            $type = $value[ 'type' ] ?? null ;
            $rule = array_merge( [ 'path' => $path ] , $value ) ;
        }
        else
        {
            throw new InvalidArgumentException( sprintf( "Invalid masking rule for path '%s': expected a masker name or an inline table." , $path ) ) ;
        }

        if( !is_string( $type ) || !Masker::includes( $type , true ) )
        {
            throw new InvalidArgumentException
            (
                sprintf
                (
                    "Invalid masking function for path '%s': expected one of %s." ,
                    $path ,
                    implode( ', ' , Masker::getConstantValues() )
                )
            ) ;
        }

        return $rule ;
    }
}
