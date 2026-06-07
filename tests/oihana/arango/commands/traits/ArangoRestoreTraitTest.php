<?php

namespace tests\oihana\arango\commands\traits;

use RuntimeException;

use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\traits\ArangoRestoreTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Host wiring {@see ArangoRestoreTrait} with the proc_open seam stubbed.
 *
 * {@see runProcess()} is overridden so no external `arangorestore` binary is
 * launched: the captured argv and a caller-controlled exit status drive the
 * trait's success / failure logic.
 */
class ArangoRestoreTraitHost
{
    use ArangoRestoreTrait ;

    /** The exit status the stubbed process should return. */
    public static int $status = 0 ;

    /** The argv captured by the stubbed runProcess(). */
    public static array $captured = [] ;

    /** Whether silent mode was forwarded to the process. */
    public static bool $silent = false ;

    protected static function runProcess( array $arguments , bool $silent = false ) :int
    {
        self::$captured = $arguments ;
        self::$silent   = $silent ;
        return self::$status ;
    }
}

/**
 * Unit coverage for {@see ArangoRestoreTrait}.
 */
#[CoversTrait(ArangoRestoreTrait::class)]
class ArangoRestoreTraitTest extends TestCase
{
    protected function setUp() :void
    {
        ArangoRestoreTraitHost::$status   = 0 ;
        ArangoRestoreTraitHost::$captured = [] ;
        ArangoRestoreTraitHost::$silent   = false ;
    }

    public function testArgumentsStartWithTheBinaryName() :void
    {
        $host = new ArangoRestoreTraitHost() ;

        $arguments = $host->getArangoRestoreArguments
        ([
            ArangoRestoreOption::SERVER_DATABASE => 'mydb' ,
            ArangoRestoreOption::INPUT_DIRECTORY => '/tmp/dump' ,
        ]) ;

        $this->assertSame( ArangoRestoreTraitHost::ARANGO_RESTORE , $arguments[ 0 ] ) ;
        $this->assertContains( '--server.database' , $arguments ) ;
        $this->assertContains( 'mydb' , $arguments ) ;
        $this->assertContains( '--input-directory' , $arguments ) ;
        $this->assertContains( '/tmp/dump' , $arguments ) ;
    }

    public function testArangoRestoreReturnsZeroAndForwardsTheBuiltArguments() :void
    {
        $host = new ArangoRestoreTraitHost() ;

        $status = $host->arangoRestore( [ ArangoRestoreOption::SERVER_DATABASE => 'mydb' ] , true ) ;

        $this->assertSame( 0 , $status ) ;
        $this->assertSame( ArangoRestoreTraitHost::ARANGO_RESTORE , ArangoRestoreTraitHost::$captured[ 0 ] ) ;
        $this->assertTrue( ArangoRestoreTraitHost::$silent ) ;
    }

    public function testArangoRestoreThrowsWhenTheProcessFails() :void
    {
        ArangoRestoreTraitHost::$status = 5 ;
        $host = new ArangoRestoreTraitHost() ;

        try
        {
            $host->arangoRestore() ;
            $this->fail( 'Expected a RuntimeException on a non-zero process status.' ) ;
        }
        catch ( RuntimeException $exception )
        {
            $this->assertSame( 5 , $exception->getCode() ) ;
            $this->assertStringContainsString( 'restore command failed' , $exception->getMessage() ) ;
        }
    }
}
