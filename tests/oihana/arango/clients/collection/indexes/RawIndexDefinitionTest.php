<?php

namespace tests\oihana\arango\clients\collection\indexes;

use oihana\arango\clients\collection\indexes\IndexDefinition;
use oihana\arango\clients\collection\indexes\RawIndexDefinition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see RawIndexDefinition}.
 */
#[CoversClass(RawIndexDefinition::class)]
class RawIndexDefinitionTest extends TestCase
{
    public function testToArrayReturnsTheBodyVerbatim() :void
    {
        $body = [ 'type' => 'persistent' , 'fields' => [ 'email' ] , 'unique' => true ] ;

        $this->assertSame( $body , new RawIndexDefinition( $body )->toArray() ) ;
    }

    public function testBodyPropertyExposesTheRawArray() :void
    {
        $body = [ 'type' => 'ttl' , 'fields' => [ 'createdAt' ] , 'expireAfter' => 3600 ] ;

        $this->assertSame( $body , new RawIndexDefinition( $body )->body ) ;
    }

    public function testImplementsTheIndexDefinitionContract() :void
    {
        $this->assertInstanceOf( IndexDefinition::class , new RawIndexDefinition( [] ) ) ;
    }
}
