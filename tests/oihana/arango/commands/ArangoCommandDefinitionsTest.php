<?php

namespace tests\oihana\arango\commands;

use oihana\arango\commands\ArangoCommand;
use oihana\arango\commands\enums\ArangoAction;

use PHPUnit\Framework\TestCase;

use function oihana\init\initContainer;
use function oihana\init\initDefinitions;

/**
 * Guards the bundled `definitions/commands.php` wiring of {@see ArangoCommand}:
 * every whitelisted action must have a handler method, and the `analyzers`
 * action must be allowed (regression — it was dispatchable but missing from the
 * `CommandParam::ACTIONS` whitelist, so the Kernel rejected it).
 *
 * @package tests\oihana\arango\commands
 * @author  Marc Alcaraz
 */
class ArangoCommandDefinitionsTest extends TestCase
{
    private function command() :ArangoCommand
    {
        $root = dirname( __DIR__ , 4 ) ;

        // Bootstrap constants normally defined by bin/console.php.
        if ( !defined( '__LIB__' ) )         { define( '__LIB__'        , $root ) ; }
        if ( !defined( '__CONFIG__' ) )      { define( '__CONFIG__'     , $root . DIRECTORY_SEPARATOR . 'configs' ) ; }
        if ( !defined( '__DEFINITIONS__' ) ) { define( '__DEFINITIONS__', $root . DIRECTORY_SEPARATOR . 'definitions' ) ; }

        $container = initContainer( initDefinitions( __DEFINITIONS__ ) ) ;

        return $container->get( ArangoCommand::NAME ) ;
    }

    public function testAnalyzersActionIsWhitelisted() :void
    {
        $this->assertContains( ArangoAction::ANALYZERS , $this->command()->actions ?? [] ) ;
    }

    public function testEveryWhitelistedActionHasAHandler() :void
    {
        $command = $this->command() ;

        foreach ( $command->actions ?? [] as $action )
        {
            $this->assertTrue( method_exists( $command , $action ) , sprintf( "The whitelisted action '%s' has no handler method on ArangoCommand." , $action ) ) ;
        }
    }
}
