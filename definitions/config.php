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
 *                       This is the only configuration key the bundled
 *                       smoke-test commands rely on.
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
    }
] ;
