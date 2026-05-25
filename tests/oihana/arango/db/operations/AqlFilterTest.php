<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Logic;

use function oihana\arango\db\operations\aqlFilter;

class AqlFilterTest extends TestCase
{
    public function testFilterReturnsNullWhenConditionsIsNull(): void
    {
        $this->assertNull( aqlFilter() ) ;
    }

    public function testFilterReturnsNullWhenConditionsIsEmptyArray(): void
    {
        $this->assertNull(aqlFilter([]));
    }

    public function testFilterWithStringCondition(): void
    {
        $result = aqlFilter('age > 18' ) ;
        $this->assertSame('FILTER age > 18' , $result ) ;
    }

    public function testFilterWithSingleArrayCondition(): void
    {
        $result = aqlFilter(['age > 18']);
        $this->assertSame('FILTER age > 18', $result);
    }

    public function testFilterWithMultipleArrayConditionsUsingDefaultAnd(): void
    {
        $conditions = ['age > 18', 'status == "active"'];
        $expected   = 'FILTER age > 18 && status == "active"';

        $result = aqlFilter( $conditions ) ; // , Logic::AND
        $this->assertSame($expected, $result);
    }

    public function testFilterWithMultipleArrayConditionsUsingOr(): void
    {
        $conditions = ['age < 18', 'country == "FR"'];
        $expected   = 'FILTER age < 18 || country == "FR"';

        $result = aqlFilter($conditions, Logic::OR ) ;
        $this->assertSame($expected, $result);
    }

    public function testFilterIgnoresEmptyAndNullConditions(): void
    {
        $conditions = ['age > 18', null, '', 'status == "active"'];
        $expected   = 'FILTER age > 18 && status == "active"';

        $result = aqlFilter($conditions);
        $this->assertSame($expected, $result);
    }

    public function testFilterReturnsNullIfAllConditionsAreEmpty(): void
    {
        $conditions = ['', null, ' '];
        $filter = aqlFilter($conditions) ;

        $this->assertNull( $filter );
    }
}