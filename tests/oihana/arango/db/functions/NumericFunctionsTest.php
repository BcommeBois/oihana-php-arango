<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\NumericFunction;
use oihana\arango\db\enums\PercentileMethod;

use function oihana\arango\db\functions\numerics\abs;
use function oihana\arango\db\functions\numerics\acos;
use function oihana\arango\db\functions\numerics\asin;
use function oihana\arango\db\functions\numerics\atan;
use function oihana\arango\db\functions\numerics\atan2;
use function oihana\arango\db\functions\numerics\average;
use function oihana\arango\db\functions\numerics\ceil;
use function oihana\arango\db\functions\numerics\cos;
use function oihana\arango\db\functions\numerics\cosSimilarity;
use function oihana\arango\db\functions\numerics\degrees;
use function oihana\arango\db\functions\numerics\exp;
use function oihana\arango\db\functions\numerics\exp2;
use function oihana\arango\db\functions\numerics\floor;
use function oihana\arango\db\functions\numerics\log;
use function oihana\arango\db\functions\numerics\log10;
use function oihana\arango\db\functions\numerics\log2;
use function oihana\arango\db\functions\numerics\max;
use function oihana\arango\db\functions\numerics\median;
use function oihana\arango\db\functions\numerics\min;
use function oihana\arango\db\functions\numerics\percentile;
use function oihana\arango\db\functions\numerics\pi;
use function oihana\arango\db\functions\numerics\pow;
use function oihana\arango\db\functions\numerics\product;
use function oihana\arango\db\functions\numerics\radians;
use function oihana\arango\db\functions\numerics\rand;
use function oihana\arango\db\functions\numerics\range;
use function oihana\arango\db\functions\numerics\round;
use function oihana\arango\db\functions\numerics\sin;
use function oihana\arango\db\functions\numerics\sqrt;
use function oihana\arango\db\functions\numerics\sum;
use function oihana\arango\db\functions\numerics\tan;
use function oihana\arango\db\helpers\aqlArray;

class NumericFunctionsTest extends TestCase
{
    public function testAbs(): void
    {
        $this->assertSame( 'ABS(5)' , abs(5) );
        $this->assertSame( 'ABS(2.5)' , abs(2.5) );
        $this->assertSame( 'ABS(2.5)' , abs('2.5') );
    }

    public function testAcos(): void
    {
        $this->assertSame( 'ACOS(0.5)' , acos(0.5) );
    }

    public function testAsin(): void
    {
        $this->assertSame( 'ASIN(0.5)' , asin(0.5) );
    }

    public function testAtan(): void
    {
        $this->assertSame( 'ATAN(0.5)' , atan(0.5) );
    }

    public function testAtan_2(): void
    {
        $this->assertSame( 'ATAN2(2,3)' , atan2(2, 3) );
    }

    public function testCeil(): void
    {
        $this->assertSame( 'CEIL(1.2)' , ceil(1.2) );
    }

    public function testCos(): void
    {
        $this->assertSame( 'COS(3.14)' , cos(3.14) );
    }

    public function testCosineSimilarity(): void
    {
        $this->assertSame
        (
            NumericFunction::COSINE_SIMILARITY . "([1,2],[3,4])",
            cosSimilarity("[1,2]", "[3,4]")
        );
    }

    public function testDegrees(): void
    {
        $this->assertSame( 'DEGREES(3.14)' , degrees(3.14) );
    }

    public function testExp(): void
    {
        $this->assertSame( 'EXP(2)' , exp(2) );
        $this->assertSame( 'EXP(2.4)' , exp(2.4) );
    }

    public function testExp_2(): void
    {
        $this->assertSame( 'EXP2(2)'   , exp2(2) );
        $this->assertSame( 'EXP2(2.4)' , exp2(2.4) );
    }

    public function testFloor(): void
    {
        $this->assertSame( 'FLOOR(2)'   , floor(2) );
        $this->assertSame( 'FLOOR(2.4)' , floor(2.4) );
    }

    public function testLog(): void
    {
        $this->assertSame("LOG(2)"  , log(2 ) );
        $this->assertSame("LOG(2.2)", log(2.2 ) );
    }

    public function testLog_2(): void
    {
        $this->assertSame("LOG2(2)"  , log2(2 ) );
        $this->assertSame("LOG2(2.2)", log2(2.2 ) );
    }

