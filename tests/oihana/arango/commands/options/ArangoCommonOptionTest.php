<?php

namespace tests\oihana\arango\commands\options;

use oihana\arango\commands\options\ArangoCommonOption;
use oihana\arango\commands\options\ArangoDumpOption;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see ArangoCommonOption::hasGroup()}.
 *
 * Exercised through {@see ArangoDumpOption}, which composes the trait.
 */
#[CoversTrait(ArangoCommonOption::class)]
class ArangoCommonOptionTest extends TestCase
{
    public function testHasGroupReturnsTrueForAnOptionListedInTheExtraSet() :void
    {
        $this->assertTrue( ArangoDumpOption::hasGroup( 'customFlag' , [ 'customFlag' , 'other' ] ) ) ;
    }

    public function testHasGroupReturnsTrueForAKnownGroupedOption() :void
    {
        $this->assertTrue( ArangoDumpOption::hasGroup( ArangoDumpOption::SERVER_DATABASE ) ) ;
        $this->assertTrue( ArangoDumpOption::hasGroup( ArangoDumpOption::LOG_LEVEL ) ) ;
    }

    public function testHasGroupReturnsFalseForAnUngroupedOption() :void
    {
        $this->assertFalse( ArangoDumpOption::hasGroup( 'definitelyNotAGroupedOption' ) ) ;
    }
}
