<?php

namespace tests\oihana\arango\db\enums\functions;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\ArrayFunction;

/**
 * Every constant of this enumeration names an AQL array function, so its value is
 * the function name itself.
 *
 * The expectations below are written as **literals**, never as the constant they
 * check: asserting `ArrayFunction::COUNT === ArrayFunction::COUNT` compares a value
 * to itself and stays green whatever the constant holds. That is exactly how
 * `CONTAINS_ARRAY` could carry `'COUNT'` — a silent duplicate of its neighbour —
 * under a fully covered, fully green suite. No helper in `src/` reads this
 * enumeration, so nothing else could have caught it either.
 */
class ArrayFunctionTest extends TestCase
{
    public function testEveryConstantHoldsItsOwnAQLFunctionName(): void
    {
        $this->assertSame( 'APPEND'         , ArrayFunction::APPEND         ) ;
        $this->assertSame( 'CONTAINS_ARRAY' , ArrayFunction::CONTAINS_ARRAY ) ;
        $this->assertSame( 'COUNT'          , ArrayFunction::COUNT          ) ;
        $this->assertSame( 'COUNT_DISTINCT' , ArrayFunction::COUNT_DISTINCT ) ;
        $this->assertSame( 'COUNT_UNIQUE'   , ArrayFunction::COUNT_UNIQUE   ) ;
        $this->assertSame( 'FIRST'          , ArrayFunction::FIRST          ) ;
        $this->assertSame( 'FLATTEN'        , ArrayFunction::FLATTEN        ) ;
        $this->assertSame( 'INTERLEAVE'     , ArrayFunction::INTERLEAVE     ) ;
        $this->assertSame( 'INTERSECTION'   , ArrayFunction::INTERSECTION   ) ;
        $this->assertSame( 'JACCARD'        , ArrayFunction::JACCARD        ) ;
        $this->assertSame( 'LAST'           , ArrayFunction::LAST           ) ;
        $this->assertSame( 'LENGTH'         , ArrayFunction::LENGTH         ) ;
        $this->assertSame( 'MINUS'          , ArrayFunction::MINUS          ) ;
        $this->assertSame( 'NTH'            , ArrayFunction::NTH            ) ;
        $this->assertSame( 'OUTERSECTION'   , ArrayFunction::OUTERSECTION   ) ;
        $this->assertSame( 'POP'            , ArrayFunction::POP            ) ;
        $this->assertSame( 'POSITION'       , ArrayFunction::POSITION       ) ;
        $this->assertSame( 'PUSH'           , ArrayFunction::PUSH           ) ;
        $this->assertSame( 'REMOVE_NTH'     , ArrayFunction::REMOVE_NTH     ) ;
        $this->assertSame( 'REMOVE_VALUE'   , ArrayFunction::REMOVE_VALUE   ) ;
        $this->assertSame( 'REMOVE_VALUES'  , ArrayFunction::REMOVE_VALUES  ) ;
        $this->assertSame( 'REPLACE_NTH'    , ArrayFunction::REPLACE_NTH    ) ;
        $this->assertSame( 'REVERSE'        , ArrayFunction::REVERSE        ) ;
        $this->assertSame( 'SHIFT'          , ArrayFunction::SHIFT          ) ;
        $this->assertSame( 'SLICE'          , ArrayFunction::SLICE          ) ;
        $this->assertSame( 'SORTED'         , ArrayFunction::SORTED         ) ;
        $this->assertSame( 'SORTED_UNIQUE'  , ArrayFunction::SORTED_UNIQUE  ) ;
        $this->assertSame( 'UNION'          , ArrayFunction::UNION          ) ;
        $this->assertSame( 'UNION_DISTINCT' , ArrayFunction::UNION_DISTINCT ) ;
        $this->assertSame( 'UNIQUE'         , ArrayFunction::UNIQUE         ) ;
        $this->assertSame( 'UNSHIFT'        , ArrayFunction::UNSHIFT        ) ;
    }
}
