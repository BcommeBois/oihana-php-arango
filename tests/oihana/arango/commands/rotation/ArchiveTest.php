<?php

namespace tests\oihana\arango\commands\rotation;

use DateTimeImmutable;

use oihana\arango\commands\rotation\Archive;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see Archive} value object.
 */
#[CoversClass(Archive::class)]
class ArchiveTest extends TestCase
{
    public function testDefaultsWhenNoInit() :void
    {
        $archive = new Archive() ;

        $this->assertSame( '' , $archive->path ) ;
        $this->assertSame( '' , $archive->bucket ) ;
        $this->assertNull( $archive->date ) ;
        $this->assertSame( 0 , $archive->size ) ;
    }

    public function testHydratesFromArray() :void
    {
        $date = new DateTimeImmutable( '2026-06-01T14:30:00' ) ;

        $archive = new Archive
        ([
            Archive::PATH   => '/dumps/2026-06-01T14:30:00-mydb.tar.gz' ,
            Archive::BUCKET => 'mydb' ,
            Archive::DATE   => $date ,
            Archive::SIZE   => 4096 ,
        ]) ;

        $this->assertSame( '/dumps/2026-06-01T14:30:00-mydb.tar.gz' , $archive->path ) ;
        $this->assertSame( 'mydb' , $archive->bucket ) ;
        $this->assertSame( $date , $archive->date ) ;
        $this->assertSame( 4096 , $archive->size ) ;
    }

    public function testHydratesFromObjectAndCasts() :void
    {
        $archive = new Archive( (object) [ Archive::PATH => 123 , Archive::SIZE => '2048' ] ) ;

        $this->assertSame( '123' , $archive->path ) ;   // cast to string
        $this->assertSame( 2048 , $archive->size ) ;    // cast to int
        $this->assertSame( '' , $archive->bucket ) ;    // omitted → default
    }

    public function testConstantsAreThePropertyNames() :void
    {
        $this->assertSame( 'path' , Archive::PATH ) ;
        $this->assertSame( 'bucket' , Archive::BUCKET ) ;
        $this->assertSame( 'date' , Archive::DATE ) ;
        $this->assertSame( 'size' , Archive::SIZE ) ;
    }
}
