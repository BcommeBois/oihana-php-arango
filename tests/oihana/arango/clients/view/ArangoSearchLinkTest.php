<?php

namespace tests\oihana\arango\clients\view ;

use oihana\arango\clients\view\ArangoSearchLink ;
use oihana\arango\clients\view\enums\StoreValues ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ArangoSearchLink} — the recursive value object
 * describing a single link entry of an arangosearch view.
 */
#[CoversClass( ArangoSearchLink::class )]
class ArangoSearchLinkTest extends TestCase
{
    public function testEmptyLinkSerialisesToEmptyArray() :void
    {
        $this->assertSame( [] , new ArangoSearchLink()->toArray() ) ;
    }

    public function testAnalyzersAreReindexedAsList() :void
    {
        $payload = new ArangoSearchLink
        (
            analyzers : [ 5 => 'text_en' , 10 => 'identity' ] ,
        )->toArray() ;

        $this->assertSame( [ 'text_en' , 'identity' ] , $payload[ 'analyzers' ] ) ;
    }

    public function testOptionalFlagsAreEmittedWhenSet() :void
    {
        $payload = new ArangoSearchLink
        (
            includeAllFields   : true ,
            trackListPositions : true ,
            storeValues        : StoreValues::ID ,
            inBackground       : false ,
        )->toArray() ;

        $this->assertTrue ( $payload[ 'includeAllFields'   ] ) ;
        $this->assertTrue ( $payload[ 'trackListPositions' ] ) ;
        $this->assertSame( 'id' , $payload[ 'storeValues'  ] ) ;
        $this->assertFalse( $payload[ 'inBackground'       ] ) ;
    }

    public function testNullOptionalFlagsAreOmitted() :void
    {
        $payload = new ArangoSearchLink( analyzers : [ 'text_en' ] )->toArray() ;

        $this->assertArrayNotHasKey( 'includeAllFields'   , $payload ) ;
        $this->assertArrayNotHasKey( 'trackListPositions' , $payload ) ;
        $this->assertArrayNotHasKey( 'storeValues'        , $payload ) ;
        $this->assertArrayNotHasKey( 'inBackground'       , $payload ) ;
        $this->assertArrayNotHasKey( 'fields'             , $payload ) ;
    }

    public function testNestedFieldsAreSerialisedRecursively() :void
    {
        $payload = new ArangoSearchLink
        (
            analyzers : [ 'identity' ] ,
            fields    :
            [
                'title' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
                'body'  => new ArangoSearchLink
                (
                    analyzers          : [ 'text_en' , 'stem_en' ] ,
                    trackListPositions : true ,
                ) ,
            ] ,
        )->toArray() ;

        $this->assertSame
        (
            [
                'analyzers' => [ 'text_en' ] ,
            ] ,
            $payload[ 'fields' ][ 'title' ] ,
        ) ;

        $this->assertSame
        (
            [
                'analyzers'          => [ 'text_en' , 'stem_en' ] ,
                'trackListPositions' => true ,
            ] ,
            $payload[ 'fields' ][ 'body' ] ,
        ) ;
    }

    public function testTwoLevelRecursion() :void
    {
        $payload = new ArangoSearchLink
        (
            fields :
            [
                'author' => new ArangoSearchLink
                (
                    fields :
                    [
                        'name' => new ArangoSearchLink( analyzers : [ 'text_en' ] ) ,
                    ] ,
                ) ,
            ] ,
        )->toArray() ;

        $this->assertSame
        (
            [ 'analyzers' => [ 'text_en' ] ] ,
            $payload[ 'fields' ][ 'author' ][ 'fields' ][ 'name' ] ,
        ) ;
    }

    public function testPlainArrayFieldsArePassedThrough() :void
    {
        // The VO accepts raw arrays in `fields` for the round-trip
        // case (server description fed back into the VO).
        $payload = new ArangoSearchLink
        (
            fields :
            [
                'title' => [ 'analyzers' => [ 'text_en' ] , 'includeAllFields' => true ] ,
            ] ,
        )->toArray() ;

        $this->assertSame
        (
            [ 'analyzers' => [ 'text_en' ] , 'includeAllFields' => true ] ,
            $payload[ 'fields' ][ 'title' ] ,
        ) ;
    }
}
