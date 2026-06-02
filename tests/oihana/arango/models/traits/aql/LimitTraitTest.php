<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\models\traits\aql\LimitTrait;

use PHPUnit\Framework\TestCase;
use xyz\oihana\schema\Pagination;

/**
 * Bare host exposing {@see LimitTrait} for isolated testing.
 */
class LimitTraitStub
{
    use LimitTrait ;
}

/**
 * Characterization coverage for {@see LimitTrait::prepareLimit()} — a thin
 * adapter over aqlLimit() driven by the Pagination::LIMIT / OFFSET keys. With a
 * binds array the clause is parameterised; without one the values are inlined.
 */
class LimitTraitTest extends TestCase
{
    private function stub() :LimitTraitStub
    {
        return new LimitTraitStub() ;
    }

    public function testLimitWithoutBindsIsInlined() :void
    {
        $this->assertSame( 'LIMIT 10' , $this->stub()->prepareLimit( [ Pagination::LIMIT => 10 ] ) ) ;
    }

    public function testLimitAndOffsetWithBindsAreParameterised() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LIMIT @offset, @limit' ,
            $this->stub()->prepareLimit( [ Pagination::LIMIT => 10 , Pagination::OFFSET => 5 ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'limit' => 10 , 'offset' => 5 ] , $binds ) ;
    }

    public function testLimitWithBindsButNoOffsetBindsOnlyLimit() :void
    {
        $binds = [] ;
        $this->assertSame( 'LIMIT @limit' , $this->stub()->prepareLimit( [ Pagination::LIMIT => 10 ] , $binds ) ) ;
        $this->assertSame( [ 'limit' => 10 ] , $binds ) ;
    }

    public function testZeroLimitReturnsEmptyClause() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->prepareLimit( [] , $binds ) ) ;
        $this->assertSame( [] , $binds ) ;
    }
}
