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
 * compiler and its native-JSON materialization.
 */
#[CoversTrait(ArangoMaskingTrait::class)]
class ArangoMaskingTraitTest extends TestCase
{
    private function host() :ArangoMaskingTraitHost
    {
        return new ArangoMaskingTraitHost() ;
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

    // ------------------------------------------------------------------ materializeMaskings

    public function testMaterializeWritesTheNativeJsonFile() :void
    {
        $file = $this->host()->materializeMaskings
        (
            [ 'users.email' => 'email' , '*' => 'structure' ] ,
            [ 'test' , 'dump' , 'mask-test-' . bin2hex( random_bytes( 4 ) ) ] ,
        ) ;

        $this->assertFileExists( $file ) ;
        $this->assertStringEndsWith( 'maskings.json' , $file ) ;

        $decoded = json_decode( (string) file_get_contents( $file ) , true ) ;
        $this->assertSame( 'masked'    , $decoded[ 'users' ][ 'type' ] ) ;
        $this->assertSame( 'email'     , $decoded[ 'users' ][ 'maskings' ][ 0 ][ 'type' ] ) ;
        $this->assertSame( 'structure' , $decoded[ '*' ][ 'type' ] ) ;

        @unlink( $file ) ;
        @rmdir( dirname( $file ) ) ;
    }
}
