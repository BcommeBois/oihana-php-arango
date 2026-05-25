<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\InvertedIndex ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see InvertedIndex} — modern fulltext replacement
 * (ArangoDB 3.10+).
 */
#[CoversClass( InvertedIndex::class )]
class InvertedIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf
        (
            IndexDefinition::class ,
            new InvertedIndex( fields : [ 'description' ] ) ,
        ) ;
    }

    public function testMinimalPayloadCarriesOnlyTypeAndFields() :void
    {
        $payload = ( new InvertedIndex( fields : [ 'description' ] ) )->toArray() ;

        $this->assertSame
        (
            [ 'type' => 'inverted' , 'fields' => [ 'description' ] ] ,
            $payload ,
        ) ;
    }

    public function testAnalyzerAndFeaturesAreEmittedWhenSet() :void
    {
        $payload = ( new InvertedIndex
        (
            fields   : [ 'name' , 'description' ] ,
            analyzer : 'text_en' ,
            features : [ 'frequency' , 'position' , 'norm' ] ,
        ) )->toArray() ;

        $this->assertSame( 'text_en'                              , $payload[ 'analyzer' ] ) ;
        $this->assertSame( [ 'frequency' , 'position' , 'norm' ] , $payload[ 'features' ] ) ;
    }

    public function testPerFieldShapeIsForwardedAsIs() :void
    {
        // fields may be an array of field-configuration objects.
        $fields =
        [
            [ 'name' => 'title' , 'analyzer' => 'text_en' ] ,
            [ 'name' => 'body'  , 'features' => [ 'frequency' ] ] ,
        ] ;

        $payload = ( new InvertedIndex( fields : $fields ) )->toArray() ;

        $this->assertSame( $fields , $payload[ 'fields' ] ) ;
    }

    public function testAdvancedOptionsAreEmittedWhenSet() :void
    {
        $primarySort  = [ 'fields' => [ [ 'field' => 'created' , 'direction' => 'desc' ] ] ] ;
        $storedValues = [ [ 'fields' => [ 'title' ] , 'compression' => 'lz4' ] ] ;

        $payload = ( new InvertedIndex
        (
            fields                    : [ 'title' ] ,
            name                      : 'idx_inv' ,
            primarySort               : $primarySort ,
            storedValues              : $storedValues ,
            includeAllFields          : false ,
            searchField               : true ,
            trackListPositions        : true ,
            cache                     : true ,
            primaryKeyCache           : false ,
            parallelism               : 2 ,
            cleanupIntervalStep       : 2 ,
            commitIntervalMsec        : 1000 ,
            consolidationIntervalMsec : 10000 ,
            inBackground              : true ,
        ) )->toArray() ;

        $this->assertSame( 'idx_inv'      , $payload[ 'name'                      ] ) ;
        $this->assertSame( $primarySort   , $payload[ 'primarySort'               ] ) ;
        $this->assertSame( $storedValues  , $payload[ 'storedValues'              ] ) ;
        $this->assertFalse( $payload[ 'includeAllFields'          ] ) ;
        $this->assertTrue ( $payload[ 'searchField'               ] ) ;
        $this->assertTrue ( $payload[ 'trackListPositions'        ] ) ;
        $this->assertTrue ( $payload[ 'cache'                     ] ) ;
        $this->assertFalse( $payload[ 'primaryKeyCache'           ] ) ;
        $this->assertSame( 2              , $payload[ 'parallelism'               ] ) ;
        $this->assertSame( 2              , $payload[ 'cleanupIntervalStep'       ] ) ;
        $this->assertSame( 1000           , $payload[ 'commitIntervalMsec'        ] ) ;
        $this->assertSame( 10000          , $payload[ 'consolidationIntervalMsec' ] ) ;
        $this->assertTrue ( $payload[ 'inBackground'              ] ) ;
    }
}
