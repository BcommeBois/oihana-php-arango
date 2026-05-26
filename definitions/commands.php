<?php

use DI\Container;

use oihana\arango\clients\commands\tests\ArangoTestClientsCommand;
use oihana\arango\commands\ArangoCommand;
use oihana\arango\commands\enums\ArangoAction;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\db\commands\tests\ArangoFacadeTestCommand;
use oihana\arango\db\enums\ArangoConfig;

use oihana\commands\enums\CommandParam;
use oihana\commands\options\CommandOption;

/**
 * DI definitions for the commands shipped with the library.
 *
 *   - command:arangodb       ArangoCommand — generic dump/restore/listDumps
 *                            runner for an ArangoDB database. Reads
 *                            connection settings from the [arango] section
 *                            of configs/config.toml and the dumps directory
 *                            from the `app.dumps` definition (see config.php).
 *
 *   - arango:test:clients    ArangoTestClientsCommand — exercises the new
 *                            clients/ HTTP library against a real arangod.
 *
 *   - arango:test:facade     ArangoFacadeTestCommand — exercises the high-level
 *                            ArangoDB façade against a real arangod.
 *
 * All three commands share the same `arango.config` definition (see config.php).
 */
return
[
    'command:arangodb' => function( Container $container ) :ArangoCommand
    {
        $arango = $container->get( 'arango.config' ) ;

        return new ArangoCommand
        (
            name      : ArangoCommand::NAME ,
            container : $container ,
            init      :
            [
                CommandParam::DESCRIPTION    => 'Manage the ArangoDB database (dump / restore / list dumps).' ,
                CommandParam::HELP           => 'Generic dump and restore runner for an ArangoDB database. Connection settings come from the [arango] section of configs/config.toml ; the dumps directory comes from [app].dumps (see config.example.toml). Override any field on the CLI: --endpoint, --user, --password, --database, --directory, --passphrase, --encrypt. Action is the first positional argument: dump, restore, listDumps. Examples: `php bin/console.php command:arangodb dump`, `php bin/console.php command:arangodb dump --list`, `php bin/console.php command:arangodb restore --last`.' ,
                CommandParam::ACTIONS        =>
                [
                    ArangoAction::DUMP    ,
                    ArangoAction::RESTORE ,
                ] ,
                ArangoCommandParam::DIRECTORY => $container->get( 'app.dumps' ) ,

                // Default encryption flag + passphrase fall back on the
                // [arango] section ; both can be overridden per run via
                // --encrypt and --passphrase.
                CommandOption::ENCRYPT     => (bool) ( $arango[ ArangoConfig::ENCRYPT ] ?? false ) ,
                CommandOption::PASS_PHRASE => $arango[ 'passphrase' ] ?? null ,

                // Connection settings — spread the [arango] section so the
                // ArangoConfigTrait keys (database, endpoint, user, password)
                // are picked up by the command at boot.
                ...$arango ,
            ]
        ) ;
    } ,

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
