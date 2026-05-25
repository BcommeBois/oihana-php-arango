<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\TtlIndex ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see TtlIndex} — value object for the TTL index.
 */
#[CoversClass( TtlIndex::class )]
class TtlIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf
        (
            IndexDefinition::class ,
            new TtlIndex( fields : [ 'createdAt' ] , expireAfter : 60 ) ,
        ) ;
    }

    public function testMinimalIndexCarriesTypeFieldsAndExpireAfter() :void
    {
        $payload = ( new TtlIndex
        (
            fields      : [ 'createdAt' ] ,
            expireAfter : 3600 ,
        ) )->toArray() ;

        $this->assertSame
        (
            [
                'type'        => 'ttl' ,
                'fields'      => [ 'createdAt' ] ,
                'expireAfter' => 3600 ,
            ] ,
            $payload ,
        ) ;
    }

    public function testOptionalFieldsAreEmittedWhenSet() :void
    {
        $payload = ( new TtlIndex
        (
            fields       : [ 'createdAt' ] ,
            expireAfter  : 3600 ,
            name         : 'idx_ttl' ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_ttl' , $payload[ 'name'         ] ) ;
        $this->assertTrue( $payload[ 'inBackground' ] ) ;
    }
}
