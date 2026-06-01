<?php

/**
 * Dev helper — seeds a disposable ArangoDB database for testing the
 * `command:arangodb` dump / restore / collections features WITHOUT
 * touching your usual local database.
 *
 * Usage (from the library root):
 *   php scripts/seed-playground.php                  # database "dump_playground"
 *   php scripts/seed-playground.php my_other_db       # custom database name
 *
 * Connection settings are read from the [arango] section of
 * configs/config.toml (same as the command), so it targets the same local
 * server — only on a separate database.
 *
 * Idempotent: re-running it re-creates the collections and re-seeds them.
 */

const __LIB__    = __DIR__ . DIRECTORY_SEPARATOR . '..' ;
const __CONFIG__ = __LIB__ . DIRECTORY_SEPARATOR . 'configs' ;

require_once __LIB__ . DIRECTORY_SEPARATOR . 'vendor/autoload.php' ;

use oihana\arango\clients\ArangoClient ;
use oihana\arango\clients\options\ClientOptions ;

use function oihana\init\initConfig ;

$databaseName = $argv[ 1 ] ?? 'dump_playground' ;

// --- Sample data -----------------------------------------------------------

$collections =
[
    'users' =>
    [
        [ '_key' => 'alice' , 'name' => 'Alice' , 'role' => 'admin'  ] ,
        [ '_key' => 'bob'   , 'name' => 'Bob'   , 'role' => 'editor' ] ,
        [ '_key' => 'carol' , 'name' => 'Carol' , 'role' => 'viewer' ] ,
    ] ,
    'products' =>
    [
        [ '_key' => 'p1' , 'label' => 'Oak plank'    , 'price' => 19.9 ] ,
        [ '_key' => 'p2' , 'label' => 'Pine board'   , 'price' =>  9.5 ] ,
        [ '_key' => 'p3' , 'label' => 'Walnut panel' , 'price' => 42.0 ] ,
        [ '_key' => 'p4' , 'label' => 'Birch sheet'  , 'price' => 15.0 ] ,
    ] ,
    'orders' =>
    [
        [ '_key' => 'o1' , 'user' => 'alice' , 'items' => [ 'p1' , 'p3' ] , 'total' => 61.9 ] ,
        [ '_key' => 'o2' , 'user' => 'bob'   , 'items' => [ 'p2' ]        , 'total' =>  9.5 ] ,
    ] ,
    'customers' =>
    [
        [ '_key' => 'c1' , 'company' => 'Acme Wood'   ] ,
        [ '_key' => 'c2' , 'company' => 'Timber & Co' ] ,
        [ '_key' => 'c3' , 'company' => 'Forest Ltd'  ] ,
    ] ,
] ;

// --- Build the client from the [arango] config ------------------------------

$config = initConfig( basePath: __CONFIG__ , file: 'config.toml' ) ;
$arango = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

$options = ClientOptions::fromArray( $arango ) ;
$client  = new ArangoClient( $options ) ;

echo '→ endpoint : ' . ( $options->endpoint() ?? '<none>' ) . PHP_EOL ;
echo '→ database : ' . $databaseName . PHP_EOL ;

// --- Create the database (if missing) ---------------------------------------

$database = $client->database( $databaseName ) ;

if ( !$database->exists() )
{
    $database->create() ;
    echo "✓ created database '{$databaseName}'" . PHP_EOL ;
}
else
{
    echo "• database '{$databaseName}' already exists" . PHP_EOL ;
}

// --- Create + seed the collections ------------------------------------------

foreach ( $collections as $name => $documents )
{
    $collection = $database->collection( $name ) ;

    if ( !$collection->exists() )
    {
        $collection->create() ;
    }
    else
    {
        $collection->truncate() ;
    }

    foreach ( $documents as $document )
    {
        $collection->insert( $document ) ;
    }

    echo "✓ {$name} : " . count( $documents ) . ' document(s)' . PHP_EOL ;
}

echo PHP_EOL . 'Done. Test it with:' . PHP_EOL ;
echo "  php bin/console.php command:arangodb collections --database {$databaseName}" . PHP_EOL ;
echo "  php bin/console.php command:arangodb dump        --database {$databaseName} -c users,products -L test" . PHP_EOL ;
echo "  php bin/console.php command:arangodb restore     --database {$databaseName} --last --collection users" . PHP_EOL ;
