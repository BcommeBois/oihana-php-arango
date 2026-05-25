<?php

namespace tests\oihana\arango\clients\collection\enums ;

use oihana\arango\clients\collection\enums\OnDuplicate ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see OnDuplicate} — wire-level strategies accepted by the
 * `onDuplicate` query parameter of the ArangoDB bulk import endpoint.
 */
#[CoversClass( OnDuplicate::class )]
class OnDuplicateTest extends TestCase
{
    public function testCanonicalValues() :void
    {
        $this->assertSame( 'error'   , OnDuplicate::ERROR   ) ;
        $this->assertSame( 'ignore'  , OnDuplicate::IGNORE  ) ;
        $this->assertSame( 'replace' , OnDuplicate::REPLACE ) ;
        $this->assertSame( 'update'  , OnDuplicate::UPDATE  ) ;
    }

    public function testIncludesRecognisesKnownStrategies() :void
    {
        foreach ( [ 'error' , 'ignore' , 'replace' , 'update' ] as $strategy )
        {
            $this->assertTrue( OnDuplicate::includes( $strategy ) , "Expected '$strategy' to be recognised by OnDuplicate" ) ;
        }
        $this->assertFalse( OnDuplicate::includes( 'unknown' ) ) ;
    }
}
