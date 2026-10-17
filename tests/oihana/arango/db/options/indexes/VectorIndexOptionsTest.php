<?php

namespace tests\oihana\arango\db\options\indexes;

use ReflectionException;

use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\VectorIndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a vector index declaration sends to the server.
 */
#[CoversClass( VectorIndexOptions::class )]
final class VectorIndexOptionsTest extends TestCase
{
    /**
     * 🚨 Only what is declared : no `geoJson` — an option of the geo-spatial index a
     * vector index does not know —, no empty `storedValues` — refused where the other
     * types hold one, and unknown before ArangoDB 3.12.7 —, and neither `sparse` nor
     * `parallelism` when the server's default holds.
     *
     * @throws ReflectionException
     */
    public function testOnlyWhatIsDeclaredIsSent() :void
    {
        $options = new VectorIndexOptions
        ([
            IndexOptions::NAME   => 'near' ,
            IndexOptions::FIELDS => [ 'embedding' ] ,
            IndexOptions::PARAMS => [ 'metric' => 'cosine' , 'dimension' => 3 ] ,
        ]) ;

        $this->assertSame
        (
            [
                'fields'       => [ 'embedding' ] ,
                'inBackground' => false ,
                'name'         => 'near' ,
                'params'       => [ 'metric' => 'cosine' , 'dimension' => 3 ] ,
                'type'         => 'vector' ,
            ] ,
            $options->jsonSerialize()
        ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testTheDeclaredOptionsAreSent() :void
    {
        $body = new VectorIndexOptions
        ([
            IndexOptions::FIELDS        => [ 'embedding' ] ,
            IndexOptions::PARAMS        => [ 'metric' => 'l2' , 'dimension' => 8 ] ,
            IndexOptions::SPARSE        => true ,
            IndexOptions::PARALLELISM   => 4 ,
            IndexOptions::STORED_VALUES => [ 'label' ] ,
        ])->jsonSerialize() ;

        $this->assertTrue( $body[ 'sparse' ] ) ;
        $this->assertSame( 4 , $body[ 'parallelism' ] ) ;
        $this->assertSame( [ 'label' ] , $body[ 'storedValues' ] ) ;
        $this->assertArrayNotHasKey( 'geoJson' , $body ) ;
    }
}
