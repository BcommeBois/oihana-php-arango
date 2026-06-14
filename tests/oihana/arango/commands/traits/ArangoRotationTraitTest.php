<?php

namespace tests\oihana\arango\commands\traits;

use DateTimeImmutable;
use InvalidArgumentException;

use oihana\arango\commands\rotation\Archive;
use oihana\arango\commands\rotation\RotationPolicy;
use oihana\arango\commands\traits\ArangoRotationTrait;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bare host exposing the rotation engine of {@see ArangoRotationTrait}.
 */
class ArangoRotationTraitHost
{
    use ArangoRotationTrait ;

    public function bucket( string $name ) :string
    {
        return $this->archiveBucket( $name ) ;
    }

    public function date( string $name ) :DateTimeImmutable
    {
        return $this->archiveDate( $name ) ;
    }

    public function size( int|string $value ) :int
    {
        return $this->parseRetentionSize( $value ) ;
    }
}

/**
 * Unit coverage for {@see ArangoRotationTrait}.
 */
#[CoversTrait(ArangoRotationTrait::class)]
#[CoversClass(Archive::class)]
#[CoversClass(RotationPolicy::class)]
class ArangoRotationTraitTest extends TestCase
{
    private array $tmpDirs = [] ;

    protected function tearDown() :void
    {
        foreach( $this->tmpDirs as $dir )
        {
            foreach( glob( $dir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $file )
            {
                @unlink( $file ) ;
            }
            @rmdir( $dir ) ;
        }
    }

    private function host() :ArangoRotationTraitHost
    {
        return new ArangoRotationTraitHost() ;
    }

    /** A synthetic archive entry dated $daysAgo days ago. */
    private function archive( string $path , string $bucket , int $daysAgo , int $size = 100 ) :Archive
    {
        return new Archive
        ([
            Archive::PATH   => $path ,
            Archive::BUCKET => $bucket ,
            Archive::DATE   => new DateTimeImmutable( "-$daysAgo days" ) ,
            Archive::SIZE   => $size ,
        ]) ;
    }

    /** A RotationPolicy from a plain init array. */
    private function policy( array $init = [] ) :RotationPolicy
    {
        return new RotationPolicy( $init ) ;
    }

    // ------------------------------------------------------------------ planRotation

    public function testNoPolicyDeletesNothing() :void
    {
        $archives = [ $this->archive( 'a0' , 'db' , 0 ) , $this->archive( 'a1' , 'db' , 1 ) ] ;
        $this->assertSame( [] , $this->host()->planRotation( $archives , $this->policy() ) ) ;
    }

    public function testKeepDeletesTheOldestBeyondN() :void
    {
        $archives =
        [
            $this->archive( 'a0' , 'db' , 0 ) ,
            $this->archive( 'a1' , 'db' , 1 ) ,
            $this->archive( 'a2' , 'db' , 2 ) ,
            $this->archive( 'a3' , 'db' , 3 ) ,
        ] ;
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 2 ] ) ) ;
        sort( $out ) ;
        $this->assertSame( [ 'a2' , 'a3' ] , $out ) ;
    }

    public function testMaxAgeDeletesOlderThanCutoffButKeepsNewest() :void
    {
        $archives =
        [
            $this->archive( 'b0' , 'db' , 0 ) ,
            $this->archive( 'b1' , 'db' , 10 ) ,
            $this->archive( 'b2' , 'db' , 20 ) ,
        ] ;
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'cutoff' => new DateTimeImmutable( '-5 days' ) ] ) ) ;
        sort( $out ) ;
        $this->assertSame( [ 'b1' , 'b2' ] , $out ) ;
    }

    public function testConservativeWhenBothKeepAndCutoff() :void
    {
        $archives =
        [
            $this->archive( 'c0' , 'db' , 0 ) ,
            $this->archive( 'c1' , 'db' , 1 ) ,
            $this->archive( 'c2' , 'db' , 2 ) ,   // beyond keep=2 but young → kept
            $this->archive( 'c3' , 'db' , 20 ) ,  // beyond keep AND old → deleted
        ] ;
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 2 , 'cutoff' => new DateTimeImmutable( '-5 days' ) ] ) ) ;
        $this->assertSame( [ 'c3' ] , $out ) ;
    }

    public function testFloorKeepsTheNewestEvenWithKeepZero() :void
    {
        $archives = [ $this->archive( 'e0' , 'db' , 0 ) , $this->archive( 'e1' , 'db' , 1 ) ] ;
        $this->assertSame( [ 'e1' ] , $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 0 ] ) ) ) ;
    }

    public function testBucketsAreIndependentAndOverridable() :void
    {
        $archives =
        [
            $this->archive( 'x0' , 'A' , 0 ) , $this->archive( 'x1' , 'A' , 1 ) , $this->archive( 'x2' , 'A' , 2 ) ,
            $this->archive( 'y0' , 'B' , 0 ) , $this->archive( 'y1' , 'B' , 1 ) ,
        ] ;

        $out = $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 1 ] ) ) ;
        sort( $out ) ;
        $this->assertSame( [ 'x1' , 'x2' , 'y1' ] , $out ) ;

        $out = $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 1 , 'buckets' => [ 'A' => 2 ] ] ) ) ;
        sort( $out ) ;
        $this->assertSame( [ 'x2' , 'y1' ] , $out ) ;
    }

    public function testMaxTotalDeletesOldestUntilUnderCap() :void
    {
        $archives =
        [
            $this->archive( 'f0' , 'db' , 0 ) , $this->archive( 'f1' , 'db' , 1 ) ,
            $this->archive( 'f2' , 'db' , 2 ) , $this->archive( 'f3' , 'db' , 3 ) , $this->archive( 'f4' , 'db' , 4 ) ,
        ] ;
        // 5×100 = 500 bytes, cap 250 → drop oldest (f2,f3,f4) leaving 200.
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'maxTotalBytes' => 250 ] ) ) ;
        sort( $out ) ;
        $this->assertSame( [ 'f2' , 'f3' , 'f4' ] , $out ) ;
    }

    public function testMaxTotalUnderCapDeletesNothing() :void
    {
        $archives = [ $this->archive( 'g0' , 'db' , 0 , 50 ) , $this->archive( 'g1' , 'db' , 1 , 50 ) ] ;
        $this->assertSame( [] , $this->host()->planRotation( $archives , $this->policy( [ 'maxTotalBytes' => 1000 ] ) ) ) ;
    }

    public function testMaxTotalRespectsFloorAndCurrent() :void
    {
        // Two buckets, tiny cap: the floor (≥1 per bucket) caps how much can go.
        $archives =
        [
            $this->archive( 'a0' , 'A' , 0 , 100 ) , $this->archive( 'a1' , 'A' , 1 , 100 ) ,
            $this->archive( 'b0' , 'B' , 0 , 100 ) , $this->archive( 'b1' , 'B' , 2 , 100 ) ,
        ] ;
        // Cap 100, current = a0 (cannot be deleted). Oldest first: b1, a1, b0, a0.
        // b1 deletable, a1 deletable, b0 is last of B → kept, a0 is current → kept.
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'maxTotalBytes' => 100 ] ) , 'a0' ) ;
        sort( $out ) ;
        $this->assertSame( [ 'a1' , 'b1' ] , $out ) ; // floor + current stop further pruning
    }

    public function testCurrentArchiveIsNeverDeleted() :void
    {
        $archives = [ $this->archive( 'g0' , 'db' , 0 ) , $this->archive( 'g1' , 'db' , 1 ) , $this->archive( 'g2' , 'db' , 2 ) ] ;
        $out = $this->host()->planRotation( $archives , $this->policy( [ 'keep' => 1 ] ) , 'g1' ) ;
        $this->assertSame( [ 'g2' ] , $out ) ; // g1 (current) and g0 (newest) survive
    }

    // ------------------------------------------------------------------ resolveRetentionPolicy

    public function testResolvePolicyParsesEveryField() :void
    {
        $policy = $this->host()->resolveRetentionPolicy
        ([
            'keep'      => 5 ,
            'max_age'   => 'P1M' ,
            'max_total' => '1G' ,
            'buckets'   => [ 'db' => 2 ] ,
        ]) ;

        $this->assertSame( 5 , $policy->keep ) ;
        $this->assertSame( [ 'db' => 2 ] , $policy->buckets ) ;
        $this->assertInstanceOf( DateTimeImmutable::class , $policy->cutoff ) ;
        $this->assertSame( 1024 ** 3 , $policy->maxTotalBytes ) ;
    }

    public function testResolvePolicyDefaultsAndEmpty() :void
    {
        $policy = $this->host()->resolveRetentionPolicy( [] ) ;
        $this->assertNull( $policy->keep ) ;
        $this->assertSame( [] , $policy->buckets ) ;
        $this->assertNull( $policy->cutoff ) ;
        $this->assertNull( $policy->maxTotalBytes ) ;

        // empty strings are ignored
        $policy = $this->host()->resolveRetentionPolicy( [ 'max_age' => '' , 'max_total' => '' ] ) ;
        $this->assertNull( $policy->cutoff ) ;
        $this->assertNull( $policy->maxTotalBytes ) ;
    }

    public function testResolvePolicyInvalidMaxAgeThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'ISO 8601 duration' ) ;
        $this->host()->resolveRetentionPolicy( [ 'max_age' => '30 days' ] ) ;
    }

    public function testMaxAgeCutoffIsCalendarAccurate() :void
    {
        // P1M one month ago is clearly within the last 40 days and before "now".
        $cutoff = $this->host()->resolveRetentionPolicy( [ 'max_age' => 'P1M' ] )->cutoff ;
        $this->assertLessThan( new DateTimeImmutable( 'now' ) , $cutoff ) ;
        $this->assertGreaterThan( new DateTimeImmutable( '-40 days' ) , $cutoff ) ;
    }

    // ------------------------------------------------------------------ retentionEnabled

    public function testRetentionEnabled() :void
    {
        $this->assertFalse( $this->host()->retentionEnabled( [] ) ) ;
        $this->assertFalse( $this->host()->retentionEnabled( [ 'auto' => true ] ) ) ;       // auto alone is not a criterion
        $this->assertFalse( $this->host()->retentionEnabled( [ 'keep' => '' ] ) ) ;
        $this->assertTrue ( $this->host()->retentionEnabled( [ 'keep' => 3 ] ) ) ;
        $this->assertTrue ( $this->host()->retentionEnabled( [ 'max_age' => 'P1Y' ] ) ) ;
        $this->assertTrue ( $this->host()->retentionEnabled( [ 'max_total' => '5G' ] ) ) ;
    }

    // ------------------------------------------------------------------ parse helpers

    public function testParseRetentionSize() :void
    {
        $host = $this->host() ;
        $this->assertSame( 1024 ** 3 * 5 , $host->size( '5G' ) ) ;
        $this->assertSame( 1024 ** 2 * 500 , $host->size( '500M' ) ) ;
        $this->assertSame( (int) ( 1.5 * 1024 ** 3 ) , $host->size( '1.5G' ) ) ;
        $this->assertSame( 2048 , $host->size( '2k' ) ) ;
        $this->assertSame( 1024 , $host->size( '1024' ) ) ;
        $this->assertSame( 4096 , $host->size( 4096 ) ) ;
        $this->assertSame( 0 , $host->size( -1 ) ) ;       // negative int clamped
        $this->assertSame( 700 , $host->size( '700B' ) ) ; // trailing B
    }

    public function testParseRetentionSizeInvalidThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'max_total' ) ;
        $this->host()->size( 'huge' ) ;
    }

    public function testArchiveBucketAndDate() :void
    {
        $host = $this->host() ;
        $this->assertSame( 'mydb-partial-pre-migration' , $host->bucket( '2026-06-01T14:30:00-mydb-partial-pre-migration.tar.gz' ) ) ;
        $this->assertSame( 'mydb' , $host->bucket( '2026-06-01T14:30:00-mydb.tar.gz.enc' ) ) ;
        $this->assertSame( 'short' , $host->bucket( 'short.tar' ) ) ; // stem shorter than the date prefix

        $this->assertSame( '2026-06-01' , $host->date( '2026-06-01T14:30:00-mydb.tar.gz' )->format( 'Y-m-d' ) ) ;
        // Unparsable date → epoch fallback.
        $this->assertSame( '1970-01-01' , $host->date( 'zzzzzzzzzzzzzzzzzzz-x.tar' )->format( 'Y-m-d' ) ) ;
    }

    // ------------------------------------------------------------------ pruneDumps

    /** Creates a dump dir and touches archive files (name => size). */
    private function dumpDir( array $files ) :string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rot_' . bin2hex( random_bytes( 6 ) ) ;
        mkdir( $dir , 0o777 , true ) ;
        $this->tmpDirs[] = $dir ;

        foreach( $files as $name => $size )
        {
            file_put_contents( $dir . DIRECTORY_SEPARATOR . $name , str_repeat( 'x' , $size ) ) ;
        }
        return $dir ;
    }

    private function io( BufferedOutput $output ) :SymfonyStyle
    {
        return new SymfonyStyle( new ArrayInput( [] ) , $output ) ;
    }

    public function testPruneDumpsDeletesAndKeeps() :void
    {
        $dir = $this->dumpDir
        ([
            '2026-06-01T00:00:00-mydb.tar.gz' => 10 ,
            '2026-06-02T00:00:00-mydb.tar.gz' => 10 ,
            '2026-06-03T00:00:00-mydb.tar.gz' => 10 ,
        ]) ;

        $output = new BufferedOutput() ;
        $count  = $this->host()->pruneDumps( $dir , $this->host()->resolveRetentionPolicy( [ 'keep' => 1 ] ) , null , false , $this->io( $output ) ) ;

        $this->assertSame( 2 , $count ) ;
        $this->assertFileDoesNotExist( $dir . '/2026-06-01T00:00:00-mydb.tar.gz' ) ;
        $this->assertFileDoesNotExist( $dir . '/2026-06-02T00:00:00-mydb.tar.gz' ) ;
        $this->assertFileExists( $dir . '/2026-06-03T00:00:00-mydb.tar.gz' ) ; // newest kept
    }

    public function testPruneDumpsDryRunDeletesNothing() :void
    {
        $dir = $this->dumpDir
        ([
            '2026-06-01T00:00:00-mydb.tar.gz' => 10 ,
            '2026-06-02T00:00:00-mydb.tar.gz' => 10 ,
        ]) ;

        $output = new BufferedOutput() ;
        $count  = $this->host()->pruneDumps( $dir , $this->host()->resolveRetentionPolicy( [ 'keep' => 1 ] ) , null , true , $this->io( $output ) ) ;

        $this->assertSame( 1 , $count ) ;
        $this->assertFileExists( $dir . '/2026-06-01T00:00:00-mydb.tar.gz' ) ; // preserved on dry run
        $this->assertStringContainsString( 'would delete' , $output->fetch() ) ;
    }

    public function testPruneDumpsNothingToPrune() :void
    {
        $dir    = $this->dumpDir( [ '2026-06-01T00:00:00-mydb.tar.gz' => 10 ] ) ;
        $output = new BufferedOutput() ;

        $count = $this->host()->pruneDumps( $dir , $this->host()->resolveRetentionPolicy( [ 'keep' => 5 ] ) , null , false , $this->io( $output ) ) ;

        $this->assertSame( 0 , $count ) ;
        $this->assertStringContainsString( 'nothing to prune' , $output->fetch() ) ;
    }

    public function testPruneDumpsExcludesTheCurrentArchive() :void
    {
        $dir = $this->dumpDir
        ([
            '2026-06-01T00:00:00-mydb.tar.gz' => 10 ,
            '2026-06-02T00:00:00-mydb.tar.gz' => 10 ,
        ]) ;
        $current = $dir . '/2026-06-01T00:00:00-mydb.tar.gz' ; // the OLDER one, marked current

        $output = new BufferedOutput() ;
        $count  = $this->host()->pruneDumps( $dir , $this->host()->resolveRetentionPolicy( [ 'keep' => 1 ] ) , $current , false , $this->io( $output ) ) ;

        $this->assertSame( 0 , $count ) ; // older is "current" (kept), newer is rank 0 (kept)
        $this->assertFileExists( $current ) ;
    }
}
