<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\traits\DirectoryTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Host composing {@see DirectoryTrait}.
 */
class DirectoryTraitHost
{
    use DirectoryTrait ;
}

/**
 * Unit coverage for {@see DirectoryTrait}.
 */
#[CoversTrait(DirectoryTrait::class)]
class DirectoryTraitTest extends TestCase
{
    public function testDirectoryDefaultsToNull() :void
    {
        $host = new DirectoryTraitHost() ;
        $this->assertNull( $host->directory ) ;
    }

    public function testInitializeDirectorySetsTheDirectoryFromInit() :void
    {
        $host = new DirectoryTraitHost() ;

        $returned = $host->initializeDirectory( [ ArangoCommandParam::DIRECTORY => '/var/dumps' ] ) ;

        $this->assertSame( '/var/dumps' , $host->directory ) ;
        $this->assertSame( $host , $returned ) ;        // fluent: returns $this
    }

    public function testInitializeDirectoryKeepsTheCurrentValueWhenTheKeyIsAbsent() :void
    {
        $host = new DirectoryTraitHost() ;
        $host->directory = '/previous' ;

        $host->initializeDirectory( [] ) ;

        $this->assertSame( '/previous' , $host->directory ) ;
    }

    public function testInitializeDirectoryDefaultsToAnEmptyInit() :void
    {
        $host = new DirectoryTraitHost() ;

        $host->initializeDirectory() ;

        $this->assertNull( $host->directory ) ;
    }
}
