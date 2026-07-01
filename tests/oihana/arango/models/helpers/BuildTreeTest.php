<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\buildTree;

/**
 * Coverage for {@see buildTree()} — reconstructs a nested `children[]` tree from a
 * flat parent-linked list (the shape produced by a depth-ranged edge projection).
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class BuildTreeTest extends TestCase
{
    private function thesaurus() :array
    {
        return
        [
            [ '_key' => 'mammals'  , '_parent' => 'animals' ] ,
            [ '_key' => 'dogs'     , '_parent' => 'mammals' ] ,
            [ '_key' => 'labrador' , '_parent' => 'dogs'    ] ,
            [ '_key' => 'cats'     , '_parent' => 'mammals' ] ,
            [ '_key' => 'birds'    , '_parent' => 'animals' ] ,
            [ '_key' => 'eagles'   , '_parent' => 'birds'   ] ,
        ] ;
    }

    public function testEmptyListReturnsEmpty() :void
    {
        $this->assertSame( [] , buildTree( [] ) ) ;
    }

    public function testNestsFromAnExplicitRootKey() :void
    {
        $tree = buildTree( $this->thesaurus() , rootKey: 'animals' ) ;

        // Two roots: mammals, birds
        $this->assertSame( [ 'mammals' , 'birds' ] , array_column( $tree , '_key' ) ) ;

        // mammals → dogs (→ labrador), cats
        $mammals = $tree[ 0 ] ;
        $this->assertSame( [ 'dogs' , 'cats' ] , array_column( $mammals[ 'children' ] , '_key' ) ) ;
        $this->assertSame( [ 'labrador' ] , array_column( $mammals[ 'children' ][ 0 ][ 'children' ] , '_key' ) ) ;
        $this->assertSame( [] , $mammals[ 'children' ][ 1 ][ 'children' ] ) ; // cats: leaf

        // birds → eagles
        $this->assertSame( [ 'eagles' ] , array_column( $tree[ 1 ][ 'children' ] , '_key' ) ) ;
    }

    public function testInfersRootsWhenNoRootKeyGiven() :void
    {
        // Depth-1 nodes point at 'animals', which is absent from the list → they are roots.
        $tree = buildTree( $this->thesaurus() ) ;

        $this->assertSame( [ 'mammals' , 'birds' ] , array_column( $tree , '_key' ) ) ;
        $this->assertSame( [ 'dogs' , 'cats' ] , array_column( $tree[ 0 ][ 'children' ] , '_key' ) ) ;
    }

    public function testHonorsACustomParentSourceField() :void
    {
        $flat =
        [
            [ 'id' => 'b' , 'broader' => 'a' ] ,
            [ 'id' => 'c' , 'broader' => 'b' ] ,
        ] ;

        $tree = buildTree( $flat , parentSource: 'broader' , rootKey: 'a' , keyField: 'id' ) ;

        $this->assertSame( [ 'b' ] , array_column( $tree , 'id' ) ) ;
        $this->assertSame( [ 'c' ] , array_column( $tree[ 0 ][ 'children' ] , 'id' ) ) ;
    }

    public function testHonorsACustomChildrenKey() :void
    {
        $tree = buildTree( $this->thesaurus() , rootKey: 'animals' , childrenKey: 'kids' ) ;

        $this->assertArrayHasKey( 'kids' , $tree[ 0 ] ) ;
        $this->assertArrayNotHasKey( 'children' , $tree[ 0 ] ) ;
    }

    public function testCycleIsGuardedAndTerminates() :void
    {
        // Pathological data: A ↔ B reference each other.
        $tree = buildTree
        (
            [ [ '_key' => 'A' , '_parent' => 'B' ] , [ '_key' => 'B' , '_parent' => 'A' ] ] ,
            rootKey: 'A'
        ) ;

        // Descends A → B, but B's child A is already on the branch → stops (no infinite loop).
        $this->assertSame( [ 'B' ] , array_column( $tree , '_key' ) ) ;
        $this->assertSame( [ 'A' ] , array_column( $tree[ 0 ][ 'children' ] , '_key' ) ) ;
        $this->assertSame( [] , $tree[ 0 ][ 'children' ][ 0 ][ 'children' ] ) ; // A not re-descended
    }

    public function testNonArrayRowsAreIgnored() :void
    {
        $flat =
        [
            [ '_key' => 'mammals' , '_parent' => 'animals' ] ,
            'garbage' ,
            42 ,
            [ '_key' => 'dogs' , '_parent' => 'mammals' ] ,
        ] ;

        $tree = buildTree( $flat , rootKey: 'animals' ) ;

        $this->assertSame( [ 'mammals' ] , array_column( $tree , '_key' ) ) ;
        $this->assertSame( [ 'dogs' ] , array_column( $tree[ 0 ][ 'children' ] , '_key' ) ) ;
    }

    public function testOrphanBecomesRootWhenParentAbsent() :void
    {
        // 'lonely' points at a parent that is nowhere in the list → root (no rootKey given).
        $tree = buildTree( [ [ '_key' => 'lonely' , '_parent' => 'ghost' ] ] ) ;

        $this->assertSame( [ 'lonely' ] , array_column( $tree , '_key' ) ) ;
        $this->assertSame( [] , $tree[ 0 ][ 'children' ] ) ;
    }
}
