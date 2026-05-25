<?php

namespace tests\oihana\arango\clients\commands\tests ;

use oihana\arango\clients\commands\tests\ArangoTestClientsCommand ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

use ReflectionClass ;

use Symfony\Component\Console\Command\Command ;

/**
 * Loading-time smoke test for {@see ArangoTestClientsCommand}.
 *
 * Loads the command class via {@see ReflectionClass} so PHP fully
 * resolves every `use SomeTrait ;` statement inside the class body
 * and every top-level import. This catches the classes of regression
 * that escape a `php -l` lint (missing top-level
 * `use Namespace\SomeTrait ;` import, unresolved parent, typoed
 * property type) but blow up the moment the command is materialised
 * at runtime.
 *
 * Same pattern as {@see \tests\oihana\api\commands\AuthCommandsLoadingTest}.
 */
#[CoversClass( ArangoTestClientsCommand::class )]
class ArangoTestClientsCommandLoadingTest extends TestCase
{
    public function testCommandClassLoadsViaReflection() :void
    {
        $this->assertTrue
        (
            class_exists( ArangoTestClientsCommand::class ) ,
            'ArangoTestClientsCommand could not be autoloaded. Most likely cause : a `use SomeTrait ;` ' .
            'inside the class is missing its top-level `use Namespace\\SomeTrait ;` import, ' .
            'or a parent / property type cannot be resolved.'
        ) ;

        $reflection = new ReflectionClass( ArangoTestClientsCommand::class ) ;

        $this->assertTrue
        (
            $reflection->isInstantiable() ,
            'ArangoTestClientsCommand must be instantiable (concrete, not abstract).'
        ) ;

        $this->assertTrue
        (
            $reflection->isSubclassOf( Command::class ) ,
            'ArangoTestClientsCommand must extend Symfony\'s Command (via oihana\\commands\\Kernel).'
        ) ;
    }

    public function testCommandClassResolvesAllItsTraits() :void
    {
        $reflection = new ReflectionClass( ArangoTestClientsCommand::class ) ;

        foreach ( $reflection->getTraitNames() as $traitName )
        {
            $this->assertTrue
            (
                trait_exists( $traitName ) ,
                "ArangoTestClientsCommand declares `use $traitName` but the trait cannot be resolved. " .
                'Check the top-level `use ...` imports of the command file.'
            ) ;
        }
    }
}
