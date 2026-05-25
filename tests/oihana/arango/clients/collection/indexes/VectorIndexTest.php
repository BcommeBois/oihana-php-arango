<?php

namespace tests\oihana\arango\clients\collection\indexes ;

use oihana\arango\clients\collection\indexes\IndexDefinition ;
use oihana\arango\clients\collection\indexes\VectorIndex ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see VectorIndex} — Faiss-backed similarity-search index
 * (ArangoDB 3.13+).
 */
#[CoversClass( VectorIndex::class )]
class VectorIndexTest extends TestCase
{
    public function testImplementsIndexDefinition() :void
    {
        $this->assertInstanceOf
        (
            IndexDefinition::class ,
            new VectorIndex( fields : [ 'embedding' ] , params : [ 'dimensions' => 768 ] ) ,
        ) ;
    }

    public function testMinimalPayloadCarriesTypeFieldsAndParams() :void
    {
        $params  = [ 'dimensions' => 768 , 'metric' => 'cosine' , 'nLists' => 100 ] ;
        $payload = ( new VectorIndex( fields : [ 'embedding' ] , params : $params ) )->toArray() ;

        $this->assertSame
        (
            [
                'type'   => 'vector' ,
                'fields' => [ 'embedding' ] ,
                'params' => $params ,
            ] ,
            $payload ,
        ) ;
    }

    public function testOptionalFieldsAreEmittedWhenSet() :void
    {
        $payload = ( new VectorIndex
        (
            fields       : [ 'embedding' ] ,
            params       : [ 'dimensions' => 768 ] ,
            parallelism  : 4 ,
            name         : 'idx_embedding' ,
            storedValues : [ 'title' ] ,
            inBackground : true ,
        ) )->toArray() ;

        $this->assertSame( 4               , $payload[ 'parallelism'  ] ) ;
        $this->assertSame( 'idx_embedding' , $payload[ 'name'         ] ) ;
        $this->assertSame( [ 'title' ]     , $payload[ 'storedValues' ] ) ;
        $this->assertTrue ( $payload[ 'inBackground' ] ) ;
    }
}
