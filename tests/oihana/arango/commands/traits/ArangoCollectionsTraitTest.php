<?php

namespace tests\oihana\arango\commands\traits;

use InvalidArgumentException;

use oihana\arango\commands\traits\ArangoCollectionsTrait;

use PHPUnit\Framework\TestCase;

/**
 * Minimal host exposing the protected static helpers of
 * {@see ArangoCollectionsTrait} so they can be unit-tested in isolation.
 */
class ArangoCollectionsTraitStub
{
    use ArangoCollectionsTrait ;

    public static function assertTargeting( array $collection , array $ignore ) :void
    {
        static::assertCollectionTargeting( $collection , $ignore ) ;
    }

    public static function label( ?string $label ) :?string
    {
        return static::sanitizeLabel( $label ) ;
    }

    public static function missing( array $requested , array $available ) :array
    {
        return static::missingCollections( $requested , $available ) ;
    }

    public static function nameSuffix( string $database , bool $partial = false , ?string $label = null ) :string
    {
        return static::getArchiveNameSuffix( $database , $partial , $label ) ;
    }

    public static function normalize( array $raw ) :array
    {
        return static::normalizeCollections( $raw ) ;
    }
}

/**
 * Unit coverage for the pure helpers of {@see ArangoCollectionsTrait}:
 * collection-option normalization (both CLI syntaxes), the
 * mutually-exclusive targeting guard, the missing-collection diff used by
 * pre-dump validation, label sanitization, and the archive name suffix.
 */
class ArangoCollectionsTraitTest extends TestCase
{
    // -------------------------------------------------------------------------
    // normalizeCollections
    // -------------------------------------------------------------------------

    public function testNormalizeRepeatedFlags() :void
    {
        $this->assertSame
        (
            [ 'users' , 'products' , 'customers' ] ,
            ArangoCollectionsTraitStub::normalize( [ 'users' , 'products' , 'customers' ] ) ,
        ) ;
    }

    public function testNormalizeCommaSeparated() :void
    {
        $this->assertSame
        (
            [ 'users' , 'products' , 'customers' ] ,
            ArangoCollectionsTraitStub::normalize( [ 'users,products,customers' ] ) ,
        ) ;
    }

    public function testNormalizeMixedSyntaxes() :void
    {
        $this->assertSame
        (
            [ 'users' , 'products' , 'customers' ] ,
            ArangoCollectionsTraitStub::normalize( [ 'users,products' , 'customers' ] ) ,
        ) ;
    }

    public function testNormalizeTrimsAndDropsEmptyFragments() :void
    {
        $this->assertSame
        (
            [ 'users' , 'products' ] ,
            ArangoCollectionsTraitStub::normalize( [ ' users , , products ' , '' , '   ' ] ) ,
        ) ;
    }

    public function testNormalizeDeduplicatesPreservingOrder() :void
    {
        $this->assertSame
        (
            [ 'users' , 'products' ] ,
            ArangoCollectionsTraitStub::normalize( [ 'users' , 'products' , 'users' , 'products,users' ] ) ,
        ) ;
    }

    public function testNormalizeEmptyInput() :void
    {
        $this->assertSame( [] , ArangoCollectionsTraitStub::normalize( [] ) ) ;
    }

    // -------------------------------------------------------------------------
    // assertCollectionTargeting
    // -------------------------------------------------------------------------

    public function testAssertTargetingAllowsCollectionOnly() :void
    {
        ArangoCollectionsTraitStub::assertTargeting( [ 'users' ] , [] ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testAssertTargetingAllowsIgnoreOnly() :void
    {
        ArangoCollectionsTraitStub::assertTargeting( [] , [ 'logs' ] ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testAssertTargetingAllowsNeither() :void
    {
        ArangoCollectionsTraitStub::assertTargeting( [] , [] ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testAssertTargetingRejectsBoth() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        ArangoCollectionsTraitStub::assertTargeting( [ 'users' ] , [ 'logs' ] ) ;
    }

    // -------------------------------------------------------------------------
    // missingCollections
    // -------------------------------------------------------------------------

    public function testMissingReturnsEmptyWhenAllPresent() :void
    {
        $this->assertSame
        (
            [] ,
            ArangoCollectionsTraitStub::missing( [ 'users' , 'products' ] , [ 'users' , 'products' , 'customers' ] ) ,
        ) ;
    }

    public function testMissingReturnsUnknownNames() :void
    {
        $this->assertSame
        (
            [ 'prodcts' ] ,
            ArangoCollectionsTraitStub::missing( [ 'users' , 'prodcts' ] , [ 'users' , 'products' ] ) ,
        ) ;
    }

    public function testMissingIsCaseSensitive() :void
    {
        $this->assertSame
        (
            [ 'Users' ] ,
            ArangoCollectionsTraitStub::missing( [ 'Users' ] , [ 'users' ] ) ,
        ) ;
    }

    public function testMissingDeduplicates() :void
    {
        $this->assertSame
        (
            [ 'ghost' ] ,
            ArangoCollectionsTraitStub::missing( [ 'ghost' , 'ghost' ] , [ 'users' ] ) ,
        ) ;
    }

    // -------------------------------------------------------------------------
    // sanitizeLabel
    // -------------------------------------------------------------------------

    public function testLabelNullReturnsNull() :void
    {
        $this->assertNull( ArangoCollectionsTraitStub::label( null ) ) ;
    }

    public function testLabelEmptyReturnsNull() :void
    {
        $this->assertNull( ArangoCollectionsTraitStub::label( '' ) ) ;
        $this->assertNull( ArangoCollectionsTraitStub::label( '   ' ) ) ;
    }

    public function testLabelValidIsKeptAndTrimmed() :void
    {
        $this->assertSame( 'pre-migration'    , ArangoCollectionsTraitStub::label( '  pre-migration  ' ) ) ;
        $this->assertSame( 'safe_v1.2'        , ArangoCollectionsTraitStub::label( 'safe_v1.2' ) ) ;
    }

    public function testLabelRejectsUnsafeCharacters() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        ArangoCollectionsTraitStub::label( 'bad/label name' ) ;
    }

    // -------------------------------------------------------------------------
    // getArchiveNameSuffix
    // -------------------------------------------------------------------------

    public function testNameSuffixFullDump() :void
    {
        $this->assertSame( '-mydb' , ArangoCollectionsTraitStub::nameSuffix( 'mydb' ) ) ;
    }

    public function testNameSuffixPartialDump() :void
    {
        $this->assertSame( '-mydb-partial' , ArangoCollectionsTraitStub::nameSuffix( 'mydb' , true ) ) ;
    }

    public function testNameSuffixPartialWithLabel() :void
    {
        $this->assertSame
        (
            '-mydb-partial-pre-migration' ,
            ArangoCollectionsTraitStub::nameSuffix( 'mydb' , true , 'pre-migration' ) ,
        ) ;
    }

    public function testNameSuffixFullWithLabel() :void
    {
        $this->assertSame
        (
            '-mydb-nightly' ,
            ArangoCollectionsTraitStub::nameSuffix( 'mydb' , false , 'nightly' ) ,
        ) ;
    }

    public function testNameSuffixIgnoresEmptyLabel() :void
    {
        $this->assertSame( '-mydb-partial' , ArangoCollectionsTraitStub::nameSuffix( 'mydb' , true , '  ' ) ) ;
    }

    public function testNameSuffixRejectsInvalidLabel() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        ArangoCollectionsTraitStub::nameSuffix( 'mydb' , true , 'oops/..' ) ;
    }
}
