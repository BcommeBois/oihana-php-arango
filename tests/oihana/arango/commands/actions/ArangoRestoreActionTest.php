<?php

namespace tests\oihana\arango\commands\actions;

use oihana\arango\commands\actions\ArangoRestoreAction;

use oihana\enums\Char;
use oihana\files\enums\FileExtension;

use PHPUnit\Framework\TestCase;

/**
 * Minimal host exposing the protected static suffix helper of
 * {@see ArangoRestoreAction} so it can be unit-tested in isolation.
 */
class ArangoRestoreActionStub
{
    use ArangoRestoreAction ;

    public static function suffix( string $database , bool $encrypt = false , bool $partial = false , ?string $label = null ) :string
    {
        return static::getArchiveFileSuffix( $database , $encrypt , $partial , $label ) ;
    }
}

/**
 * Unit coverage for {@see ArangoRestoreAction::getArchiveFileSuffix()}.
 *
 * Guards against the operator-precedence regression where
 * `Char::DASH . $database . $encrypt ? A : B` was parsed as
 * `(Char::DASH . $database . $encrypt) ? A : B` — a string condition that is
 * always truthy, so the suffix collapsed to the encrypted extension and lost
 * the `-{database}` segment. Also guards the gzip extension (`.tar.gz`), since
 * the dump action always produces a gzip-compressed tarball.
 */
class ArangoRestoreActionTest extends TestCase
{
    public function testSuffixWhenNotEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' , false ) ) ;
    }

    public function testSuffixWhenEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz.enc' , ArangoRestoreActionStub::suffix( 'mydb' , true ) ) ;
    }

    public function testSuffixDefaultsToNotEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' ) ) ;
    }

    public function testSuffixKeepsDatabaseSegment() :void
    {
        // The precedence bug used to drop the "-{database}" part entirely.
        $this->assertStringContainsString( Char::DASH . 'orders' , ArangoRestoreActionStub::suffix( 'orders' , true ) ) ;
        $this->assertStringStartsWith( Char::DASH . 'orders' , ArangoRestoreActionStub::suffix( 'orders' , false ) ) ;
    }

    public function testEncryptionFlagActuallyChangesTheExtension() :void
    {
        // The precedence bug made both branches return the same (encrypted) value.
        $this->assertNotSame
        (
            ArangoRestoreActionStub::suffix( 'mydb' , true ) ,
            ArangoRestoreActionStub::suffix( 'mydb' , false ) ,
        ) ;
    }

    public function testSuffixUsesCanonicalFileExtensions() :void
    {
        $this->assertSame( '-mydb' . FileExtension::TAR_GZ           , ArangoRestoreActionStub::suffix( 'mydb' , false ) ) ;
        $this->assertSame( '-mydb' . FileExtension::TAR_GZ_ENCRYPTED , ArangoRestoreActionStub::suffix( 'mydb' , true  ) ) ;
    }

    public function testSuffixPartialDump() :void
    {
        $this->assertSame( '-mydb-partial.tar.gz'     , ArangoRestoreActionStub::suffix( 'mydb' , false , true ) ) ;
        $this->assertSame( '-mydb-partial.tar.gz.enc' , ArangoRestoreActionStub::suffix( 'mydb' , true  , true ) ) ;
    }

    public function testSuffixPartialWithLabel() :void
    {
        $this->assertSame
        (
            '-mydb-partial-pre-migration.tar.gz' ,
            ArangoRestoreActionStub::suffix( 'mydb' , false , true , 'pre-migration' ) ,
        ) ;
        $this->assertSame
        (
            '-mydb-partial-pre-migration.tar.gz.enc' ,
            ArangoRestoreActionStub::suffix( 'mydb' , true , true , 'pre-migration' ) ,
        ) ;
    }

    public function testSuffixFullWithLabel() :void
    {
        $this->assertSame( '-mydb-nightly.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' , false , false , 'nightly' ) ) ;
    }
}