    public function testLog_10(): void
    {
        $this->assertSame("LOG10(2)"  , log10(2 ) );
        $this->assertSame("LOG10(2.2)", log10(2.2 ) );
    }

    public function testPow(): void
    {
        $this->assertSame("POW(2,3)", pow(2, 3));
    }

    public function testRadians(): void
    {
        $this->assertSame( 'RADIANS(180)'  , radians(180   ) ) ;
        $this->assertSame( 'RADIANS(1)'    , radians('1' ) ) ;
        $this->assertSame( 'RADIANS(1.25)' , radians(1.25  ) ) ;
    }

    public function testRound(): void
    {
        $this->assertSame( 'ROUND(2)'   , round(2   ) ) ;
        $this->assertSame( 'ROUND(1.2)' , round(1.2 ) ) ;
    }

    public function testSin(): void
    {
        $this->assertSame( 'SIN(3)'    , sin(3      ) ) ;
        $this->assertSame( 'SIN(3.14)' , sin(3.14   ) ) ;
        $this->assertSame( 'SIN(3.14)' , sin('3.14' ) ) ;
    }

    public function testSqrt(): void
    {
        $this->assertSame( 'SQRT(9)'    , sqrt(9      ) ) ;
        $this->assertSame( 'SQRT(9.14)' , sqrt(9.14   ) ) ;
    }

    public function testTan(): void
    {
        $this->assertSame( 'TAN(10)'  , tan(10   ) ) ;
        $this->assertSame( 'TAN(10)'  , tan('10' ) ) ;
        $this->assertSame( 'TAN(5.6)' , tan(5.6  ) ) ;
    }

    // ---------- Function with arrays (unique argument "array-like") ----------

    public function testAverage(): void
    {
        $this->assertSame( 'AVERAGE([1,2,3])' , average( aqlArray( [1,2,3] ) ) ) ;
    }

    public function testMax(): void
    {
        $this->assertSame( 'MAX([1,2,3])' , max( aqlArray( [1,2,3] ) ) ) ;
    }

    public function testMedian(): void
    {
        $this->assertSame( 'MEDIAN([1,2,3])' , median( aqlArray( [1,2,3] ) ) ) ;
    }

    public function testMin(): void
    {
        $this->assertSame( 'MIN([1,2,3])' , min( aqlArray( [1,2,3] ) ) ) ;
    }

    public function testProduct(): void
    {
        $this->assertSame( 'PRODUCT([1,2,3])' , product( aqlArray( [1,2,3] ) ) ) ;
    }

    public function testSum(): void
    {
        $this->assertSame( 'SUM([1,2,3])' , sum( aqlArray( [1,2,3] ) ) ) ;
    }

    // ---------- Function without arguments ----------

    public function testPi(): void
    {
        $this->assertSame(NumericFunction::PI . "()", pi() );
    }

    public function testRand(): void
    {
        $this->assertSame(NumericFunction::RAND . "()", rand() );
    }

    // ---------- Range ----------

    public function testRangeWithDefaultStep(): void
    {
        $this->assertSame(
            NumericFunction::RANGE . "(1,5)",
            range(1, 5)
        );
    }

    public function testRangeWithCustomStep(): void
    {
        $this->assertSame(
            NumericFunction::RANGE . "(1,10,2.5)",
            range(1, 10, 2.5)
        );
    }

    // ---------- Special cases

    public function testPercentileWithRankMethodUsesEmptyMarker(): void
    {
        // method !== INTERPOLATION → Char::EMPTY en 3e param
        $this->assertSame(
            "PERCENTILE([1,2,3],50)",
            percentile("[1,2,3]", 50, PercentileMethod::RANK)
        );
    }

    public function testPercentileWithInterpolationMethod(): void
    {
        $this->assertSame(
            "PERCENTILE([1,2,3],75," . PercentileMethod::INTERPOLATION . ")",
            percentile("[1,2,3]", 75, PercentileMethod::INTERPOLATION)
        );
    }

    public function testPercentilePositionIsClippedLow(): void
    {
        // clip(-20 → 0)
        $this->assertSame(
            "PERCENTILE([1,2,3],0)",
            percentile("[1,2,3]", -20, PercentileMethod::RANK)
        );
    }

    public function testPercentilePositionIsClippedHigh(): void
    {
        // clip(150 → 100)
        $this->assertSame
        (
            "PERCENTILE([1,2,3],100)",
            percentile("[1,2,3]", 150, PercentileMethod::RANK)
        );
    }
}
