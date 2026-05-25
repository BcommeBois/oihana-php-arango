<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\matchesSkin;

final class matchesSkinTest extends TestCase
{
    // ---------------------------------------------------------------------
    // Null cases — no restriction declared, always matches
    // ---------------------------------------------------------------------

    public function testReturnsTrueWhenSkinsIsNull() :void
    {
        $this->assertTrue( matchesSkin( null , 'full'    ) );
        $this->assertTrue( matchesSkin( null , 'default' ) );
    }

    public function testReturnsTrueWhenCurrentSkinIsNull() :void
    {
        $this->assertTrue( matchesSkin( [ 'full' ]    , null ) );
        $this->assertTrue( matchesSkin( 'main,full'   , null ) );
    }

    public function testReturnsTrueWhenBothAreNull() :void
    {
        $this->assertTrue( matchesSkin( null , null ) );
    }

    // ---------------------------------------------------------------------
    // Array<string> mode — the canonical usage in the repo
    // ---------------------------------------------------------------------

    public function testArrayContainsCurrentSkin() :void
    {
        $this->assertTrue( matchesSkin( [ 'full' , 'default' ] , 'full' ) );
        $this->assertTrue( matchesSkin( [ 'full' , 'default' ] , 'default' ) );
    }

    public function testArrayDoesNotContainCurrentSkin() :void
    {
        $this->assertFalse( matchesSkin( [ 'full' ] , 'default' ) );
        $this->assertFalse( matchesSkin( [ 'main' ] , 'full'    ) );
    }

    public function testArrayWithBusinessSkinsWorks() :void
    {
        // Business skins (image, offers, employee) are opaque strings —
        // matchesSkin doesn't need to know them.
        $this->assertTrue ( matchesSkin( [ 'image' , 'offers' ] , 'offers' ) );
        $this->assertFalse( matchesSkin( [ 'image' , 'offers' ] , 'main'   ) );
    }

    public function testEmptyArrayMatchesNothing() :void
    {
        $this->assertFalse( matchesSkin( [] , 'full' ) );
    }

    public function testArrayUsesStrictComparison() :void
    {
        // 'full' must not loose-match a falsy / type-coerced value.
        $this->assertFalse( matchesSkin( [ 0 , false ] , 'full' ) );
    }

    // ---------------------------------------------------------------------
    // Comma-separated string mode
    // ---------------------------------------------------------------------

    public function testCsvStringContainsSkin() :void
    {
        $this->assertTrue( matchesSkin( 'main,full'   , 'full' ) );
        $this->assertTrue( matchesSkin( 'main, full'  , 'full' ) );  // tolerant to whitespace
        $this->assertTrue( matchesSkin( ' main , full ' , 'main' ) );
    }

    public function testCsvStringDoesNotContainSkin() :void
    {
        $this->assertFalse( matchesSkin( 'main,full' , 'default' ) );
    }

    public function testSingleStringActsAsSingletonCsv() :void
    {
        $this->assertTrue ( matchesSkin( 'full' , 'full'    ) );
        $this->assertFalse( matchesSkin( 'full' , 'default' ) );
    }

    // ---------------------------------------------------------------------
    // Unknown shape — defensive default
    // ---------------------------------------------------------------------

    public function testUnknownShapeReturnsTrue() :void
    {
        // An int / object / bool slipping in must not break the projection.
        $this->assertTrue( matchesSkin( 42      , 'full' ) );
        $this->assertTrue( matchesSkin( true    , 'full' ) );
        $this->assertTrue( matchesSkin( 1.5     , 'full' ) );
    }
}
