<?php

use DI\Container;

use oihana\arango\clients\commands\tests\ArangoTestClientsCommand;
use oihana\arango\db\commands\tests\ArangoFacadeTestCommand;

use oihana\commands\enums\CommandParam;

/**
 * DI definitions for the two live smoke-test commands shipped with the library.
 *
 *   - arango:test:clients   ArangoTestClientsCommand — exercises the new
 *                           clients/ HTTP library against a real arangod.
 *
 *   - arango:test:facade    ArangoFacadeTestCommand — exercises the high-level
 *                           ArangoDB façade against a real arangod.
 *
 * Both commands read the same `arango.config` definition (see config.php).
 */
return
[
    'command:arango:test:clients' => fn( Container $container ) => new ArangoTestClientsCommand
    (
        name      : 'arango:test:clients' ,
        container : $container ,
        init      :
        [
            CommandParam::DESCRIPTION              => 'Live end-to-end smoke test for the oihana\\arango\\clients\\ HTTP library (uses an ephemeral test database created and dropped per run).' ,
            CommandParam::HELP                     => 'Exercises every public surface of the client on a real ArangoDB server: connection (version, listDatabases), database lifecycle (create, exists, collections), collection lifecycle (create, properties, rename, drop), document CRUD, edge collection, AQL + Cursor, indexes, transactions, graphs, analyzers, views, and error mapping. Connection settings come from the [arango] section of configs/config.toml; override any field with --endpoint / --user / --password / --database. Use --step=N / --step=N1-N2 / --step=all to scope the run. Pass --no-cleanup to keep the test database around for post-mortem inspection.' ,
            ArangoTestClientsCommand::ARANGO_CONFIG => $container->get( 'arango.config' ) ,
        ]
    ) ,

    'command:arango:test:facade' => fn( Container $container ) => new ArangoFacadeTestCommand
    (
        name      : 'arango:test:facade' ,
        container : $container ,
        init      :
        [
            CommandParam::DESCRIPTION              => 'Live end-to-end smoke test for the high-level oihana\\arango\\db\\ArangoDB façade (uses an ephemeral test database created and dropped per run).' ,
            CommandParam::HELP                     => 'Exercises every public surface of the façade on a real ArangoDB server: collection lifecycle, index ops, query helpers, streaming, fullCount nesting, and legacy exception wrapping. Connection settings come from the [arango] section of configs/config.toml; override any field with --endpoint / --user / --password. The façade always runs on its own ephemeral database — production data is never touched. Use --step=N / --step=N1-N2 / --step=all to scope the run. Pass --no-cleanup to keep the test database around for post-mortem inspection.' ,
            ArangoFacadeTestCommand::ARANGO_CONFIG => $container->get( 'arango.config' ) ,
        ]
    )
] ;
