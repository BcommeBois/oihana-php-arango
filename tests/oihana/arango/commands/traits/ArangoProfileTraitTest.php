<?php

namespace tests\oihana\arango\commands\traits;

use RuntimeException;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\traits\ArangoProfileTrait;
use oihana\arango\db\enums\ArangoConfig;

use oihana\files\exceptions\FileException;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Bare host for {@see ArangoProfileTrait}.
 */
class ArangoProfileTraitHost
{
    use ArangoProfileTrait ;
}

/**
 * Unit coverage for {@see ArangoProfileTrait}.
 */
#[CoversTrait(ArangoProfileTrait::class)]
class ArangoProfileTraitTest extends TestCase
{
    private array $files = [] ;

    protected function tearDown() :void
    {
        foreach ( $this->files as $file )
        {
            @unlink( $file ) ;
        }
        $this->files = [] ;
    }

    private function host( array $profiles = [] ) :ArangoProfileTraitHost
    {
        return new ArangoProfileTraitHost()->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => $profiles ] ) ;
    }

    /** Writes a temporary .toml file and returns its path. */
    private function tomlFile( string $content ) :string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'profile_' . bin2hex( random_bytes( 6 ) ) . '.toml' ;
        file_put_contents( $path , $content ) ;
        $this->files[] = $path ;
        return $path ;
    }

    // ------------------------------------------------------------------ init

    public function testInitializeIgnoresNonArray() :void
    {
        // A non-array section leaves the declared profiles empty → any name is unknown.
        $host = new ArangoProfileTraitHost()->initializeArangoProfiles( [ ArangoCommandParam::PROFILES => 'nope' ] ) ;
        $this->expectException( RuntimeException::class ) ;
        $host->resolveProfile( 'whatever' ) ;
    }

    public function testInitializeReturnsStatic() :void
    {
        $host = new ArangoProfileTraitHost() ;
        $this->assertSame( $host , $host->initializeArangoProfiles() ) ;
    }

    // ------------------------------------------------------------------ resolveProfile

    public function testResolveNullOrEmptyReturnsNull() :void
    {
        $host = $this->host() ;
        $this->assertNull( $host->resolveProfile( null ) ) ;
        $this->assertNull( $host->resolveProfile( '' ) ) ;
    }

    public function testResolveNamedProfile() :void
    {
        $host = $this->host
        ([
            'test-local' => [ ArangoCommandParam::PROFILE_COLLECTIONS => [ 'products' ] ] ,
        ]) ;

        $profile = $host->resolveProfile( 'test-local' ) ;
        $this->assertSame( [ 'products' ] , $profile[ ArangoCommandParam::PROFILE_COLLECTIONS ] ) ;
    }

    public function testResolveUnknownProfileThrows() :void
    {
        $host = $this->host( [ 'a' => [] ] ) ;
        $this->expectException( RuntimeException::class ) ;
        $this->expectExceptionMessage( "Unknown dump/restore profile 'ghost'" ) ;
        $host->resolveProfile( 'ghost' ) ;
    }

    public function testResolveExternalTomlFile() :void
    {
        $path = $this->tomlFile( <<<TOML
            collections = ["thesaurus", "produits"]
            exclude     = ["_users"]
            endpoint    = "tcp://staging:8529"
            database    = "app_staging"
            TOML ) ;

        $host    = $this->host() ;
        $profile = $host->resolveProfile( $path ) ;

        $this->assertSame( [ 'thesaurus' , 'produits' ] , $profile[ ArangoCommandParam::PROFILE_COLLECTIONS ] ) ;
        $this->assertSame( 'app_staging' , $profile[ ArangoConfig::DATABASE ] ) ;
    }

    public function testResolveMissingExternalFileThrows() :void
    {
        $host = $this->host() ;
        $this->expectException( FileException::class ) ;
        $host->resolveProfile( '/no/such/path/to/a/profile.toml' ) ;
    }

    // ------------------------------------------------------------------ selection

    public function testProfilePositiveMergesAndDeduplicates() :void
    {
        $host    = $this->host() ;
        $profile =
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'a' , 'b' , 'a' ] ,
            ArangoCommandParam::PROFILE_EDGES       => [ 'e1' , 'b' ] ,   // 'b' duplicate across the two
        ] ;

        $this->assertSame( [ 'a' , 'b' , 'e1' ] , $host->profilePositive( $profile ) ) ;
    }

    public function testNormalizeSplitsCommaSeparatedStrings() :void
    {
        $host    = $this->host() ;
        $profile = [ ArangoCommandParam::PROFILE_COLLECTIONS => 'a, b ,, c' ] ;

        $this->assertSame( [ 'a' , 'b' , 'c' ] , $host->profilePositive( $profile ) ) ;
    }

    public function testProfileConnectionExtractsOnlyPresentStringKeys() :void
    {
        $host    = $this->host() ;
        $profile =
        [
            ArangoConfig::ENDPOINT => 'tcp://staging:8529' ,
            ArangoConfig::DATABASE => 'app_staging' ,
            ArangoConfig::USER     => 123 ,           // not a string → ignored
        ] ;

        $this->assertSame
        (
            [ ArangoConfig::ENDPOINT => 'tcp://staging:8529' , ArangoConfig::DATABASE => 'app_staging' ] ,
            $host->profileConnection( $profile ) ,
        ) ;
    }

    public function testProfileDirectoryReturnsTheStringValue() :void
    {
        $host    = $this->host() ;
        $profile = [ ArangoCommandParam::DIRECTORY => '/backups/staging' ] ;

        $this->assertSame( '/backups/staging' , $host->profileDirectory( $profile ) ) ;
    }

    public function testProfileDirectoryDefaultsToNull() :void
    {
        $host = $this->host() ;

        $this->assertNull( $host->profileDirectory( [] ) ) ;            // absent
        $this->assertNull( $host->profileDirectory( [ ArangoCommandParam::DIRECTORY => '' ] ) ) ; // empty
        $this->assertNull( $host->profileDirectory( [ ArangoCommandParam::DIRECTORY => 123 ] ) ) ; // not a string
    }

    public function testProfileSelectionPositiveMinusExclude() :void
    {
        $host    = $this->host() ;
        $profile =
        [
            ArangoCommandParam::PROFILE_COLLECTIONS => [ 'a' , 'b' , 'c' ] ,
            ArangoCommandParam::PROFILE_EXCLUDE     => [ 'b' ] ,
        ] ;

        $this->assertSame( [ 'a' , 'c' ] , $host->profileSelection( $profile ) ) ;
    }

    public function testProfileSelectionExcludeOnlyUsesUniverse() :void
    {
        $host    = $this->host() ;
        $profile = [ ArangoCommandParam::PROFILE_EXCLUDE => [ '_users' , 'sessions' ] ] ;

        $this->assertSame
        (
            [ 'products' , 'clients' ] ,
            $host->profileSelection( $profile , [ 'products' , '_users' , 'clients' , 'sessions' ] ) ,
        ) ;
    }
}
