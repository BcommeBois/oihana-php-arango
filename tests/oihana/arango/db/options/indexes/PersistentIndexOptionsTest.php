<?php

namespace tests\oihana\arango\db\options\indexes;

use ReflectionException;

use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a persistent index declaration sends to the server.
 *
 * The defaults sent are the ones the server answers with (measured on ArangoDB 3.12) :
 * the index comparison reads no difference right after the creation.
 */
#[CoversClass( PersistentIndexOptions::class )]
final class PersistentIndexOptionsTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testTheDefaultsAreTheServers() :void
    {
        $options = new PersistentIndexOptions( [ IndexOptions::NAME => 'byOwner' , IndexOptions::FIELDS => [ 'owner' ] ] ) ;

        $this->assertSame
        (
            [
                'cacheEnabled' => false ,
                'deduplicate'  => true ,
                'estimates'    => true ,
                'fields'       => [ 'owner' ] ,
                'inBackground' => false ,
                'name'         => 'byOwner' ,
                'sparse'       => false ,
                'type'         => 'persistent' ,
                'unique'       => false ,
            ] ,
            $options->jsonSerialize()
        ) ;
    }

    /**
     * 🚨 The stored values are sent under the key the server reads — and only when
     * declared.
     *
     * @throws ReflectionException
     */
    public function testTheStoredValuesAreSentWhenDeclared() :void
    {
        $options = new PersistentIndexOptions
        ([
            IndexOptions::FIELDS        => [ 'owner' ] ,
            IndexOptions::STORED_VALUES => [ 'due' , '_key' ] ,
        ]) ;

        $this->assertSame( [ 'due' , '_key' ] , $options->jsonSerialize()[ 'storedValues' ] ) ;
        $this->assertArrayNotHasKey( 'storedValues' , new PersistentIndexOptions( [ IndexOptions::FIELDS => [ 'owner' ] ] )->jsonSerialize() ) ;
    }

    /**
     * @throws ReflectionException
     */
    public function testEveryDeclaredFlagIsSent() :void
    {
        $body = new PersistentIndexOptions
        ([
            IndexOptions::FIELDS        => [ 'email' ] ,
            IndexOptions::UNIQUE        => true ,
            IndexOptions::SPARSE        => true ,
            IndexOptions::DEDUPLICATE   => false ,
            IndexOptions::ESTIMATES     => false ,
            IndexOptions::CACHE_ENABLED => true ,
        ])->jsonSerialize() ;

        $this->assertTrue ( $body[ 'unique'       ] ) ;
        $this->assertTrue ( $body[ 'sparse'       ] ) ;
        $this->assertFalse( $body[ 'deduplicate'  ] ) ;
        $this->assertFalse( $body[ 'estimates'    ] ) ;
        $this->assertTrue ( $body[ 'cacheEnabled' ] ) ;
    }
}
