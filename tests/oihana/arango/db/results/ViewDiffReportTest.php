<?php

namespace tests\oihana\arango\db\results;

use oihana\arango\db\enums\ViewDiffStatus;
use oihana\arango\db\results\ViewDiffReport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see ViewDiffReport} — the typed result of a View
 * declaration ↔ server comparison.
 *
 * @package tests\oihana\arango\db\results
 * @author  Marc Alcaraz
 */
#[CoversClass( ViewDiffReport::class )]
class ViewDiffReportTest extends TestCase
{
    public function testDefaultsAreEmptyChangesAndNotApplied() :void
    {
        $report = new ViewDiffReport( 'placesView' , ViewDiffStatus::MISSING ) ;

        $this->assertSame( 'placesView' , $report->name ) ;
        $this->assertSame( ViewDiffStatus::MISSING , $report->status ) ;
        $this->assertSame( [] , $report->changes ) ;
        $this->assertFalse( $report->applied ) ;
    }

    public function testInSyncOnlyForTheInSyncStatus() :void
    {
        $this->assertTrue ( new ViewDiffReport( 'v' , ViewDiffStatus::IN_SYNC )->inSync() ) ;
        $this->assertFalse( new ViewDiffReport( 'v' , ViewDiffStatus::DRIFTED )->inSync() ) ;
        $this->assertFalse( new ViewDiffReport( 'v' , ViewDiffStatus::MISSING )->inSync() ) ;
        $this->assertFalse( new ViewDiffReport( 'v' , ViewDiffStatus::INVALID )->inSync() ) ;
        $this->assertFalse( new ViewDiffReport( 'v' , ViewDiffStatus::UNREACHABLE )->inSync() ) ;
    }

    public function testCarriesChangesAndAppliedFlag() :void
    {
        $report = new ViewDiffReport( 'v' , ViewDiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] , true ) ;

        $this->assertSame( [ 'places.fields.name : not indexed on the server' ] , $report->changes ) ;
        $this->assertTrue( $report->applied ) ;
    }
}
