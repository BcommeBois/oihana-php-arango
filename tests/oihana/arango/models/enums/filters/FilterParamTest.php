<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\TestCase;

use oihana\arango\models\enums\filters\FilterParam;

/**
 * Tests for FilterParam class.
 */
class FilterParamTest extends TestCase
{
    // ========================================
    // CONSTANTS
    // ========================================

    public function testConstantsAreDefined(): void
    {
        $this->assertSame( 'all'    , FilterParam::ALL    ) ;
        $this->assertSame( 'alt'    , FilterParam::ALT    ) ;
        $this->assertSame( 'at'     , FilterParam::AT     ) ;
        $this->assertSame( 'exp'    , FilterParam::EXP    ) ;
        $this->assertSame( 'format' , FilterParam::FORMAT ) ;
        $this->assertSame( 'key'    , FilterParam::KEY    ) ;
        $this->assertSame( 'length' , FilterParam::LENGTH ) ;
        $this->assertSame( 'match'  , FilterParam::MATCH  ) ;
        $this->assertSame( 'method' , FilterParam::METHOD ) ;
        $this->assertSame( 'op'     , FilterParam::OP     ) ;
        $this->assertSame( 'pos'    , FilterParam::POS    ) ;
        $this->assertSame( 'scope'  , FilterParam::SCOPE  ) ;
        $this->assertSame( 'start'  , FilterParam::START  ) ;
        $this->assertSame( 'tz'     , FilterParam::TZ     ) ;
        $this->assertSame( 'type'   , FilterParam::TYPE   ) ;
        $this->assertSame( 'unit'   , FilterParam::UNIT   ) ;
        $this->assertSame( 'val'    , FilterParam::VAL    ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        $this->assertTrue( FilterParam::includes( FilterParam::ALL    ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::ALT    ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::AT     ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::EXP    ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::FORMAT ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::KEY    ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::LENGTH ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::MATCH  ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::METHOD ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::OP     ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::POS    ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::SCOPE  ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::START  ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::TZ     ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::TYPE   ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::UNIT   ) ) ;
        $this->assertTrue( FilterParam::includes( FilterParam::VAL    ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterParam::includes( 'invalid'  ) ) ;
        $this->assertFalse( FilterParam::includes( 'value'    ) ) ;
        $this->assertFalse( FilterParam::includes( 'operator' ) ) ;
        $this->assertFalse( FilterParam::includes( ''         ) ) ;
        $this->assertFalse( FilterParam::includes( null       ) ) ;
        $this->assertFalse( FilterParam::includes( 123        ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterParam::enums() ;

        $this->assertIsArray( $enums ) ;
        $this->assertContains( FilterParam::ALL    , $enums ) ;
        $this->assertContains( FilterParam::ALT    , $enums ) ;
        $this->assertContains( FilterParam::AT     , $enums ) ;
        $this->assertContains( FilterParam::EXP    , $enums ) ;
        $this->assertContains( FilterParam::FORMAT , $enums ) ;
        $this->assertContains( FilterParam::KEY    , $enums ) ;
        $this->assertContains( FilterParam::LENGTH , $enums ) ;
        $this->assertContains( FilterParam::MATCH  , $enums ) ;
        $this->assertContains( FilterParam::METHOD , $enums ) ;
        $this->assertContains( FilterParam::OP     , $enums ) ;
        $this->assertContains( FilterParam::POS    , $enums ) ;
        $this->assertContains( FilterParam::SCOPE  , $enums ) ;
        $this->assertContains( FilterParam::START  , $enums ) ;
        $this->assertContains( FilterParam::TZ     , $enums ) ;
        $this->assertContains( FilterParam::TYPE   , $enums ) ;
        $this->assertContains( FilterParam::UNIT   , $enums ) ;
        $this->assertContains( FilterParam::VAL    , $enums ) ;
    }

    // ========================================
    // USE CASES
    // ========================================

    public function testFilterParamCanBeUsedAsArrayKeys(): void
    {
        $filter =
        [
            FilterParam::KEY => 'name' ,
            FilterParam::VAL => 'John' ,
            FilterParam::OP  => 'eq'   ,
        ];

        $this->assertSame( 'name' , $filter[ FilterParam::KEY ] ) ;
        $this->assertSame( 'John' , $filter[ FilterParam::VAL ] ) ;
        $this->assertSame( 'eq'   , $filter[ FilterParam::OP  ] ) ;
    }

    public function testFilterParamCanBeUsedWithNullCoalescing(): void
    {
        $filter = [ FilterParam::KEY => 'name' ] ;

        $this->assertSame( 'name'    , $filter[ FilterParam::KEY ] ?? null ) ;
        $this->assertNull( $filter[ FilterParam::VAL ] ?? null ) ;
        $this->assertSame( 'default' , $filter[ FilterParam::OP  ] ?? 'default' ) ;
    }
}
