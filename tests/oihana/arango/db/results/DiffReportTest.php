<?php

namespace tests\oihana\arango\db\results;

use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see DiffReport} — the typed result of a
 * declaration ↔ server comparison (collection, indexes or View).
 *
 * @package tests\oihana\arango\db\results
 * @author  Marc Alcaraz
 */
#[CoversClass( DiffReport::class )]
class DiffReportTest extends TestCase
{
    public function testDefaultsAreEmptyChangesNotAppliedAndViewKind() :void
    {
        $report = new DiffReport( 'placesView' , DiffStatus::MISSING ) ;

        $this->assertSame( 'placesView' , $report->name ) ;
        $this->assertSame( DiffStatus::MISSING , $report->status ) ;
        $this->assertSame( [] , $report->changes ) ;
        $this->assertFalse( $report->applied ) ;
        $this->assertSame( DiffKind::VIEW , $report->kind ) ;
    }

    public function testCarriesAnExplicitKind() :void
    {
        $report = new DiffReport( 'places' , DiffStatus::IN_SYNC , kind : DiffKind::INDEXES ) ;

        $this->assertSame( DiffKind::INDEXES , $report->kind ) ;
    }

    public function testInSyncOnlyForTheInSyncStatus() :void
    {
        $this->assertTrue ( new DiffReport( 'v' , DiffStatus::IN_SYNC )->inSync() ) ;
        $this->assertFalse( new DiffReport( 'v' , DiffStatus::DRIFTED )->inSync() ) ;
        $this->assertFalse( new DiffReport( 'v' , DiffStatus::MISSING )->inSync() ) ;
        $this->assertFalse( new DiffReport( 'v' , DiffStatus::INVALID )->inSync() ) ;
        $this->assertFalse( new DiffReport( 'v' , DiffStatus::UNREACHABLE )->inSync() ) ;
    }

    public function testCarriesChangesAndAppliedFlag() :void
    {
        $report = new DiffReport( 'v' , DiffStatus::DRIFTED , [ 'places.fields.name : not indexed on the server' ] , true ) ;

        $this->assertSame( [ 'places.fields.name : not indexed on the server' ] , $report->changes ) ;
        $this->assertTrue( $report->applied ) ;
    }
}
