<?php

namespace tests\oihana\arango\db\results;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\Statistic;
use oihana\arango\db\results\ExecutionStats;

class ExecutionStatsTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function fixture() : array
    {
        return
        [
            'writesExecuted'  => 0 ,
            'writesIgnored'   => 0 ,
            'documentLookups' => 4 ,
            'scannedFull'     => 50 ,
            'scannedIndex'    => 11 ,
            'cacheHits'       => 7 ,
            'cacheMisses'     => 2 ,
            'filtered'        => 11 ,
            'httpRequests'    => 3 ,
            'executionTime'   => 0.000414 ,
            'peakMemoryUsage' => 65536 ,
            'fullCount'       => 39 ,
        ] ;
    }

    public function testTypedGetters() : void
    {
        $s = new ExecutionStats( $this->fixture() );

        $this->assertSame( 0.000414 , $s->executionTime() );
        $this->assertSame( 50 , $s->scannedFull() );
        $this->assertSame( 11 , $s->scannedIndex() );
        $this->assertSame( 11 , $s->filtered() );
        $this->assertSame( 65536 , $s->peakMemoryUsage() );
        $this->assertSame( 0 , $s->writesExecuted() );
        $this->assertSame( 0 , $s->writesIgnored() );
        $this->assertSame( 4 , $s->documentLookups() );
        $this->assertSame( 3 , $s->httpRequests() );
        $this->assertSame( 7 , $s->cacheHits() );
        $this->assertSame( 2 , $s->cacheMisses() );
        $this->assertSame( 39 , $s->fullCount() );
    }

    public function testGenericGetAndRaw() : void
    {
        $s = new ExecutionStats( $this->fixture() );
        $this->assertSame( 50 , $s->get( Statistic::SCANNED_FULL ) );
        $this->assertSame( 'fallback' , $s->get( 'missing' , 'fallback' ) );
        $this->assertSame( $this->fixture() , $s->raw() );
    }

    public function testDefaultsWhenEmpty() : void
    {
        $s = new ExecutionStats( [] );
        $this->assertSame( 0.0 , $s->executionTime() );
        $this->assertSame( 0 , $s->scannedFull() );
        $this->assertSame( 0 , $s->scannedIndex() );
        $this->assertSame( 0 , $s->filtered() );
        $this->assertSame( 0 , $s->peakMemoryUsage() );
        $this->assertSame( 0 , $s->writesExecuted() );
        $this->assertSame( 0 , $s->writesIgnored() );
        $this->assertSame( 0 , $s->documentLookups() );
        $this->assertSame( 0 , $s->httpRequests() );
        $this->assertSame( 0 , $s->cacheHits() );
        $this->assertSame( 0 , $s->cacheMisses() );
        $this->assertNull( $s->fullCount() );
    }
}
