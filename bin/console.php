#!/usr/bin/env php
<?php

use Symfony\Component\Console\Application;

use function oihana\init\initContainer;
use function oihana\init\initDefinitions;

/**
 * Console entry point for the oihana/php-arango live smoke-test runner.
 *
 * Bootstraps a minimal Symfony Console application from the `definitions/`
 * directory. Available commands:
 *
 *   - arango:test:clients   Live end-to-end smoke test for the new HTTP client
 *                           (clients/ — connection, database/collection
 *                           lifecycle, document CRUD, edges, AQL + cursor,
 *                           indexes, transactions, graphs, analyzers, views).
 *
 *   - arango:test:facade    Live end-to-end smoke test for the high-level
 *                           ArangoDB façade (db/ArangoDB).
 *
 * The runner reads its ArangoDB connection settings from `configs/config.toml` (copy `configs/config.example.toml` first
 * and adjust the values to point at your local arangod).
 */

const __LIB__         = __DIR__ . DIRECTORY_SEPARATOR . '..' ;
const __CONFIG__      = __LIB__ . DIRECTORY_SEPARATOR . 'configs'     ;
const __DEFINITIONS__ = __LIB__ . DIRECTORY_SEPARATOR . 'definitions' ;

require_once __LIB__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php' ;

try
{
    $container   = initContainer( initDefinitions( __DEFINITIONS__ ) ) ;
    $application = $container->get( Application::class ) ;
    $application->run() ;
}
catch ( Throwable $exception )
{
    fwrite( STDERR , 'Bootstrap failed: ' . $exception->getMessage() . PHP_EOL ) ;
    exit( 1 ) ;
}
