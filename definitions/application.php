<?php

use DI\Container;

use oihana\arango\clients\commands\tests\ArangoTestClientsCommand;
use oihana\arango\commands\ArangoCommand;

use oihana\arango\db\commands\tests\ArangoFacadeTestCommand;
use Symfony\Component\Console\Application;

/**
 * Build the Symfony Console application and register the commands
 * defined in commands.php.
 *
 * The library ships three commands :
 *
 *   - command:arangodb       Generic dump/restore runner for ArangoDB
 *                            (uses configs/config.toml).
 *   - arango:test:clients    Live smoke test for the clients/ HTTP library.
 *   - arango:test:facade     Live smoke test for the high-level façade.
 *
 * Everything else (harvesting, integrity checks, project-specific tooling)
 * lives in the consuming host application's own console.
 */
return
[
    Application::class => function( Container $container ) :Application
    {
        $application = new Application( 'oihana/php-arango — console runner' ) ;

        $application->addCommand( $container->get( ArangoCommand::NAME            ) ) ;
        $application->addCommand( $container->get( ArangoTestClientsCommand::NAME ) ) ;
        $application->addCommand( $container->get( ArangoFacadeTestCommand::NAME  ) ) ;

        return $application ;
    }
] ;
