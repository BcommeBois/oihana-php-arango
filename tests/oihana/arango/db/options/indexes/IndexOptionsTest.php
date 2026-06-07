<?php

namespace tests\oihana\arango\db\options\indexes;

use oihana\arango\db\options\indexes\IndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionException;

/**
 * Unit coverage for {@see IndexOptions}.
 */
#[CoversClass(IndexOptions::class)]
class IndexOptionsTest extends TestCase
{
    public function testNullInitLeavesTheDefaults() :void
    {
        $options = new IndexOptions() ;

        $this->assertSame( [] , $options->fields ) ;
    }

    public function testArrayInitPopulatesKnownProperties() :void
    {
        $options = new IndexOptions
        ([
            'fields' => [ 'email' ] ,
            'name'   => 'idx_email' ,
            'type'   => 'persistent' ,
        ]) ;

        $this->assertSame( [ 'email' ]   , $options->fields ) ;
        $this->assertSame( 'idx_email'   , $options->name ) ;
        $this->assertSame( 'persistent'  , $options->type ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testUnknownKeysAreIgnored() :void
    {
        $options = new IndexOptions
        ([
            'fields' => [ 'a' ] ,
            'name'   => 'idx_a' ,
            'type'   => 'geo' ,
            'bogus'  => 'ignored' ,            // not a declared property → skipped
        ]) ;

        $this->assertArrayNotHasKey( 'bogus' , $options->jsonSerialize() ) ;
    }

    public function testObjectInitPopulatesProperties() :void
    {
        $options = new IndexOptions( (object) [ 'fields' => [ 'a' , 'b' ] , 'name' => 'n' , 'type' => 'mdi' ] ) ;

        $this->assertSame( [ 'a' , 'b' ] , $options->fields ) ;
        $this->assertSame( 'n'           , $options->name ) ;
        $this->assertSame( 'mdi'         , $options->type ) ;
    }

    /**
     * @return void
     * @throws ReflectionException
     */
    public function testJsonSerializeReturnsTheToArrayShape() :void
    {
        $options = new IndexOptions
        ([
            'fields' => [ 'email' ] ,
            'name'   => 'idx_email' ,
            'type'   => 'persistent' ,
        ]) ;

        $this->assertSame
        (
            [ 'fields' => [ 'email' ] , 'name' => 'idx_email' , 'type' => 'persistent' ] ,
            $options->jsonSerialize() ,
        ) ;
    }
}
