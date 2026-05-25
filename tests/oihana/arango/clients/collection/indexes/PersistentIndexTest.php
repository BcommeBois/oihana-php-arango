<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\PersistentIndex ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see PersistentIndex} — value object for the default
 * (B-tree) index type.
 */
#[CoversClass( PersistentIndex::class )]
class PersistentIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf( IndexDefinition::class , new PersistentIndex( fields : [ 'email' ] ) ) ;
    }

    public function testMinimalIndexCarriesOnlyTypeAndFields() :void
    {
        $payload = ( new PersistentIndex( fields : [ 'email' ] ) )->toArray() ;

        $this->assertSame
        (
            [ 'type' => 'persistent' , 'fields' => [ 'email' ] ] ,
            $payload ,
        ) ;
    }

    public function testUniqueAndSparseAreEmittedWhenTrue() :void
    {
        $payload = ( new PersistentIndex
        (
            fields : [ 'email' ] ,
            unique : true ,
            sparse : true ,
        ) )->toArray() ;

        $this->assertTrue( $payload[ 'unique' ] ) ;
        $this->assertTrue( $payload[ 'sparse' ] ) ;
    }

    public function testUniqueAndSparseAreOmittedWhenFalse() :void
    {
        // Default false values are omitted to keep the body compact and
        // let the server apply its own defaults.
        $payload = ( new PersistentIndex( fields : [ 'email' ] ) )->toArray() ;

        $this->assertArrayNotHasKey( 'unique' , $payload ) ;
        $this->assertArrayNotHasKey( 'sparse' , $payload ) ;
    }

    public function testOptionalFlagsAreEmittedOnlyWhenSet() :void
    {
        $payload = ( new PersistentIndex
        (
            fields       : [ 'email' ] ,
            name         : 'idx_email' ,
            deduplicate  : false ,
            estimates    : true ,
            cacheEnabled : true ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_email' , $payload[ 'name'         ] ) ;
        $this->assertFalse( $payload[ 'deduplicate'  ] ) ;
        $this->assertTrue ( $payload[ 'estimates'    ] ) ;
        $this->assertTrue ( $payload[ 'cacheEnabled' ] ) ;
        $this->assertTrue ( $payload[ 'inBackground' ] ) ;
    }

    public function testNullOptionalFlagsAreOmitted() :void
    {
        $payload = ( new PersistentIndex( fields : [ 'email' ] ) )->toArray() ;

        $this->assertArrayNotHasKey( 'name'         , $payload ) ;
        $this->assertArrayNotHasKey( 'deduplicate'  , $payload ) ;
        $this->assertArrayNotHasKey( 'estimates'    , $payload ) ;
        $this->assertArrayNotHasKey( 'cacheEnabled' , $payload ) ;
        $this->assertArrayNotHasKey( 'storedValues' , $payload ) ;
        $this->assertArrayNotHasKey( 'inBackground' , $payload ) ;
    }

    public function testStoredValuesAreEmittedWhenSet() :void
    {
        $payload = ( new PersistentIndex
        (
            fields       : [ 'email' ] ,
            storedValues : [ 'name' , 'createdAt' ] ,
        ) )->toArray() ;

        $this->assertSame( [ 'name' , 'createdAt' ] , $payload[ 'storedValues' ] ) ;
    }
}
