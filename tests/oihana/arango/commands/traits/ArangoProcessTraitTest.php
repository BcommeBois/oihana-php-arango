<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoDumpOptions;
use oihana\arango\commands\traits\ArangoProcessTrait;

use PHPUnit\Framework\TestCase;

/**
 * Minimal host exposing the protected static helpers of
 * {@see ArangoProcessTrait}.
 */
class ArangoProcessTraitStub
{
    use ArangoProcessTrait ;

    public static function arguments( ArangoDumpOptions $options ) :array
    {
        return static::optionsToArguments( $options , ArangoDumpOption::class ) ;
    }

    public static function run( array $arguments , bool $silent = false ) :int
    {
        return static::runProcess( $arguments , $silent ) ;
    }
}

/**
 * Unit coverage for {@see ArangoProcessTrait}.
 *
 * The security-critical guarantee is that option values are carried as
 * individual `argv` entries (never quoted into a shell string), so a value
 * containing shell metacharacters cannot break or inject into the command.
 */
class ArangoProcessTraitTest extends TestCase
{
    // -------------------------------------------------------------------------
    // optionsToArguments
    // -------------------------------------------------------------------------

    public function testScalarBecomesFlagThenValue() :void
    {
        $options = ArangoDumpOptions::create( [ ArangoDumpOption::SERVER_DATABASE => 'mydb' ] ) ;
        $this->assertSame( [ '--server.database' , 'mydb' ] , ArangoProcessTraitStub::arguments( $options ) ) ;
    }

    public function testArrayRepeatsTheFlag() :void
    {
        $options = ArangoDumpOptions::create( [ ArangoDumpOption::COLLECTION => [ 'users' , 'products' ] ] ) ;
        $this->assertSame
        (
            [ '--collection' , 'users' , '--collection' , 'products' ] ,
            ArangoProcessTraitStub::arguments( $options ) ,
        ) ;
    }

    public function testBooleanTrueBecomesAStandaloneFlag() :void
    {
        // A boolean-true option emits the flag alone, with no following value.
        $options = ArangoDumpOptions::create( [ ArangoDumpOption::DUMP_DATA => true ] ) ;
        $this->assertSame( [ '--dump-data' ] , ArangoProcessTraitStub::arguments( $options ) ) ;
    }

    public function testMetacharacterValueStaysOneArgument() :void
    {
        // The crux: a value with shell metacharacters must remain a single,
        // verbatim argv entry — no quoting, no splitting, no escaping.
        $payload = 'p@ss $(touch /tmp/x) `id` ; rm -rf /' ;
        $options = ArangoDumpOptions::create( [ ArangoDumpOption::SERVER_PASSWORD => $payload ] ) ;

        $arguments = ArangoProcessTraitStub::arguments( $options ) ;

        $this->assertSame( [ '--server.password' , $payload ] , $arguments ) ;
    }

    // -------------------------------------------------------------------------
    // runProcess
    // -------------------------------------------------------------------------

    public function testRunProcessReturnsExitCode() :void
    {
        if ( PHP_OS_FAMILY === 'Windows' )
        {
            $this->markTestSkipped( 'POSIX-only exit-code probe.' ) ;
        }

        $this->assertSame( 0 , ArangoProcessTraitStub::run( [ 'true' ]  , true ) ) ;
        $this->assertNotSame( 0 , ArangoProcessTraitStub::run( [ 'false' ] , true ) ) ;
    }

    public function testRunProcessDoesNotInvokeAShell() :void
    {
        if ( PHP_OS_FAMILY === 'Windows' )
        {
            $this->markTestSkipped( 'POSIX-only shell-injection probe.' ) ;
        }

        $marker = sys_get_temp_dir() . '/arango_proc_shell_probe_' . getmypid() ;
        @unlink( $marker ) ;

        // If a shell interpreted argv[1], $(touch <marker>) would create the
        // file. With proc_open(array) there is no shell, so echo just prints
        // the literal string and the marker is never created.
        ArangoProcessTraitStub::run( [ 'echo' , '$(touch ' . $marker . ')' ] , true ) ;

        $this->assertFileDoesNotExist( $marker ) ;
        @unlink( $marker ) ;
    }
}
