<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlWindowBounds;

final class AqlWindowBoundsTest extends TestCase
{
    public function testBothNumericBounds(): void
    {
        $this->assertSame( '{ preceding: 1, following: 1 }' , aqlWindowBounds( 1 , 1 ) );
    }

    public function testPrecedingOnly(): void
    {
        $this->assertSame( '{ preceding: 0 }' , aqlWindowBounds( 0 , null ) );
    }

    public function testFollowingOnly(): void
    {
        $this->assertSame( '{ following: 2 }' , aqlWindowBounds( null , 2 ) );
    }

    public function testStringBoundsAreSingleQuoted(): void
    {
        // ISO 8601 duration and the 'unbounded' keyword.
        $this->assertSame( "{ preceding: 'PT1H', following: 0 }" , aqlWindowBounds( 'PT1H' , 0 ) );
        $this->assertSame( "{ preceding: 'unbounded', following: 0 }" , aqlWindowBounds( 'unbounded' , 0 ) );
    }

    public function testFloatBound(): void
    {
        $this->assertSame( '{ preceding: 1.5 }' , aqlWindowBounds( 1.5 , null ) );
    }

    public function testNoBoundsEmitsEmptyObject(): void
    {
        $this->assertSame( '{  }' , aqlWindowBounds( null , null ) );
    }
}
