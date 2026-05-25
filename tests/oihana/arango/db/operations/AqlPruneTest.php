<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Logic;

use function oihana\arango\db\operations\aqlPrune;

class AqlPruneTest extends TestCase
{
    public function testPruneReturnsNullWhenConditionsIsNull(): void
    {
        $this->assertNull( aqlPrune() ) ;
    }

    public function testPruneReturnsNullWhenConditionsIsEmptyArray(): void
    {
        $this->assertNull(aqlPrune([]));
    }

    public function testPruneWithStringCondition(): void
    {
        $result = aqlPrune('v.age > 40' ) ;
        $this->assertSame('PRUNE v.age > 40' , $result ) ;
    }

    public function testPruneWithSingleArrayCondition(): void
    {
        $result = aqlPrune(['v.age > 40']);
        $this->assertSame('PRUNE v.age > 40', $result);
    }

    public function testPruneWithMultipleArrayConditionsUsingDefaultAnd(): void
    {
        $conditions = ['v.age > 40', 'e.type == "friend"'];
        $expected   = 'PRUNE v.age > 40 && e.type == "friend"';

        $result = aqlPrune( $conditions ) ; // , Logic::AND
        $this->assertSame($expected, $result);
    }

    public function testPruneWithMultipleArrayConditionsUsingOr(): void
    {
        $conditions = ['v.status == "inactive"', 'e.label == "blocked"'];
        $expected   = 'PRUNE v.status == "inactive" || e.label == "blocked"';

        $result = aqlPrune($conditions, Logic::OR ) ;
        $this->assertSame($expected, $result);
    }

    public function testPruneIgnoresEmptyAndNullConditions(): void
    {
        $conditions = ['v.age > 18', null, '', 'e.type == "friend"'];
        $expected   = 'PRUNE v.age > 18 && e.type == "friend"';

        $result = aqlPrune($conditions);
        $this->assertSame($expected, $result);
    }

    public function testPruneReturnsNullIfAllConditionsAreEmpty(): void
    {
        $conditions = ['', null, ' '];
        $prune = aqlPrune($conditions) ;

        $this->assertNull( $prune );
    }
}