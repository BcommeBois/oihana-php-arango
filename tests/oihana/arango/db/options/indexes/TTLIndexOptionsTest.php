<?php

namespace tests\oihana\arango\db\options\indexes;

use ReflectionException;

use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\TTLIndexOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a time-to-live index declaration sends to the server.
 */
#[CoversClass( TTLIndexOptions::class )]
final class TTLIndexOptionsTest extends TestCase
{
    /**
     * @throws ReflectionException
     */
    public function testOnlyTheLifetimeTheFieldAndTheNameAreSent() :void
    {
        $options = new TTLIndexOptions( [ IndexOptions::NAME => 'expiry' , IndexOptions::FIELDS => [ 'at' ] , IndexOptions::EXPIRE_AFTER => 3600 ] ) ;

        $this->assertSame( [ 'expireAfter' => 3600 , 'fields' => [ 'at' ] , 'name' => 'expiry' , 'type' => 'ttl' ] , $options->jsonSerialize() ) ;
    }

    /**
     * The lifetime is a number, not only an integer : the server keeps `1.5` as it
     * was sent.
     *
     * @throws ReflectionException
     */
    public function testALifetimeMayBeADecimal() :void
    {
        $options = new TTLIndexOptions( [ IndexOptions::FIELDS => [ 'at' ] , IndexOptions::EXPIRE_AFTER => 1.5 ] ) ;

        $this->assertSame( 1.5 , $options->jsonSerialize()[ 'expireAfter' ] ) ;
    }
}
