<?php

namespace tests\oihana\arango\clients\helpers ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\helpers\mergeWrittenPayload ;

/**
 * Tests for {@see \oihana\arango\clients\helpers\mergeWrittenPayload} —
 * merge the optional `new` / `old` payload of an ArangoDB write
 * response into the meta document.
 */
class MergeWrittenPayloadTest extends TestCase
{
    public function testReturnsBodyUntouchedWhenPayloadFieldAbsent() :void
    {
        $body = [ '_key' => 'a' , '_id' => 'c/a' , '_rev' => 'r1' ] ;

        $this->assertSame( $body , mergeWrittenPayload( $body , 'new' ) ) ;
    }

    public function testReturnsBodyUntouchedWhenPayloadIsNotArray() :void
    {
        $body = [ '_key' => 'a' , 'new' => 'not-an-array' ] ;

        $this->assertSame( $body , mergeWrittenPayload( $body , 'new' ) ) ;
    }

    public function testMergesPayloadAndMetaWithMetaTakingPrecedence() :void
    {
        $body = [
            '_key' => 'a' ,
            '_id'  => 'users/a' ,
            '_rev' => 'r1' ,
            'new'  => [
                '_key' => 'IGNORED' , // meta wins
                '_id'  => 'IGNORED' , // meta wins
                '_rev' => 'IGNORED' , // meta wins
                'name' => 'Alice'    ,
                'age'  => 42         ,
            ] ,
        ] ;

        // array_merge preserves the first array's key positions when keys
        // collide — so the meta keys (_key/_id/_rev) keep the order they
        // had inside the payload, and the new keys (name/age) come after.
        $this->assertSame
        (
            [
                '_key' => 'a' ,
                '_id'  => 'users/a' ,
                '_rev' => 'r1' ,
                'name' => 'Alice' ,
                'age'  => 42 ,
            ] ,
            mergeWrittenPayload( $body , 'new' ) ,
        ) ;
    }

    public function testStripsPayloadFieldFromResult() :void
    {
        $body   = [ '_key' => 'a' , '_rev' => 'r1' , 'new' => [ 'name' => 'Alice' ] ] ;
        $merged = mergeWrittenPayload( $body , 'new' ) ;

        $this->assertArrayNotHasKey( 'new' , $merged ) ;
    }

    public function testHandlesRemoveStyleOldPayload() :void
    {
        // Same semantics for `old` (used on remove() with returnOld: true).
        $body = [
            '_key' => 'a' ,
            '_rev' => 'r2' ,
            'old'  => [ 'name' => 'Alice' , 'role' => 'admin' ] ,
        ] ;

        $this->assertSame
        (
            [
                'name' => 'Alice'  ,
                'role' => 'admin'  ,
                '_key' => 'a'      ,
                '_rev' => 'r2'     ,
            ] ,
            mergeWrittenPayload( $body , 'old' ) ,
        ) ;
    }
}
