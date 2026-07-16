<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\joins\isPolymorphicJoin;

/**
 * Coverage for {@see isPolymorphicJoin()} — the predicate telling a polymorphic
 * join definition (Arango::MAP + Arango::DISCRIMINATOR) from a regular one.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class IsPolymorphicJoinTest extends TestCase
{
    public function testTrueWhenMapAndDiscriminatorPresent() :void
    {
        $this->assertTrue( isPolymorphicJoin
        ([
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::MAP           => [ 'Warehouse' => [ AQL::MODEL => 'model.warehouse' ] ] ,
        ]) ) ;
    }

    public function testFalseForRegularJoin() :void
    {
        $this->assertFalse( isPolymorphicJoin( [ AQL::MODEL => 'model.warehouse' ] ) ) ;
    }

    public function testFalseWhenNotArray() :void
    {
        $this->assertFalse( isPolymorphicJoin( 'model.warehouse' ) ) ;
        $this->assertFalse( isPolymorphicJoin( null ) ) ;
    }

    public function testFalseWhenMapMissingEmptyOrNotArray() :void
    {
        $this->assertFalse( isPolymorphicJoin( [ Arango::DISCRIMINATOR => 'type' ] ) ) ;
        $this->assertFalse( isPolymorphicJoin( [ Arango::DISCRIMINATOR => 'type' , Arango::MAP => [] ] ) ) ;
        $this->assertFalse( isPolymorphicJoin( [ Arango::DISCRIMINATOR => 'type' , Arango::MAP => 'nope' ] ) ) ;
    }

    public function testFalseWhenDiscriminatorMissingEmptyOrNotString() :void
    {
        $map = [ 'Warehouse' => [ AQL::MODEL => 'model.warehouse' ] ] ;

        $this->assertFalse( isPolymorphicJoin( [ Arango::MAP => $map ] ) ) ;
        $this->assertFalse( isPolymorphicJoin( [ Arango::MAP => $map , Arango::DISCRIMINATOR => '' ] ) ) ;
        $this->assertFalse( isPolymorphicJoin( [ Arango::MAP => $map , Arango::DISCRIMINATOR => 123 ] ) ) ;
    }
}
