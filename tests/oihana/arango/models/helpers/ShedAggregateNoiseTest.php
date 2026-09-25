<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\shedAggregateNoise;

/**
 * What a grouped row keeps and what it loses once its summed aggregates are shed.
 */
class ShedAggregateNoiseTest extends TestCase
{
    public function testTheNamedFloatsAreShedAndTheRestIsLeftAlone() :void
    {
        $row = (object) [ 'year' => 2025 , 'total' => 1380913.4299999992 , 'mean' => 45292.249999999985 , 'biggest' => 202799.4000000001 , 'n' => 3 , 'label' => 'a' ] ;

        $shed = shedAggregateNoise( $row , [ 'total' , 'mean' , 'missing' ] ) ;

        $this->assertSame( $row , $shed , 'the object is altered in place and handed back' ) ;
        $this->assertSame( 1380913.43 , $shed->total ) ;
        $this->assertSame( 45292.25   , $shed->mean ) ;
        $this->assertSame( 202799.4000000001 , $shed->biggest ) ;
        $this->assertSame( 3   , $shed->n ) ;
        $this->assertSame( 'a' , $shed->label ) ;
        $this->assertFalse( property_exists( $shed , 'missing' ) ) ;
    }

    public function testAnArrayRowComesBackAsAnArray() :void
    {
        $shed = shedAggregateNoise( [ 'total' => 0.1 + 0.2 , 'n' => 12400 , 'mean' => null ] , [ 'total' , 'n' , 'mean' ] ) ;

        $this->assertSame( [ 'total' => 0.3 , 'n' => 12400 , 'mean' => null ] , $shed ) ;
    }

    public function testNoNameNoChange() :void
    {
        $row = (object) [ 'total' => 0.1 + 0.2 ] ;

        $this->assertSame( 0.1 + 0.2 , shedAggregateNoise( $row , [] )->total ) ;
    }
}
