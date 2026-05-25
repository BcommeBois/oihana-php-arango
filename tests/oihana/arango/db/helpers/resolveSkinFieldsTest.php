<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\resolveSkinFields;

final class resolveSkinFieldsTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Legacy AQL::FIELDS — definitions with no SKIN_FIELDS keep working
    // ---------------------------------------------------------------------

    public function testLegacyFieldsReturnedWhenNoSkinFields() :void
    {
        $projection = [ '_key' , 'name' ] ;
        $def        = [ Arango::FIELDS => $projection ] ;

        $this->assertSame( $projection , resolveSkinFields( $def , 'full'    ) ) ;
        $this->assertSame( $projection , resolveSkinFields( $def , 'default' ) ) ;
        $this->assertSame( $projection , resolveSkinFields( $def , null      ) ) ;
    }

    public function testReturnsNullWhenDefinitionHasNothing() :void
    {
        $this->assertNull( resolveSkinFields( [] , 'full' ) ) ;
        $this->assertNull( resolveSkinFields( [] , null   ) ) ;
    }

    public function testNonArraySkinFieldsIsIgnoredAndFallsBackOnLegacyFields() :void
    {
        $def = [
            AQL::SKIN_FIELDS => 'invalid-shape' ,
            Arango::FIELDS   => [ '_key' ] ,
        ] ;

        // The malformed SKIN_FIELDS entry is ignored and the legacy
        // projection is used — robustness matters here, definitions
        // are hand-written.
        $this->assertSame( [ '_key' ] , resolveSkinFields( $def , 'full' ) ) ;
    }

    // ---------------------------------------------------------------------
    // SKIN_FIELDS resolution
    // ---------------------------------------------------------------------

    public function testSkinFieldsReturnsExactSkinBucket() :void
    {
        $flat = [ '_key' , 'name' ] ;
        $rich = [ '_key' , 'name' , 'permissions' ] ;

        $def = [
            AQL::SKIN_FIELDS => [
                'default' => $flat ,
                'full'    => $rich ,
            ] ,
        ] ;

        $this->assertSame( $flat , resolveSkinFields( $def , 'default' ) ) ;
        $this->assertSame( $rich , resolveSkinFields( $def , 'full'    ) ) ;
    }

    public function testSkinFieldsFallsBackOnWildcardWhenSkinIsUnknown() :void
    {
        $flat     = [ '_key' , 'name' ] ;
        $fallback = [ '_key' ] ;

        $def = [
            AQL::SKIN_FIELDS => [
                'full' => $flat ,
                '*'    => $fallback ,
            ] ,
        ] ;

        $this->assertSame( $flat     , resolveSkinFields( $def , 'full'    ) ) ;
        $this->assertSame( $fallback , resolveSkinFields( $def , 'default' ) ) ;
        $this->assertSame( $fallback , resolveSkinFields( $def , 'image'   ) ) ;
    }

    public function testSkinFieldsFallsBackOnLegacyFieldsWhenNeitherSkinNorWildcardMatch() :void
    {
        $rich   = [ '_key' , 'permissions' ] ;
        $legacy = [ '_key' ] ;

        $def = [
            AQL::SKIN_FIELDS => [ 'full' => $rich ] ,
            Arango::FIELDS   => $legacy ,
        ] ;

        $this->assertSame( $rich   , resolveSkinFields( $def , 'full'    ) ) ;
        $this->assertSame( $legacy , resolveSkinFields( $def , 'default' ) ) ;
    }

    public function testSkinFieldsFallsBackToNullWhenNothingMatches() :void
    {
        $def = [
            AQL::SKIN_FIELDS => [ 'full' => [ '_key' ] ] ,
        ] ;

        // Skin not in the bucket, no '*' bucket, no legacy AQL::FIELDS.
        $this->assertNull( resolveSkinFields( $def , 'default' ) ) ;
    }

    public function testNullSkinPicksWildcardBucketWhenAvailable() :void
    {
        $fallback = [ '_key' ] ;
        $def      = [
            AQL::SKIN_FIELDS => [ '*' => $fallback ] ,
        ] ;

        // skin === null cannot be a literal key in the bucket map (PHP
        // arrays coerce null to '' as key) — wildcard is the right anchor.
        $this->assertSame( $fallback , resolveSkinFields( $def , null ) ) ;
    }

    public function testEmptyArrayBucketIsTreatedAsValidProjection() :void
    {
        // An explicitly empty array means "project nothing" — different
        // from "fallback on the next bucket". The function must return
        // it as-is, not skip it.
        $def = [
            AQL::SKIN_FIELDS => [
                'default' => [] ,
                'full'    => [ '_key' ] ,
            ] ,
            Arango::FIELDS => [ 'should-not-be-used' ] ,
        ] ;

        // PHP `?? ` skips null only, not empty array → empty bucket wins.
        $this->assertSame( [] , resolveSkinFields( $def , 'default' ) ) ;
    }
}
