<?php

namespace tests\oihana\arango\db\results;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\results\ExecutionStats;
use oihana\arango\db\results\ProfileResult;

class ProfileResultTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function fixture() : array
    {
        return
        [
            'warnings' => [] ,
            'stats'    => [ 'scannedFull' => 50 , 'filtered' => 11 , 'executionTime' => 0.0004 ] ,
            'plan'     => [ 'nodes' => [] ] ,
            'profile'  =>
            [
                'parsing'         => 0.00001 ,
                'optimizing plan' => 0.00005 ,
                'executing'       => 0.00020 ,
                'finalizing'      => 0.00001 ,
            ] ,
        ] ;
    }

    public function testStatsIsTyped() : void
    {
        $p = new ProfileResult( $this->fixture() );
        $this->assertInstanceOf( ExecutionStats::class , $p->stats() );
        $this->assertSame( 50 , $p->stats()->scannedFull() );
        $this->assertSame( 11 , $p->stats()->filtered() );
    }

    public function testTimingsAndTotalTime() : void
    {
        $p = new ProfileResult( $this->fixture() );

        $this->assertSame
        (
            [ 'parsing' , 'optimizing plan' , 'executing' , 'finalizing' ] ,
            array_keys( $p->timings() )
        );
        $this->assertEqualsWithDelta( 0.00027 , $p->totalTime() , 1e-9 );
    }

    public function testWarningsPlanAndRaw() : void
    {
        $p = new ProfileResult( $this->fixture() );
        $this->assertSame( [] , $p->warnings() );
        $this->assertSame( [ 'nodes' => [] ] , $p->plan() );
        $this->assertSame( $this->fixture() , $p->raw() );
    }

    public function testDefaultsWhenEmpty() : void
    {
        $p = new ProfileResult( [] );
        $this->assertInstanceOf( ExecutionStats::class , $p->stats() );
        $this->assertSame( [] , $p->timings() );
        $this->assertSame( 0.0 , $p->totalTime() );
        $this->assertSame( [] , $p->warnings() );
        $this->assertSame( [] , $p->plan() );
        $this->assertSame( [] , $p->raw() );
    }
}
