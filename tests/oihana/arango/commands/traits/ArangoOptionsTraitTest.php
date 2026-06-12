<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\traits\ArangoOptionsTrait;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Bare host exposing the protected resolvers of {@see ArangoOptionsTrait}.
 */
class ArangoOptionsTraitHost
{
    use ArangoOptionsTrait ;

    public function resolveDump( array $explicit , InputInterface $input ) :array
    {
        return $this->resolveDumpOptions( $explicit , $input ) ;
    }

    public function resolveRestore( array $explicit , InputInterface $input ) :array
    {
        return $this->resolveRestoreOptions( $explicit , $input ) ;
    }
}

/**
 * Unit coverage for {@see ArangoOptionsTrait}.
 *
 * Verifies the precedence chain (binary default → config → CLI), the curated
 * CLI flag mapping for both `dump` and `restore`, and that non-array config
 * sections are ignored.
 */
#[CoversTrait(ArangoOptionsTrait::class)]
#[AllowMockObjectsWithoutExpectations]
class ArangoOptionsTraitTest extends TestCase
{
    /** An InputInterface double whose getOption() answers from $map (null otherwise). */
    private function input( array $map = [] ) :InputInterface
    {
        $input = $this->createMock( InputInterface::class ) ;
        $input->method( 'getOption' )->willReturnCallback( fn( string $name ) => $map[ $name ] ?? null ) ;
        return $input ;
    }

    // ------------------------------------------------------------------ init

    public function testInitializeCapturesBothSections() :void
    {
        $host = new ArangoOptionsTraitHost() ;
        $host->initializeArangoOptions
        ([
            ArangoCommandParam::DUMP    => [ ArangoDumpOption::THREADS => 2 ] ,
            ArangoCommandParam::RESTORE => [ ArangoRestoreOption::THREADS => 3 ] ,
        ]) ;

        $this->assertSame( 2 , $host->resolveDump( [] , $this->input() )[ ArangoDumpOption::THREADS ] ) ;
        $this->assertSame( 3 , $host->resolveRestore( [] , $this->input() )[ ArangoRestoreOption::THREADS ] ) ;
    }

    public function testInitializeIgnoresNonArraySections() :void
    {
        $host = new ArangoOptionsTraitHost() ;
        $host->initializeArangoOptions
        ([
            ArangoCommandParam::DUMP    => 'oops' ,
            ArangoCommandParam::RESTORE => 123 ,
        ]) ;

        $this->assertSame( [] , $host->resolveDump( [] , $this->input() ) ) ;
        $this->assertSame( [] , $host->resolveRestore( [] , $this->input() ) ) ;
    }

    public function testInitializeReturnsStatic() :void
    {
        $host = new ArangoOptionsTraitHost() ;
        $this->assertSame( $host , $host->initializeArangoOptions() ) ;
    }

    // ------------------------------------------------------------------ dump

    public function testResolveDumpWithoutConfigOrFlagsReturnsExplicit() :void
    {
        $host     = new ArangoOptionsTraitHost() ;
        $explicit = [ ArangoDumpOption::SERVER_DATABASE => 'app' ] ;

        $this->assertSame( $explicit , $host->resolveDump( $explicit , $this->input() ) ) ;
    }

    public function testResolveDumpAppliesEveryFlag() :void
    {
        $host = new ArangoOptionsTraitHost() ;

        $options = $host->resolveDump( [] , $this->input
        ([
            ArangoCommandOption::INCLUDE_SYSTEM => true ,
            ArangoCommandOption::NO_VIEWS       => true ,
            ArangoCommandOption::ALL_DATABASES  => true ,
            ArangoCommandOption::OVERWRITE      => true ,
            ArangoCommandOption::THREADS        => '4' ,
        ]) ) ;

        $this->assertTrue ( $options[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
        $this->assertFalse( $options[ ArangoDumpOption::DUMP_VIEWS ] ) ;
        $this->assertTrue ( $options[ ArangoDumpOption::ALL_DATABASES ] ) ;
        $this->assertTrue ( $options[ ArangoDumpOption::OVERWRITE ] ) ;
        $this->assertSame ( 4 , $options[ ArangoDumpOption::THREADS ] ) ;
    }

    public function testResolveDumpCliOverridesConfigAndExplicitWinsOnCollision() :void
    {
        $host = new ArangoOptionsTraitHost() ;
        $host->initializeArangoOptions
        ([
            ArangoCommandParam::DUMP =>
            [
                ArangoDumpOption::THREADS         => 2 ,
                ArangoDumpOption::OVERWRITE       => true ,
                ArangoDumpOption::SERVER_DATABASE => 'from-config' ,
            ]
        ]) ;

        $options = $host->resolveDump
        (
            [ ArangoDumpOption::SERVER_DATABASE => 'from-explicit' ] ,
            $this->input( [ ArangoCommandOption::THREADS => '8' ] ) ,
        ) ;

        $this->assertSame( 8               , $options[ ArangoDumpOption::THREADS ] ) ;          // CLI > config
        $this->assertTrue( $options[ ArangoDumpOption::OVERWRITE ] ) ;                          // config default kept
        $this->assertSame( 'from-explicit' , $options[ ArangoDumpOption::SERVER_DATABASE ] ) ;  // explicit > config
    }

    // ------------------------------------------------------------------ restore

    public function testResolveRestoreAppliesFlagsAndSplitsViews() :void
    {
        $host = new ArangoOptionsTraitHost() ;

        $options = $host->resolveRestore( [] , $this->input
        ([
            ArangoCommandOption::INCLUDE_SYSTEM => true ,
            ArangoCommandOption::ALL_DATABASES  => true ,
            ArangoCommandOption::THREADS        => '6' ,
            ArangoCommandOption::VIEW           => [ 'a_view, b_view' , 'c_view' ] ,
        ]) ) ;

        $this->assertTrue( $options[ ArangoRestoreOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
        $this->assertTrue( $options[ ArangoRestoreOption::ALL_DATABASES ] ) ;
        $this->assertSame( 6 , $options[ ArangoRestoreOption::THREADS ] ) ;
        $this->assertSame( [ 'a_view' , 'b_view' , 'c_view' ] , $options[ ArangoRestoreOption::VIEW ] ) ;
    }

    public function testResolveRestoreIgnoresEmptyViewInput() :void
    {
        $host    = new ArangoOptionsTraitHost() ;
        $options = $host->resolveRestore( [] , $this->input( [ ArangoCommandOption::VIEW => [ ' ' , '' ] ] ) ) ;

        $this->assertArrayNotHasKey( ArangoRestoreOption::VIEW , $options ) ;
    }

    public function testResolveRestoreConfigDefaultApplied() :void
    {
        $host = new ArangoOptionsTraitHost() ;
        $host->initializeArangoOptions( [ ArangoCommandParam::RESTORE => [ ArangoRestoreOption::INCLUDE_SYSTEM_COLLECTIONS => true ] ] ) ;

        $options = $host->resolveRestore( [] , $this->input() ) ;

        $this->assertTrue( $options[ ArangoRestoreOption::INCLUDE_SYSTEM_COLLECTIONS ] ) ;
    }
}
