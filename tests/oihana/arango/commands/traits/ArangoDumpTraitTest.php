<?php

namespace tests\oihana\arango\commands\traits;

use RuntimeException;

use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\traits\ArangoDumpTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Host wiring {@see ArangoDumpTrait} with the proc_open seam stubbed.
 *
 * {@see runProcess()} is overridden so no external `arangodump` binary is
 * launched: the captured argv and a caller-controlled exit status drive the
 * trait's success / failure logic.
 */
class ArangoDumpTraitHost
{
    use ArangoDumpTrait ;

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
 * Unit coverage for {@see ArangoDumpTrait}.
 */
#[CoversTrait(ArangoDumpTrait::class)]
class ArangoDumpTraitTest extends TestCase
{
    protected function setUp() :void
    {
        ArangoDumpTraitHost::$status   = 0 ;
        ArangoDumpTraitHost::$captured = [] ;
        ArangoDumpTraitHost::$silent   = false ;
    }

    public function testArgumentsStartWithTheBinaryName() :void
    {
        $host = new ArangoDumpTraitHost() ;

        $arguments = $host->getArangoDumpArguments
        ([
            ArangoDumpOption::SERVER_DATABASE  => 'mydb' ,
            ArangoDumpOption::OUTPUT_DIRECTORY => '/tmp/dump' ,
        ]) ;

        $this->assertSame( ArangoDumpTraitHost::ARANGO_DUMP , $arguments[ 0 ] ) ;
        $this->assertContains( '--server.database' , $arguments ) ;
        $this->assertContains( 'mydb' , $arguments ) ;
        $this->assertContains( '--output-directory' , $arguments ) ;
        $this->assertContains( '/tmp/dump' , $arguments ) ;
    }

    public function testArangoDumpReturnsZeroAndForwardsTheBuiltArguments() :void
    {
        $host = new ArangoDumpTraitHost() ;

        $status = $host->arangoDump( [ ArangoDumpOption::SERVER_DATABASE => 'mydb' ] , true ) ;

        $this->assertSame( 0 , $status ) ;
        $this->assertSame( ArangoDumpTraitHost::ARANGO_DUMP , ArangoDumpTraitHost::$captured[ 0 ] ) ;
        $this->assertTrue( ArangoDumpTraitHost::$silent ) ;
    }

    public function testArangoDumpThrowsWhenTheProcessFails() :void
    {
        ArangoDumpTraitHost::$status = 3 ;
        $host = new ArangoDumpTraitHost() ;

        try
        {
            $host->arangoDump() ;
            $this->fail( 'Expected a RuntimeException on a non-zero process status.' ) ;
        }
        catch ( RuntimeException $exception )
        {
            $this->assertSame( 3 , $exception->getCode() ) ;
            $this->assertStringContainsString( 'dump command failed' , $exception->getMessage() ) ;
        }
    }
}
