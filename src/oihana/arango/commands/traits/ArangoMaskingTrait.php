<?php

namespace oihana\arango\commands\traits;

use InvalidArgumentException;

use function oihana\files\makeTemporaryDirectory;

/**
 * Compiles the **convenient** masking table into a native `arangodump`
 * maskings file (dump-only — the dump itself is anonymized).
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
 *    ({@see MASKING_MODES}: `exclude` / `structure` / `masked` / `full`) ;
 *  - a key **with a dot** is `<collection>.<path>` (the first segment is the
 *    collection, the rest is the attribute path); its value is a masker name
 *    ({@see MASKING_FUNCTIONS}) or an inline table carrying `type` plus the
 *    masker parameters. The collection mode defaults to `masked`.
 *
 * The native structure produced is, per collection:
 * `{ "type": <mode>, "maskings": [ { "path": <path>, "type": <masker>, … } ] }`.
 *
 * Anything the convenient form cannot express (path wildcards, quoted names) is
 * served by the native-file escape hatch (`--maskings <file.json>`).
 */
trait ArangoMaskingTrait
{
    /**
     * The attribute masker function names supported by `arangodump`.
     */
    private const array MASKING_FUNCTIONS =
    [
        'creditCard' , 'datetime' , 'decimal' , 'email' , 'integer' ,
        'phone' , 'random' , 'randomString' , 'xifyFront' , 'zip' ,
    ] ;

    /**
     * The document-level masking modes (the per-collection `type`).
     */
    private const array MASKING_MODES = [ 'exclude' , 'full' , 'masked' , 'structure' ] ;

    /**
     * Compiles the convenient masking table into the native `arangodump`
     * maskings structure (collection name => `{ type, maskings[] }`).
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
                if( !is_string( $value ) || !in_array( $value , self::MASKING_MODES , true ) )
                {
                    throw new InvalidArgumentException
                    (
                        sprintf
                        (
                            "Invalid masking mode for '%s': expected one of %s." ,
                            $key ,
                            implode( ', ' , self::MASKING_MODES )
                        )
                    ) ;
                }

                $result[ $key ] ??= [ 'type' => 'masked' , 'maskings' => [] ] ;
                $result[ $key ][ 'type' ] = $value ;

                continue ;
            }

            $collection = substr( $key , 0 , $dot ) ;
            $path       = substr( $key , $dot + 1 ) ;

            if( $collection === '' || $path === '' )
            {
                throw new InvalidArgumentException( sprintf( "Malformed masking key '%s': expected '<collection>.<path>'." , $key ) ) ;
            }

            $result[ $collection ] ??= [ 'type' => 'masked' , 'maskings' => [] ] ;
            $result[ $collection ][ 'maskings' ][] = $this->maskingRule( $path , $value ) ;
        }

        return $result ;
    }

    /**
     * Compiles the table and writes the native maskings JSON to a temporary
     * file (cleaned up with the command). Returns the file path.
     *
     * @param array $table        The convenient `[…masking]` table.
     * @param array $tmpSegments   The temporary-directory path segments (command-scoped, so the command cleanup removes it).
     * @return string The path to the generated maskings file.
     * @throws InvalidArgumentException When the table is invalid.
     */
    public function materializeMaskings( array $table , array $tmpSegments ) :string
    {
        $compiled  = $this->compileMaskings( $table ) ;
        $directory = makeTemporaryDirectory( $tmpSegments ) ;
        $file      = $directory . DIRECTORY_SEPARATOR . 'maskings.json' ;

        file_put_contents( $file , json_encode( $compiled , JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ;

        return $file ;
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

        if( !is_string( $type ) || !in_array( $type , self::MASKING_FUNCTIONS , true ) )
        {
            throw new InvalidArgumentException
            (
                sprintf
                (
                    "Invalid masking function for path '%s': expected one of %s." ,
                    $path ,
                    implode( ', ' , self::MASKING_FUNCTIONS )
                )
            ) ;
        }

        return $rule ;
    }
}
