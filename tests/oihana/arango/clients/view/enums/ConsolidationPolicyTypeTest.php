<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\ConsolidationPolicyType ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ConsolidationPolicyType} — the `type` of an ArangoSearch
 * view's `consolidationPolicy`.
 */
#[CoversClass( ConsolidationPolicyType::class )]
class ConsolidationPolicyTypeTest extends TestCase
{
    public function testCanonicalConstantValues() :void
    {
        $this->assertSame( 'bytes_accum' , ConsolidationPolicyType::BYTES_ACCUM ) ;
        $this->assertSame( 'tier'        , ConsolidationPolicyType::TIER        ) ;
    }

    public function testEnumsContainsEveryType() :void
    {
        $enums = ConsolidationPolicyType::enums() ;

        $this->assertCount( 2 , $enums ) ;
        $this->assertContains( ConsolidationPolicyType::BYTES_ACCUM , $enums ) ;
        $this->assertContains( ConsolidationPolicyType::TIER        , $enums ) ;
    }

    public function testIncludesRecognisesTypesAndRejectsOthers() :void
    {
        $this->assertTrue ( ConsolidationPolicyType::includes( 'bytes_accum' ) ) ;
        $this->assertTrue ( ConsolidationPolicyType::includes( 'tier'        ) ) ;

        $this->assertFalse( ConsolidationPolicyType::includes( 'count' ) ) ;
    }
}
