<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\ArrayComparator;
use oihana\arango\db\enums\Comparator;
use oihana\arango\models\enums\filters\FilterArrayComparator;

/**
 * Tests for FilterArrayComparator class.
 */
class FilterArrayComparatorTest extends TestCase
{
    // ========================================
    // CONSTANTS - ALL
    // ========================================

    public function testAllConstantsAreDefined(): void
    {
        $this->assertSame( 'all.eq'  , FilterArrayComparator::ALL_EQ  ) ;
        $this->assertSame( 'all.ge'  , FilterArrayComparator::ALL_GE  ) ;
        $this->assertSame( 'all.gt'  , FilterArrayComparator::ALL_GT  ) ;
        $this->assertSame( 'all.in'  , FilterArrayComparator::ALL_IN  ) ;
        $this->assertSame( 'all.le'  , FilterArrayComparator::ALL_LE  ) ;
        $this->assertSame( 'all.lt'  , FilterArrayComparator::ALL_LT  ) ;
        $this->assertSame( 'all.ne'  , FilterArrayComparator::ALL_NE  ) ;
        $this->assertSame( 'all.nin' , FilterArrayComparator::ALL_NIN ) ;
    }

    // ========================================
    // CONSTANTS - ANY
    // ========================================

    public function testAnyConstantsAreDefined(): void
    {
        $this->assertSame( 'any.eq'  , FilterArrayComparator::ANY_EQ  ) ;
        $this->assertSame( 'any.ge'  , FilterArrayComparator::ANY_GE  ) ;
        $this->assertSame( 'any.gt'  , FilterArrayComparator::ANY_GT  ) ;
        $this->assertSame( 'any.in'  , FilterArrayComparator::ANY_IN  ) ;
        $this->assertSame( 'any.le'  , FilterArrayComparator::ANY_LE  ) ;
        $this->assertSame( 'any.lt'  , FilterArrayComparator::ANY_LT  ) ;
        $this->assertSame( 'any.ne'  , FilterArrayComparator::ANY_NE  ) ;
        $this->assertSame( 'any.nin' , FilterArrayComparator::ANY_NIN ) ;
    }

    // ========================================
    // CONSTANTS - NONE
    // ========================================

    public function testNoneConstantsAreDefined(): void
    {
        $this->assertSame( 'none.eq'  , FilterArrayComparator::NONE_EQ  ) ;
        $this->assertSame( 'none.ge'  , FilterArrayComparator::NONE_GE  ) ;
        $this->assertSame( 'none.gt'  , FilterArrayComparator::NONE_GT  ) ;
        $this->assertSame( 'none.in'  , FilterArrayComparator::NONE_IN  ) ;
        $this->assertSame( 'none.le'  , FilterArrayComparator::NONE_LE  ) ;
        $this->assertSame( 'none.lt'  , FilterArrayComparator::NONE_LT  ) ;
        $this->assertSame( 'none.ne'  , FilterArrayComparator::NONE_NE  ) ;
        $this->assertSame( 'none.nin' , FilterArrayComparator::NONE_NIN ) ;
    }

    // ========================================
    // GET ALIAS - ALL
    // ========================================

    #[DataProvider('provideAllAliases')]
    public function testGetAliasReturnsCorrectAllComparator( string $input , string $expected ): void
    {
        $this->assertSame( $expected , FilterArrayComparator::getAlias( $input ) ) ;
    }

    public static function provideAllAliases(): array
    {
        return
        [
            'all.eq'  => [ FilterArrayComparator::ALL_EQ  , ArrayComparator::ALL . ' ' . Comparator::EQUAL                 ] ,
            'all.ge'  => [ FilterArrayComparator::ALL_GE  , ArrayComparator::ALL . ' ' . Comparator::GREATER_THAN_OR_EQUAL ] ,
            'all.gt'  => [ FilterArrayComparator::ALL_GT  , ArrayComparator::ALL . ' ' . Comparator::GREATER_THAN          ] ,
            'all.in'  => [ FilterArrayComparator::ALL_IN  , ArrayComparator::ALL . ' ' . Comparator::IN                    ] ,
            'all.le'  => [ FilterArrayComparator::ALL_LE  , ArrayComparator::ALL . ' ' . Comparator::LESS_THAN_OR_EQUAL    ] ,
            'all.lt'  => [ FilterArrayComparator::ALL_LT  , ArrayComparator::ALL . ' ' . Comparator::LESS_THAN             ] ,
            'all.ne'  => [ FilterArrayComparator::ALL_NE  , ArrayComparator::ALL . ' ' . Comparator::NOT_EQUAL             ] ,
            'all.nin' => [ FilterArrayComparator::ALL_NIN , ArrayComparator::ALL . ' ' . Comparator::NOT_IN                ] ,
        ];
    }

    // ========================================
    // GET ALIAS - ANY
    // ========================================

    #[DataProvider('provideAnyAliases')]
    public function testGetAliasReturnsCorrectAnyComparator( string $input , string $expected ): void
    {
        $this->assertSame( $expected , FilterArrayComparator::getAlias( $input ) ) ;
    }

