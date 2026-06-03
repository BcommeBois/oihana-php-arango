<?php

namespace tests\oihana\arango\db\enums;

use oihana\arango\db\enums\Logic;

use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see Logic::normalize()} — keeps OR, otherwise falls back to AND.
 */
final class LogicTest extends TestCase
{
    public function testNormalizeKeepsOr() :void
    {
        $this->assertSame( Logic::OR , Logic::normalize( Logic::OR ) ) ;
    }

    public function testNormalizeKeepsAnd() :void
    {
        $this->assertSame( Logic::AND , Logic::normalize( Logic::AND ) ) ;
    }

    public function testNormalizeDefaultsToAndForNull() :void
    {
        $this->assertSame( Logic::AND , Logic::normalize( null ) ) ;
    }

    public function testNormalizeDefaultsToAndForUnknownOperator() :void
    {
        $this->assertSame( Logic::AND , Logic::normalize( 'XOR' ) ) ;
    }
}
