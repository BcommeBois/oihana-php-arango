<?php

namespace tests\oihana\arango\db\options\indexes;

use ReflectionException;

use oihana\arango\db\enums\IndexType;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\MDIIndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a multi-dimensional index declaration sends to the server.
 *
 * 🚨 **This class could create no index at all.** It sent `prefixFields: []`, which an `mdi` refuses,
 * and `storedValues: []`, which both types refuse — measured on ArangoDB 3.12.
 * And `estimates: true`, which an `mdi` answers `false` to, so the index comparison reported a difference on every run.
 */
#[CoversClass( MDIIndexOptions::class )]
final class MDIIndexOptionsTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testAnUndeclaredListOrEstimateIsNotSent() :void
    {
        $body = new MDIIndexOptions( [ IndexOptions::NAME => 'xy' , IndexOptions::FIELDS => [ 'x' , 'y' ] ] )->jsonSerialize() ;

        $this->assertArrayNotHasKey( 'prefixFields' , $body ) ;
        $this->assertArrayNotHasKey( 'storedValues' , $body ) ;
        $this->assertArrayNotHasKey( 'estimates'    , $body ) ;
    }

    /**
     * The value types are required by the server, and `double` is the only one it
     * admits : the default.
     *
     * @throws ReflectionException
     */
    public function testTheDefaults() :void
    {
        $this->assertSame
        (
            [
                'fieldValueTypes' => 'double' ,
                'fields'          => [ 'x' , 'y' ] ,
                'inBackground'    => false ,
                'name'            => 'xy' ,
                'sparse'          => false ,
                'type'            => 'mdi' ,
                'unique'          => false ,
            ] ,
            new MDIIndexOptions( [ IndexOptions::NAME => 'xy' , IndexOptions::FIELDS => [ 'x' , 'y' ] ] )->jsonSerialize()
        ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testAPrefixedIndexSendsWhatItDeclares() :void
    {
        $body = new MDIIndexOptions
        ([
            IndexOptions::TYPE          => IndexType::MDI_PREFIXED ,
            IndexOptions::FIELDS        => [ 'x' , 'y' ] ,
            IndexOptions::PREFIX_FIELDS => [ 'kind' ] ,
            IndexOptions::STORED_VALUES => [ 'label' ] ,
            IndexOptions::ESTIMATES     => true ,
        ])->jsonSerialize() ;

        $this->assertSame( 'mdi-prefixed' , $body[ 'type'         ] ) ;
        $this->assertSame( [ 'kind' ]     , $body[ 'prefixFields' ] ) ;
        $this->assertSame( [ 'label' ]    , $body[ 'storedValues' ] ) ;
        $this->assertTrue( $body[ 'estimates' ] ) ;
    }
}
