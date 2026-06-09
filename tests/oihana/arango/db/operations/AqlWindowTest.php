<?php

namespace tests\oihana\arango\db\operations;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\enums\Char;

use function oihana\arango\db\operations\aqlWindow;

final class AqlWindowTest extends TestCase
{
    public function testReturnsEmptyStringWithoutAggregate(): void
    {
        $this->assertSame( Char::EMPTY , aqlWindow() );
        $this->assertSame( Char::EMPTY , aqlWindow([ AQL::PRECEDING => 1 , AQL::FOLLOWING => 1 ]) );
    }

    public function testRowBasedWithBothBounds(): void
    {
        $result = aqlWindow
        ([
            AQL::PRECEDING => 1 ,
            AQL::FOLLOWING => 1 ,
            AQL::AGGREGATE => [ 'rollingAvg' => 'AVG(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            'WINDOW { preceding: 1, following: 1 } AGGREGATE rollingAvg = AVG(doc.val)' ,
            $result ,
        );
    }

    public function testRangeBasedWithDurationStringAndNumericFollowing(): void
    {
        // rangeValue present -> range-based form with the WINDOW `WITH` keyword.
        // string bound -> single-quoted (ISO 8601 duration), numeric bound -> bare.
        $result = aqlWindow
        ([
            AQL::RANGE_VALUE => 'doc.time' ,
            AQL::PRECEDING   => 'PT1H' ,
            AQL::FOLLOWING   => 0 ,
            AQL::AGGREGATE   => [ 'total' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            "WINDOW doc.time WITH { preceding: 'PT1H', following: 0 } AGGREGATE total = SUM(doc.val)" ,
            $result ,
        );
    }

    public function testPrecedingOnlyOmitsFollowing(): void
    {
        // Running sum over all previous rows + current: only `preceding` is set.
        $result = aqlWindow
        ([
            AQL::PRECEDING => 0 ,
            AQL::AGGREGATE => [ 'runningSum' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            'WINDOW { preceding: 0 } AGGREGATE runningSum = SUM(doc.val)' ,
            $result ,
        );
    }

    public function testFollowingOnlyOmitsPreceding(): void
    {
        $result = aqlWindow
        ([
            AQL::FOLLOWING => 2 ,
            AQL::AGGREGATE => [ 'ahead' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            'WINDOW { following: 2 } AGGREGATE ahead = SUM(doc.val)' ,
            $result ,
        );
    }

    public function testEmptyRangeValueFallsBackToRowBased(): void
    {
        // A non-string / empty rangeValue must NOT emit the range-based `WITH` form.
        $result = aqlWindow
        ([
            AQL::RANGE_VALUE => Char::EMPTY ,
            AQL::PRECEDING   => 1 ,
            AQL::AGGREGATE   => [ 'n' => 'LENGTH(1)' ] ,
        ]);

        $this->assertSame( 'WINDOW { preceding: 1 } AGGREGATE n = LENGTH(1)' , $result );
    }

    public function testMultipleAggregates(): void
    {
        $result = aqlWindow
        ([
            AQL::PRECEDING => 1 ,
            AQL::FOLLOWING => 1 ,
            AQL::AGGREGATE =>
            [
                'minVal' => 'MIN(doc.val)' ,
                'maxVal' => 'MAX(doc.val)' ,
            ] ,
        ]);

        $this->assertSame
        (
            'WINDOW { preceding: 1, following: 1 } AGGREGATE minVal = MIN(doc.val), maxVal = MAX(doc.val)' ,
            $result ,
        );
    }

    public function testUnboundedRunningTotalIsSingleQuoted(): void
    {
        // String bounds are single-quoted — including the 'unbounded' keyword.
        $result = aqlWindow
        ([
            AQL::PRECEDING => 'unbounded' ,
            AQL::FOLLOWING => 0 ,
            AQL::AGGREGATE => [ 'runningTotal' => 'SUM(doc.val)' ] ,
        ]);

        $this->assertSame
        (
            "WINDOW { preceding: 'unbounded', following: 0 } AGGREGATE runningTotal = SUM(doc.val)" ,
            $result ,
        );
    }

    public function testNoBoundsEmitsEmptyBoundsObject(): void
    {
        // Both bounds null: the builder still emits an (empty) bounds object.
        $result = aqlWindow([ AQL::AGGREGATE => [ 'total' => 'SUM(doc.val)' ] ]);
        $this->assertSame( 'WINDOW {  } AGGREGATE total = SUM(doc.val)' , $result );
    }
}
