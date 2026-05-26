<?php

use DI\Container;

use function oihana\init\initConfig;

/**
 * Load the TOML configuration shipped with the library.
 *
 * Exposes:
 *
 *   - 'arango.config' : the parsed [arango] section of configs/config.toml,
 *                       or an empty array when the file does not exist.
 *                       Consumed by the bundled smoke-test commands and
 *                       by `command:arangodb` (dump/restore).
 *
 *   - 'app.dumps'     : absolute path of the directory where dump archives
 *                       are written / read by `command:arangodb`. Resolved
 *                       from [app].dumps in configs/config.toml :
 *                         - absolute path     → used as-is
 *                         - relative path     → resolved against __LIB__
 *                         - missing / empty   → defaults to __LIB__/dumps
 *                       Host projects integrating the library should wire
 *                       their own `ArangoCommandParam::DIRECTORY` instead
 *                       of relying on this key.
 *
 * Drop a `configs/config.toml` next to `configs/config.example.toml` to
 * point the runner at your local ArangoDB instance.
 */
return
[
    'arango.config' => function( Container $container ) :array
    {
        $config = initConfig( basePath: __CONFIG__ , file: 'config.toml' );
        return is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;
    } ,

    'app.dumps' => function( Container $container ) :string
    {
        $config = initConfig( basePath: __CONFIG__ , file: 'config.toml' );
        $value  = $config[ 'app' ][ 'dumps' ] ?? null ;

        if ( !is_string( $value ) || $value === '' )
        {
            return __LIB__ . DIRECTORY_SEPARATOR . 'dumps' ;
        }

        // Absolute path → used as-is. Detects both POSIX and Windows roots.
        if ( str_starts_with( $value , DIRECTORY_SEPARATOR ) || preg_match( '#^[A-Za-z]:[\\\\/]#' , $value ) === 1 )
        {
            return $value ;
        }

        // Relative path → resolved against the library root.
        return __LIB__ . DIRECTORY_SEPARATOR . $value ;
    } ,
] ;
