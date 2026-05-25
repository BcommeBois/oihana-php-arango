<?php

namespace tests\oihana\arango\models\enums\filters;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Comparator;
use oihana\arango\models\enums\filters\FilterComparator;

/**
 * Tests for FilterComparator class.
 */
class FilterComparatorTest extends TestCase
{
    // ========================================
    // CONSTANTS
    // ========================================

    public function testConstantsAreDefined(): void
    {
        $this->assertSame( 'eq'     , FilterComparator::EQ     ) ;
        $this->assertSame( 'ge'     , FilterComparator::GE     ) ;
        $this->assertSame( 'gt'     , FilterComparator::GT     ) ;
        $this->assertSame( 'in'     , FilterComparator::IN     ) ;
        $this->assertSame( 'le'     , FilterComparator::LE     ) ;
        $this->assertSame( 'lt'     , FilterComparator::LT     ) ;
        $this->assertSame( 'like'   , FilterComparator::LIKE   ) ;
        $this->assertSame( 'match'  , FilterComparator::MATCH  ) ;
        $this->assertSame( 'ne'     , FilterComparator::NE     ) ;
        $this->assertSame( 'nin'    , FilterComparator::NIN    ) ;
        $this->assertSame( 'nlike'  , FilterComparator::NLIKE  ) ;
        $this->assertSame( 'nmatch' , FilterComparator::NMATCH ) ;
    }

    // ========================================
    // GET ALIAS
    // ========================================

    #[DataProvider('provideValidAliases')]
    public function testGetAliasReturnsCorrectComparator( string $input , string $expected ): void
    {
        $this->assertSame( $expected , FilterComparator::getAlias( $input ) ) ;
    }

    public static function provideValidAliases(): array
    {
        return
        [
            'equals'                 => [ FilterComparator::EQ     , Comparator::EQUAL                 ] ,
            'greater than or equals' => [ FilterComparator::GE     , Comparator::GREATER_THAN_OR_EQUAL ] ,
            'greater than'           => [ FilterComparator::GT     , Comparator::GREATER_THAN          ] ,
            'in'                     => [ FilterComparator::IN     , Comparator::IN                    ] ,
            'less than or equals'    => [ FilterComparator::LE     , Comparator::LESS_THAN_OR_EQUAL    ] ,
            'less than'              => [ FilterComparator::LT     , Comparator::LESS_THAN             ] ,
            'like'                   => [ FilterComparator::LIKE   , Comparator::LIKE                  ] ,
            'match'                  => [ FilterComparator::MATCH  , Comparator::MATCH                 ] ,
            'not equals'             => [ FilterComparator::NE     , Comparator::NOT_EQUAL             ] ,
            'not in'                 => [ FilterComparator::NIN    , Comparator::NOT_IN                ] ,
            'not like'               => [ FilterComparator::NLIKE  , Comparator::NOT_LIKE              ] ,
            'not match'              => [ FilterComparator::NMATCH , Comparator::NOT_MATCH             ] ,
        ];
    }

    public function testGetAliasReturnsDefaultForUnknownValue(): void
    {
        $this->assertSame( Comparator::EQUAL , FilterComparator::getAlias( 'unknown' ) ) ;
        $this->assertSame( Comparator::EQUAL , FilterComparator::getAlias( null      ) ) ;
        $this->assertSame( Comparator::EQUAL , FilterComparator::getAlias( ''        ) ) ;
    }

    public function testGetAliasReturnsCustomDefault(): void
    {
        $this->assertSame( 'custom' , FilterComparator::getAlias( 'unknown' , 'custom' ) ) ;
        $this->assertNull( FilterComparator::getAlias( 'unknown' , null ) ) ;
    }

    // ========================================
    // INCLUDES
    // ========================================

    public function testIncludesReturnsTrueForValidConstants(): void
    {
        $this->assertTrue( FilterComparator::includes( FilterComparator::EQ    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::GE    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::GT    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::IN    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::LE    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::LT    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::LIKE  ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::MATCH ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::NE    ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::NIN   ) ) ;
        $this->assertTrue( FilterComparator::includes( FilterComparator::NLIKE ) ) ;
    }

    public function testIncludesReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse( FilterComparator::includes( 'invalid' ) ) ;
        $this->assertFalse( FilterComparator::includes( ''        ) ) ;
        $this->assertFalse( FilterComparator::includes( null      ) ) ;
        $this->assertFalse( FilterComparator::includes( 123       ) ) ;
    }

    // ========================================
    // ENUMS
    // ========================================

    public function testEnumsReturnsAllConstants(): void
    {
        $enums = FilterComparator::enums() ;

        $this->assertIsArray( $enums ) ;
        $this->assertContains( FilterComparator::EQ     , $enums ) ;
        $this->assertContains( FilterComparator::GE     , $enums ) ;
        $this->assertContains( FilterComparator::GT     , $enums ) ;
        $this->assertContains( FilterComparator::IN     , $enums ) ;
        $this->assertContains( FilterComparator::LE     , $enums ) ;
        $this->assertContains( FilterComparator::LT     , $enums ) ;
        $this->assertContains( FilterComparator::LIKE   , $enums ) ;
        $this->assertContains( FilterComparator::MATCH  , $enums ) ;
        $this->assertContains( FilterComparator::NE     , $enums ) ;
        $this->assertContains( FilterComparator::NIN    , $enums ) ;
        $this->assertContains( FilterComparator::NLIKE  , $enums ) ;
        $this->assertContains( FilterComparator::NMATCH , $enums ) ;
    }
}
