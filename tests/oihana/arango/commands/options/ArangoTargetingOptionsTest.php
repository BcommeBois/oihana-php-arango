<?php

namespace tests\oihana\arango\commands\options;

use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoDumpOptions;
use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\options\ArangoRestoreOptions;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the collection-targeting options wired by the dump and
 * restore actions serialize to the expected `arangodump` / `arangorestore`
 * command-line flags — array values must render as repeated flags, and the
 * targeting flags must be absent when no collection is requested.
 */
class ArangoTargetingOptionsTest extends TestCase
{
    public function testDumpCollectionRendersRepeatedFlags() :void
    {
        $command = (string) ArangoDumpOptions::create
        (
            [ ArangoDumpOption::COLLECTION => [ 'users' , 'products' ] ]
        ) ;

        $this->assertStringContainsString( '--collection "users"'    , $command ) ;
        $this->assertStringContainsString( '--collection "products"' , $command ) ;
    }

    public function testDumpWithoutTargetingHasNoCollectionFlag() :void
    {
        $command = (string) ArangoDumpOptions::create
        (
            [ ArangoDumpOption::SERVER_DATABASE => 'mydb' ]
        ) ;

        $this->assertStringNotContainsString( '--collection' , $command ) ;
    }

    public function testRestoreCollectionRendersFlag() :void
    {
        $command = (string) ArangoRestoreOptions::create
        (
            [ ArangoRestoreOption::COLLECTION => [ 'users' ] ]
        ) ;

        $this->assertStringContainsString( '--collection "users"' , $command ) ;
    }

    public function testRestoreWithoutTargetingHasNoCollectionFlag() :void
    {
        $command = (string) ArangoRestoreOptions::create
        (
            [ ArangoRestoreOption::INPUT_DIRECTORY => '/tmp/in' ]
        ) ;

        $this->assertStringNotContainsString( '--collection' , $command ) ;
    }
}