    public static function provideAnyAliases(): array
    {
        return
        [
            'any.eq'  => [ FilterArrayComparator::ANY_EQ  , ArrayComparator::ANY . ' ' . Comparator::EQUAL                 ] ,
            'any.ge'  => [ FilterArrayComparator::ANY_GE  , ArrayComparator::ANY . ' ' . Comparator::GREATER_THAN_OR_EQUAL ] ,
            'any.gt'  => [ FilterArrayComparator::ANY_GT  , ArrayComparator::ANY . ' ' . Comparator::GREATER_THAN          ] ,
            'any.in'  => [ FilterArrayComparator::ANY_IN  , ArrayComparator::ANY . ' ' . Comparator::IN                    ] ,
            'any.le'  => [ FilterArrayComparator::ANY_LE  , ArrayComparator::ANY . ' ' . Comparator::LESS_THAN_OR_EQUAL    ] ,
            'any.lt'  => [ FilterArrayComparator::ANY_LT  , ArrayComparator::ANY . ' ' . Comparator::LESS_THAN             ] ,
            'any.ne'  => [ FilterArrayComparator::ANY_NE  , ArrayComparator::ANY . ' ' . Comparator::NOT_EQUAL             ] ,
            'any.nin' => [ FilterArrayComparator::ANY_NIN , ArrayComparator::ANY . ' ' . Comparator::NOT_IN                ] ,
        ];
    }

    // ========================================
    // GET ALIAS - NONE
    // ========================================

    #[DataProvider('provideNoneAliases')]
    public function testGetAliasReturnsCorrectNoneComparator( string $input , string $expected ): void
    {
        $this->assertSame( $expected , FilterArrayComparator::getAlias( $input ) ) ;
    }

    public static function provideNoneAliases(): array
    {
        return
        [
            'none.eq'  => [ FilterArrayComparator::NONE_EQ  , ArrayComparator::NONE . ' ' . Comparator::EQUAL                 ] ,
            'none.ge'  => [ FilterArrayComparator::NONE_GE  , ArrayComparator::NONE . ' ' . Comparator::GREATER_THAN_OR_EQUAL ] ,
            'none.gt'  => [ FilterArrayComparator::NONE_GT  , ArrayComparator::NONE . ' ' . Comparator::GREATER_THAN          ] ,
            'none.in'  => [ FilterArrayComparator::NONE_IN  , ArrayComparator::NONE . ' ' . Comparator::IN                    ] ,
            'none.le'  => [ FilterArrayComparator::NONE_LE  , ArrayComparator::NONE . ' ' . Comparator::LESS_THAN_OR_EQUAL    ] ,
            'none.lt'  => [ FilterArrayComparator::NONE_LT  , ArrayComparator::NONE . ' ' . Comparator::LESS_THAN             ] ,
            'none.ne'  => [ FilterArrayComparator::NONE_NE  , ArrayComparator::NONE . ' ' . Comparator::NOT_EQUAL             ] ,
            'none.nin' => [ FilterArrayComparator::NONE_NIN , ArrayComparator::NONE . ' ' . Comparator::NOT_IN                ] ,
        ];
    }

    // ========================================
    // GET ALIAS - DEFAULT
    // ========================================

    public function testGetAliasReturnsNullForUnknownValue(): void
    {
        $this->assertNull( FilterArrayComparator::getAlias( 'unknown' ) ) ;
        $this->assertNull( FilterArrayComparator::getAlias( null      ) ) ;
        $this->assertNull( FilterArrayComparator::getAlias( ''        ) ) ;
        $this->assertNull( FilterArrayComparator::getAlias( 'eq'      ) ) ; // simple comparator, not array
    }

    public function testGetAliasReturnsCustomDefault(): void
    {
        $this->assertSame( 'custom' , FilterArrayComparator::getAlias( 'unknown' , 'custom' ) ) ;
        $this->assertSame( '==' , FilterArrayComparator::getAlias( 'unknown' , '==' ) ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        // ALL
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_EQ  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_GE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_GT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_IN  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_LE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_LT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_NE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ALL_NIN ) ) ;

        // ANY
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_EQ  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_GE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_GT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_IN  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_LE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_LT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_NE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::ANY_NIN ) ) ;

        // NONE
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_EQ  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_GE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_GT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_IN  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_LE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_LT  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_NE  ) ) ;
        $this->assertTrue( FilterArrayComparator::includes( FilterArrayComparator::NONE_NIN ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterArrayComparator::includes( 'invalid' ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( 'eq'      ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( 'all'     ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( 'any'     ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( 'none'    ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( ''        ) ) ;
        $this->assertFalse( FilterArrayComparator::includes( null      ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterArrayComparator::enums() ;

        $this->assertIsArray( $enums ) ;

        // Verify that all public constants are present
        $this->assertContains( FilterArrayComparator::ALL_EQ   , $enums ) ;
        $this->assertContains( FilterArrayComparator::ALL_NIN  , $enums ) ;
        $this->assertContains( FilterArrayComparator::ANY_EQ   , $enums ) ;
        $this->assertContains( FilterArrayComparator::ANY_NIN  , $enums ) ;
        $this->assertContains( FilterArrayComparator::NONE_EQ  , $enums ) ;
        $this->assertContains( FilterArrayComparator::NONE_NIN , $enums ) ;
    }
}
