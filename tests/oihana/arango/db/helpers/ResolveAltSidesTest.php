<?php

namespace tests\oihana\arango\db\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\resolveAltSides;

/**
 * Direct unit coverage for the free helper
 * {@see resolveAltSides}: it splits the raw `alt`
 * parameter into a `[ keyChain , valChain ]` pair, distinguishing the legacy
 * field-only forms (string / list) from the per-side object form.
 */
final class ResolveAltSidesTest extends TestCase
{
    public function testNullYieldsTwoNoOps(): void
    {
        $this->assertSame( [ null , null ] , resolveAltSides( null ) ) ;
    }

    public function testStringIsKeySideOnly(): void
    {
        $this->assertSame( [ 'lower' , null ] , resolveAltSides( 'lower' ) ) ;
    }

    public function testListIsKeySideOnly(): void
    {
        // A list is a function chain, applied to the key side only.
        $this->assertSame( [ [ 'trim' , 'lower' ] , null ] , resolveAltSides( [ 'trim' , 'lower' ] ) ) ;
    }

    public function testObjectKeyOnly(): void
    {
        $this->assertSame( [ 'lower' , null ] , resolveAltSides( [ 'key' => 'lower' ] ) ) ;
    }

    public function testObjectValOnly(): void
    {
        $this->assertSame( [ null , 'upper' ] , resolveAltSides( [ 'val' => 'upper' ] ) ) ;
    }

    public function testObjectBothSides(): void
    {
        $this->assertSame( [ 'lower' , 'upper' ] , resolveAltSides( [ 'key' => 'lower' , 'val' => 'upper' ] ) ) ;
    }

    public function testValTrueMirrorsTheKeyChain(): void
    {
        $this->assertSame( [ 'lower' , 'lower' ] , resolveAltSides( [ 'key' => 'lower' , 'val' => true ] ) ) ;
    }

    public function testValTrueMirrorsAListKeyChain(): void
    {
        $alt = [ 'key' => [ 'trim' , 'lower' ] , 'val' => true ] ;
        $this->assertSame( [ [ 'trim' , 'lower' ] , [ 'trim' , 'lower' ] ] , resolveAltSides( $alt ) ) ;
    }
}
