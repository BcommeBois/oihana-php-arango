<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\MDIIndex ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see MDIIndex} — multi-dimensional index value object.
 * Type automatically resolves to `mdi-prefixed` when `$prefixFields`
 * is non-empty, and `mdi` otherwise.
 */
#[CoversClass( MDIIndex::class )]
class MDIIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf
        (
            IndexDefinition::class ,
            new MDIIndex( fields : [ 'lat' , 'lng' ] ) ,
        ) ;
    }

    public function testMinimalPayloadResolvesToMdi() :void
    {
        $payload = ( new MDIIndex( fields : [ 'lat' , 'lng' ] ) )->toArray() ;

        $this->assertSame
        (
            [
                'type'            => 'mdi' ,
                'fields'          => [ 'lat' , 'lng' ] ,
                'fieldValueTypes' => 'double' ,
            ] ,
            $payload ,
        ) ;
    }

    public function testPayloadResolvesToMdiPrefixedWhenPrefixFieldsSet() :void
    {
        $payload = ( new MDIIndex
        (
            fields       : [ 'lat' , 'lng' ] ,
            prefixFields : [ 'tenant' ] ,
        ) )->toArray() ;

        $this->assertSame( 'mdi-prefixed' , $payload[ 'type'         ] ) ;
        $this->assertSame( [ 'tenant' ]    , $payload[ 'prefixFields' ] ) ;
    }

    public function testEmptyPrefixFieldsKeepsPlainMdi() :void
    {
        $payload = ( new MDIIndex( fields : [ 'lat' ] , prefixFields : [] ) )->toArray() ;

        $this->assertSame( 'mdi' , $payload[ 'type' ] ) ;
        $this->assertArrayNotHasKey( 'prefixFields' , $payload ) ;
    }

    public function testUniqueAndSparseAreEmittedWhenTrue() :void
    {
        $payload = ( new MDIIndex
        (
            fields : [ 'lat' , 'lng' ] ,
            unique : true ,
            sparse : true ,
        ) )->toArray() ;

        $this->assertTrue( $payload[ 'unique' ] ) ;
        $this->assertTrue( $payload[ 'sparse' ] ) ;
    }

    public function testOptionalFieldsAreEmittedWhenSet() :void
    {
        $payload = ( new MDIIndex
        (
            fields       : [ 'lat' , 'lng' ] ,
            name         : 'idx_geo' ,
            estimates    : true ,
            storedValues : [ 'name' ] ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_geo'  , $payload[ 'name'         ] ) ;
        $this->assertTrue ( $payload[ 'estimates'    ] ) ;
        $this->assertSame( [ 'name' ] , $payload[ 'storedValues' ] ) ;
        $this->assertTrue ( $payload[ 'inBackground' ] ) ;
    }
}
