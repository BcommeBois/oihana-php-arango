<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\FulltextIndex ;
use oihana\arango\clients\collection\indexes\IndexDefinition ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see FulltextIndex} — legacy value object for the
 * fulltext index (server-side deprecated since 3.10).
 */
#[CoversClass( FulltextIndex::class )]
class FulltextIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf
        (
            IndexDefinition::class ,
            new FulltextIndex( fields : [ 'description' ] ) ,
        ) ;
    }

    public function testMinimalIndexCarriesOnlyTypeAndFields() :void
    {
        $payload = ( new FulltextIndex( fields : [ 'description' ] ) )->toArray() ;

        $this->assertSame
        (
            [ 'type' => 'fulltext' , 'fields' => [ 'description' ] ] ,
            $payload ,
        ) ;
    }

    public function testMinLengthIsEmittedWhenSet() :void
    {
        $payload = ( new FulltextIndex
        (
            fields    : [ 'description' ] ,
            minLength : 3 ,
        ) )->toArray() ;

        $this->assertSame( 3 , $payload[ 'minLength' ] ) ;
    }

    public function testNameAndInBackgroundAreEmittedWhenSet() :void
    {
        $payload = ( new FulltextIndex
        (
            fields       : [ 'description' ] ,
            name         : 'idx_ft' ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_ft' , $payload[ 'name'         ] ) ;
        $this->assertTrue( $payload[ 'inBackground' ] ) ;
    }
}
