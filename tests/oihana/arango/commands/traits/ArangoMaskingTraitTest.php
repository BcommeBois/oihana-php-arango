<?php

namespace tests\oihana\arango\commands\traits;

use InvalidArgumentException;

use oihana\arango\commands\traits\ArangoMaskingTrait;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing the masking compiler of {@see ArangoMaskingTrait}.
 */
class ArangoMaskingTraitHost
{
    use ArangoMaskingTrait ;

    public string $id = 'test' ;

    public function getName() :string
    {
        return 'dump' ;
    }
}

/**
 * Unit coverage for {@see ArangoMaskingTrait} — the convenient masking table
 * compiler and the dump-directory PHP masking pass.
 */
#[CoversTrait(ArangoMaskingTrait::class)]
class ArangoMaskingTraitTest extends TestCase
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

    private function host() :ArangoMaskingTraitHost
    {
        return new ArangoMaskingTraitHost() ;
    }

    /** Creates a dump directory and writes a `<name>_h` data (+ optional structure) pair. */
    private function dumpDirWith( array $collections , bool $withStructure = true ) :string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mask_dir_' . bin2hex( random_bytes( 6 ) ) ;
        mkdir( $dir , 0o777 , true ) ;
        $this->tmpDirs[] = $dir ;

        foreach( $collections as $name => $lines )
        {
            file_put_contents( $dir . DIRECTORY_SEPARATOR . $name . '_h.data.json' , implode( "\n" , $lines ) . "\n" ) ;
            if( $withStructure )
            {
                file_put_contents( $dir . DIRECTORY_SEPARATOR . $name . '_h.structure.json' , json_encode( [ 'parameters' => [ 'name' => $name ] ] ) ) ;
            }
        }

        return $dir ;
    }

    // ------------------------------------------------------------------ compileMaskings

    public function testCollectionModeNoDotKey() :void
    {
        $out = $this->host()->compileMaskings( [ 'users' => 'structure' , '*' => 'exclude' ] ) ;

        $this->assertSame( 'structure' , $out[ 'users' ][ 'type' ] ) ;
        $this->assertSame( []          , $out[ 'users' ][ 'maskings' ] ) ;
        $this->assertSame( 'exclude'   , $out[ '*' ][ 'type' ] ) ;
    }

    public function testAttributeRuleImpliesMaskedMode() :void
    {
        $out = $this->host()->compileMaskings( [ 'users.email' => 'email' ] ) ;

        $this->assertSame( 'masked' , $out[ 'users' ][ 'type' ] ) ;
        $this->assertSame
        (
            [ 'path' => 'email' , 'type' => 'email' ] ,
            $out[ 'users' ][ 'maskings' ][ 0 ] ,
        ) ;
    }

    public function testNestedAttributePathKeepsTheRemainderAsPath() :void
    {
        $out = $this->host()->compileMaskings( [ 'users.address.city' => 'random' ] ) ;

        $this->assertSame( 'address.city' , $out[ 'users' ][ 'maskings' ][ 0 ][ 'path' ] ) ;
        $this->assertSame( 'random'       , $out[ 'users' ][ 'maskings' ][ 0 ][ 'type' ] ) ;
    }

    public function testInlineTableCarriesTheMaskerParameters() :void
    {
        $out = $this->host()->compileMaskings( [ 'users.card' => [ 'type' => 'xifyFront' , 'unmaskedLength' => 4 ] ] ) ;

        $this->assertSame
        (
            [ 'path' => 'card' , 'type' => 'xifyFront' , 'unmaskedLength' => 4 ] ,
            $out[ 'users' ][ 'maskings' ][ 0 ] ,
        ) ;
    }

    public function testExplicitModeAndRulesOnTheSameCollection() :void
    {
        $out = $this->host()->compileMaskings
        ([
            'users'       => 'masked' ,
            'users.email' => 'email' ,
            'users.phone' => 'phone' ,
        ]) ;

        $this->assertSame( 'masked' , $out[ 'users' ][ 'type' ] ) ;
        $this->assertCount( 2 , $out[ 'users' ][ 'maskings' ] ) ;
    }

    public function testWildcardCollectionAttributeRule() :void
    {
        $out = $this->host()->compileMaskings( [ '*.email' => 'email' ] ) ;

        $this->assertSame( 'masked' , $out[ '*' ][ 'type' ] ) ;
        $this->assertSame( 'email'  , $out[ '*' ][ 'maskings' ][ 0 ][ 'path' ] ) ;
    }

    public function testEmptyTableCompilesToEmptyStructure() :void
    {
        $this->assertSame( [] , $this->host()->compileMaskings( [] ) ) ;
    }

    public function testUnknownModeThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'Invalid masking mode' ) ;
        $this->host()->compileMaskings( [ 'users' => 'nope' ] ) ;
    }

    public function testUnknownMaskerThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'Invalid masking function' ) ;
        $this->host()->compileMaskings( [ 'users.email' => 'obfuscate' ] ) ;
    }

    public function testInlineTableWithoutTypeThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'Invalid masking function' ) ;
        $this->host()->compileMaskings( [ 'users.card' => [ 'unmaskedLength' => 4 ] ] ) ;
    }

    public function testScalarRuleValueThatIsNeitherStringNorArrayThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'expected a masker name or an inline table' ) ;
        $this->host()->compileMaskings( [ 'users.email' => 123 ] ) ;
    }

    public function testMalformedKeyWithTrailingDotThrows() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'Malformed masking key' ) ;
        $this->host()->compileMaskings( [ 'users.' => 'email' ] ) ;
    }

    // ------------------------------------------------------------------ maskDumpDirectory

    private function dataOf( string $dir , string $collection ) :string
    {
        return (string) file_get_contents( $dir . DIRECTORY_SEPARATOR . $collection . '_h.data.json' ) ;
    }

    public function testMaskDumpDirectoryAnonymizesMatchedCollectionOnly() :void
    {
        $dir = $this->dumpDirWith
        ([
            'people'  => [ json_encode( [ '_key' => 'a' , 'email' => 'real@example.com' ] ) ] ,
            'secrets' => [ json_encode( [ '_key' => 'b' , 'token' => 'keepme' ] ) ] ,
        ]) ;

        $count = $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ 'people.email' => 'email' ] ) ) ;

        $this->assertSame( 1 , $count ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $this->dataOf( $dir , 'people' ) ) ;
        $this->assertStringContainsString( '"_key":"a"' , $this->dataOf( $dir , 'people' ) ) ;
        $this->assertStringContainsString( 'keepme' , $this->dataOf( $dir , 'secrets' ) ) ; // no rule, untouched
    }

    public function testMaskDumpDirectoryWildcardDefaultAppliesToEveryCollection() :void
    {
        $dir   = $this->dumpDirWith( [ 'people' => [ json_encode( [ 'email' => 'real@example.com' ] ) ] ] ) ;
        $count = $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ '*.email' => 'email' ] ) ) ;

        $this->assertSame( 1 , $count ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $this->dataOf( $dir , 'people' ) ) ;
    }

    public function testMaskDumpDirectoryFallsBackToFileNameWithoutStructure() :void
    {
        $dir   = $this->dumpDirWith( [ 'people' => [ json_encode( [ 'email' => 'real@example.com' ] ) ] ] , withStructure : false ) ;
        $count = $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ 'people.email' => 'email' ] ) ) ;

        $this->assertSame( 1 , $count ) ;
        $this->assertStringNotContainsString( 'real@example.com' , $this->dataOf( $dir , 'people' ) ) ;
    }

    public function testMaskDumpDirectorySkipsUnmatchedAndEmptyRuleCollections() :void
    {
        $dir = $this->dumpDirWith
        ([
            'orders' => [ json_encode( [ 'total' => 9 ] ) ] ,                 // no matching entry
            'people' => [ json_encode( [ 'email' => 'real@example.com' ] ) ] , // matched but mode-only (no rules)
        ]) ;

        // 'people' = masked mode with no attribute rules; 'orders' has no entry at all.
        $count = $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ 'people' => 'masked' ] ) ) ;

        $this->assertSame( 0 , $count ) ;
        $this->assertStringContainsString( 'real@example.com' , $this->dataOf( $dir , 'people' ) ) ;
        $this->assertStringContainsString( '"total":9' , $this->dataOf( $dir , 'orders' ) ) ;
    }

    public function testMaskDumpDirectoryKeepsNonJsonLines() :void
    {
        $dir = $this->dumpDirWith( [ 'people' => [ 'not-json' , json_encode( [ 'email' => 'real@example.com' ] ) ] ] ) ;

        $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ 'people.email' => 'email' ] ) ) ;

        $data = $this->dataOf( $dir , 'people' ) ;
        $this->assertStringContainsString( 'not-json' , $data ) ;                 // preserved verbatim
        $this->assertStringNotContainsString( 'real@example.com' , $data ) ;
    }

    public function testMaskDumpDirectoryRejectsNonMaskedMode() :void
    {
        $dir = $this->dumpDirWith( [ 'people' => [ json_encode( [ 'email' => 'x' ] ) ] ] ) ;

        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'not supported by the PHP masking engine' ) ;
        $this->host()->maskDumpDirectory( $dir , $this->host()->compileMaskings( [ 'people' => 'structure' ] ) ) ;
    }

    public function testMaskDumpDirectoryEmptyCompiledReturnsZero() :void
    {
        $dir = $this->dumpDirWith( [ 'people' => [ json_encode( [ 'email' => 'x' ] ) ] ] ) ;
        $this->assertSame( 0 , $this->host()->maskDumpDirectory( $dir , [] ) ) ;
    }
}
