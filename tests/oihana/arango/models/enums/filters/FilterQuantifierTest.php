<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\models\enums\filters\FilterQuantifier;

/**
 * Tests for the {@see FilterQuantifier} enum: the named quantifier codes and
 * their AQL keyword aliases (the numeric `AT LEAST (n)` form is resolved by
 * {@see \oihana\arango\db\helpers\resolveQuantifier()}, not listed here).
 */
class FilterQuantifierTest extends TestCase
{
    public function testConstantsAreDefined(): void
    {
        $this->assertSame( 'all'     , FilterQuantifier::ALL      ) ;
        $this->assertSame( 'any'     , FilterQuantifier::ANY      ) ;
        $this->assertSame( 'none'    , FilterQuantifier::NONE     ) ;
        $this->assertSame( 'atLeast' , FilterQuantifier::AT_LEAST ) ;
    }

    public function testGetAliasMapsNamedQuantifiers(): void
    {
        $this->assertSame( ArrayComparator::ALL  , FilterQuantifier::getAlias( FilterQuantifier::ALL  ) ) ;
        $this->assertSame( ArrayComparator::ANY  , FilterQuantifier::getAlias( FilterQuantifier::ANY  ) ) ;
        $this->assertSame( ArrayComparator::NONE , FilterQuantifier::getAlias( FilterQuantifier::NONE ) ) ;
    }

    public function testGetAliasReturnsDefaultForUnknown(): void
    {
        $this->assertNull( FilterQuantifier::getAlias( 'bogus' ) ) ;
        $this->assertSame( 'X' , FilterQuantifier::getAlias( 'bogus' , 'X' ) ) ;
    }

    public function testAtLeastIsNotAnAlias(): void
    {
        // The numeric quantifier carries a threshold, so it is built dynamically
        // by resolveQuantifier() rather than mapped through getAlias().
        $this->assertNull( FilterQuantifier::getAlias( FilterQuantifier::AT_LEAST ) ) ;
    }
}
