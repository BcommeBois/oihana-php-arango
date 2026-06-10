<?php

namespace tests\oihana\arango\db;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use oihana\arango\clients\cursor\Cursor;
use oihana\arango\db\results\ExecutionStats;
use oihana\arango\db\results\ProfileResult;

/**
 * Unit coverage for {@see \oihana\arango\db\ArangoDB::getStats()} and
 * {@see \oihana\arango\db\ArangoDB::getProfile()} — both read the last cursor's
 * `extra` and wrap it into typed value-objects.
 */
#[AllowMockObjectsWithoutExpectations]
class ProfileFacadeTest extends ArangoDBTestCase
{
    /**
     * @return array<string,mixed>
     */
    private function extra() : array
    {
        return
        [
            'stats'   => [ 'scannedFull' => 50 , 'filtered' => 11 , 'executionTime' => 0.0004 ] ,
            'profile' => [ 'parsing' => 0.00001 , 'executing' => 0.0002 ] ,
            'warnings'=> [] ,
        ] ;
    }

    public function testGetStatsWrapsCursorStats() : void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getExtra' )->willReturn( $this->extra() ) ;

        $stats = $this->newArangoDB( null , null , $cursor )->getStats() ;

        $this->assertInstanceOf( ExecutionStats::class , $stats ) ;
        $this->assertSame( 50 , $stats->scannedFull() ) ;
        $this->assertSame( 11 , $stats->filtered() ) ;
    }

    public function testGetStatsWithNoCursorIsEmpty() : void
    {
        $stats = $this->newArangoDB()->getStats() ;
        $this->assertSame( 0 , $stats->scannedFull() ) ;
    }

    public function testGetStatsToleratesNonArrayStats() : void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getExtra' )->willReturn( [ 'stats' => 'oops' ] ) ;

        $this->assertSame( 0 , $this->newArangoDB( null , null , $cursor )->getStats()->scannedFull() ) ;
    }

    public function testGetProfileWrapsCursorExtra() : void
    {
        $cursor = $this->createMock( Cursor::class ) ;
        $cursor->method( 'getExtra' )->willReturn( $this->extra() ) ;

        $profile = $this->newArangoDB( null , null , $cursor )->getProfile() ;

        $this->assertInstanceOf( ProfileResult::class , $profile ) ;
        $this->assertSame( [ 'parsing' , 'executing' ] , array_keys( $profile->timings() ) ) ;
        $this->assertSame( 50 , $profile->stats()->scannedFull() ) ;
    }
}
