<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\TestCase;

use oihana\arango\models\enums\filters\FilterType;

/**
 * Tests for FilterType class.
 */
class FilterTypeTest extends TestCase
{
    // ========================================
    // CONSTANTS
    // ========================================

    public function testConstantsAreDefined(): void
    {
        $this->assertSame( 'array'  , FilterType::ARRAY  ) ;
        $this->assertSame( 'bool'   , FilterType::BOOL   ) ;
        $this->assertSame( 'date'   , FilterType::DATE   ) ;
        $this->assertSame( 'geo'    , FilterType::GEO   ) ;
        $this->assertSame( 'number' , FilterType::NUMBER ) ;
        $this->assertSame( 'string' , FilterType::STRING ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        $this->assertTrue( FilterType::includes( FilterType::ARRAY  ) ) ;
        $this->assertTrue( FilterType::includes( FilterType::BOOL   ) ) ;
        $this->assertTrue( FilterType::includes( FilterType::DATE   ) ) ;
        $this->assertTrue( FilterType::includes( FilterType::GEO    ) ) ;
        $this->assertTrue( FilterType::includes( FilterType::NUMBER ) ) ;
        $this->assertTrue( FilterType::includes( FilterType::STRING ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterType::includes( 'invalid'  ) ) ;
        $this->assertFalse( FilterType::includes( 'integer'  ) ) ;
        $this->assertFalse( FilterType::includes( 'float'    ) ) ;
        $this->assertFalse( FilterType::includes( 'boolean'  ) ) ;
        $this->assertFalse( FilterType::includes( ''         ) ) ;
        $this->assertFalse( FilterType::includes( null       ) ) ;
        $this->assertFalse( FilterType::includes( 123        ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterType::enums() ;

        $this->assertIsArray( $enums ) ;
        $this->assertCount( 7 , $enums ) ;
        $this->assertContains( FilterType::ARRAY   , $enums ) ;
        $this->assertContains( FilterType::BOOL    , $enums ) ;
        $this->assertContains( FilterType::DATE    , $enums ) ;
        $this->assertContains( FilterType::GEO     , $enums ) ;
        $this->assertContains( FilterType::NUMBER  , $enums ) ;
        $this->assertContains( FilterType::STRING  , $enums ) ;
        $this->assertContains( FilterType::VIRTUAL , $enums ) ;
    }

    // ========================================
    // USE CASES
    // ========================================

    public function testFilterTypeCanBeUsedInMatchExpression(): void
    {
        $type = FilterType::STRING ;

        $result = match( $type )
        {
            FilterType::ARRAY  => 'array' ,
            FilterType::BOOL   => 'bool'  ,
            FilterType::DATE   => 'date'  ,
            FilterType::NUMBER => 'number' ,
            FilterType::STRING => 'string' ,
            default            => null ,
        };

        $this->assertSame( 'string' , $result ) ;
    }
}
