<?php

namespace oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for {@see isPolymorphic()} — the predicate telling a polymorphic
 * edge definition (Arango::MAP + Arango::DISCRIMINATOR) from a regular one.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class IsPolymorphicTest extends TestCase
{
    public function testTrueWhenMapAndDiscriminatorPresent() :void
    {
        $this->assertTrue( isPolymorphic
        ([
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           => [ 'warehouse' => [ AQL::MODEL => 'edges.warehouse' ] ] ,
        ]) ) ;
    }

    public function testFalseForRegularEdge() :void
    {
        $this->assertFalse( isPolymorphic( [ AQL::MODEL => 'edges.warehouse' ] ) ) ;
    }

    public function testFalseWhenNotArray() :void
    {
        $this->assertFalse( isPolymorphic( 'edges.warehouse' ) ) ;
        $this->assertFalse( isPolymorphic( null ) ) ;
    }

    public function testFalseWhenMapMissingEmptyOrNotArray() :void
    {
        $this->assertFalse( isPolymorphic( [ Arango::DISCRIMINATOR => 'kind' ] ) ) ;
        $this->assertFalse( isPolymorphic( [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [] ] ) ) ;
        $this->assertFalse( isPolymorphic( [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => 'nope' ] ) ) ;
    }

    public function testFalseWhenDiscriminatorMissingEmptyOrNotString() :void
    {
        $map = [ 'warehouse' => [ AQL::MODEL => 'edges.warehouse' ] ] ;

        $this->assertFalse( isPolymorphic( [ Arango::MAP => $map ] ) ) ;
        $this->assertFalse( isPolymorphic( [ Arango::MAP => $map , Arango::DISCRIMINATOR => '' ] ) ) ;
        $this->assertFalse( isPolymorphic( [ Arango::MAP => $map , Arango::DISCRIMINATOR => 123 ] ) ) ;
    }
}
