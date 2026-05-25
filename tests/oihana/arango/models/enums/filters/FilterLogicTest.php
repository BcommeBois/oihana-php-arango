<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Logic;
use oihana\arango\models\enums\filters\FilterLogic;

/**
 * Tests for FilterLogic class.
 */
class FilterLogicTest extends TestCase
{
    // ========================================
    // CONSTANTS
    // ========================================

    public function testConstantsAreDefined(): void
    {
        $this->assertSame( 'and' , FilterLogic::AND ) ;
        $this->assertSame( 'not' , FilterLogic::NOT ) ;
        $this->assertSame( 'or'  , FilterLogic::OR  ) ;
    }

    // ========================================
    // GET ALIAS
    // ========================================

    #[DataProvider('provideValidAliases')]
    public function testGetAliasReturnsCorrectLogicOperator( string $input , string $expected ): void
    {
        $this->assertSame( $expected , FilterLogic::getAlias( $input ) ) ;
    }

    public static function provideValidAliases(): array
    {
        return
        [
            'and' => [ FilterLogic::AND , Logic::AND ] ,
            'not' => [ FilterLogic::NOT , Logic::NOT ] ,
            'or'  => [ FilterLogic::OR  , Logic::OR  ] ,
        ];
    }

    public function testGetAliasReturnsDefaultForUnknownValue(): void
    {
        $this->assertSame( Logic::AND , FilterLogic::getAlias( 'unknown' ) ) ;
        $this->assertSame( Logic::AND , FilterLogic::getAlias( null      ) ) ;
        $this->assertSame( Logic::AND , FilterLogic::getAlias( ''        ) ) ;
    }

    public function testGetAliasReturnsCustomDefault(): void
    {
        $this->assertSame( 'custom' , FilterLogic::getAlias( 'unknown' , 'custom' ) ) ;
        $this->assertNull( FilterLogic::getAlias( 'unknown' , null ) ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        $this->assertTrue( FilterLogic::includes( FilterLogic::AND ) ) ;
        $this->assertTrue( FilterLogic::includes( FilterLogic::NOT ) ) ;
        $this->assertTrue( FilterLogic::includes( FilterLogic::OR  ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterLogic::includes( 'invalid' ) ) ;
        $this->assertFalse( FilterLogic::includes( 'xor'     ) ) ;
        $this->assertFalse( FilterLogic::includes( 'nand'    ) ) ;
        $this->assertFalse( FilterLogic::includes( ''        ) ) ;
        $this->assertFalse( FilterLogic::includes( null      ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterLogic::enums() ;

        $this->assertIsArray( $enums ) ;

        // Verify that all public constants are present
        $this->assertContains( FilterLogic::AND , $enums ) ;
        $this->assertContains( FilterLogic::NOT , $enums ) ;
        $this->assertContains( FilterLogic::OR  , $enums ) ;
    }

    // ========================================
    // USE CASES
    // ========================================

    public function testFilterLogicCanBeUsedAsFirstArrayElement(): void
    {
        $conditions =
        [
            FilterLogic::OR ,
            [ 'key' => 'name' , 'val' => 'John' ] ,
            [ 'key' => 'name' , 'val' => 'Jane' ] ,
        ];

        $this->assertTrue( FilterLogic::includes( $conditions[0] ) ) ;
        $this->assertSame( FilterLogic::OR , $conditions[0] ) ;
    }
}
