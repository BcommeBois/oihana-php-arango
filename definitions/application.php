<?php

use DI\Container;

use Symfony\Component\Console\Application;

/**
 * Build the Symfony Console application and register the two bundled
 * smoke-test commands defined in commands.php.
 *
 * The library deliberately ships only the two `arango:test:*` commands —
 * everything else (harvesting, integrity checks, etc.) lives in the
 * consuming host application's own console.
 */
return
[
    Application::class => function( Container $container ) :Application
    {
        $application = new Application( 'oihana/php-arango — live smoke-test runner' );

        $application->addCommand( $container->get( 'command:arango:test:clients' ) );
        $application->addCommand( $container->get( 'command:arango:test:facade'  ) );

        return $application ;
    }
] ;
