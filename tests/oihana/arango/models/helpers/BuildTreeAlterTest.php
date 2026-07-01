<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\buildTreeAlter;

/**
 * Coverage for {@see buildTreeAlter()} — the `Alter::MAP` factory that reshapes a
 * flat hierarchy projection into a nested tree, rooted on the enclosing document.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class BuildTreeAlterTest extends TestCase
{
    private function flat() :array
    {
        return
        [
            [ '_key' => 'mammals' , '_parent' => 'animals' ] ,
            [ '_key' => 'dogs'    , '_parent' => 'mammals' ] ,
            [ '_key' => 'birds'   , '_parent' => 'animals' ] ,
        ] ;
    }

    public function testReturnsACallable() :void
    {
        $this->assertIsCallable( buildTreeAlter() ) ;
    }

    public function testRootsTheTreeOnTheEnclosingDocumentKey() :void
    {
        $alter = buildTreeAlter() ;

        $tree = $alter( [ '_key' => 'animals' ] , null , 'descendants' , $this->flat() ) ;

        $this->assertSame( [ 'mammals' , 'birds' ] , array_column( $tree , '_key' ) ) ;
        $this->assertSame( [ 'dogs' ] , array_column( $tree[ 0 ][ 'children' ] , '_key' ) ) ;
    }

    public function testReadsTheRootFromAnObjectDocument() :void
    {
        $alter = buildTreeAlter() ;

        $doc = (object) [ '_key' => 'animals' ] ;
        $tree = $alter( $doc , null , 'descendants' , $this->flat() ) ;

        $this->assertSame( [ 'mammals' , 'birds' ] , array_column( $tree , '_key' ) ) ;
    }

    public function testNonArrayValueIsReturnedUnchanged() :void
    {
        $alter = buildTreeAlter() ;

        $this->assertSame( 'scalar' , $alter( [ '_key' => 'x' ] , null , 'k' , 'scalar' ) ) ;
        $this->assertNull( $alter( [ '_key' => 'x' ] , null , 'k' , null ) ) ;
    }

    public function testHonorsCustomParentSourceAndChildrenKey() :void
    {
        $alter = buildTreeAlter( parentSource: 'broader' , childrenKey: 'kids' ) ;

        $flat =
        [
            [ '_key' => 'b' , 'broader' => 'a' ] ,
            [ '_key' => 'c' , 'broader' => 'b' ] ,
        ] ;

        $tree = $alter( [ '_key' => 'a' ] , null , 'narrower' , $flat ) ;

        $this->assertSame( [ 'b' ] , array_column( $tree , '_key' ) ) ;
        $this->assertArrayHasKey( 'kids' , $tree[ 0 ] ) ;
        $this->assertSame( [ 'c' ] , array_column( $tree[ 0 ][ 'kids' ] , '_key' ) ) ;
    }
}
