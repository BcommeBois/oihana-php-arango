<?php

namespace tests\oihana\arango\clients\helpers ;

use PHPUnit\Framework\TestCase ;

use function oihana\arango\clients\helpers\unwrapField ;

/**
 * Tests for {@see \oihana\arango\clients\helpers\unwrapField} —
 * extract a wrapper field from an ArangoDB response envelope, with a
 * typed fallback when the field is missing.
 */
class UnwrapFieldTest extends TestCase
{
    public function testExtractsPresentField() :void
    {
        $body = [ 'graph' => [ 'name' => 'workplaces' ] , 'error' => false ] ;

        $this->assertSame( [ 'name' => 'workplaces' ] , unwrapField( $body , 'graph' ) ) ;
    }

    public function testReturnsFallbackWhenFieldMissing() :void
    {
        $this->assertNull( unwrapField( [ 'other' => 1 ] , 'graph' ) ) ;
        $this->assertSame( 'default' , unwrapField( [ 'other' => 1 ] , 'graph' , 'default' ) ) ;
    }

    public function testReturnsFallbackWhenFieldIsNotArray() :void
    {
        // Defensive: the helper requires the field value to be an array.
        $body = [ 'graph' => 'not-an-object' ] ;

        $this->assertNull( unwrapField( $body , 'graph' ) ) ;
        $this->assertSame( [ 'fallback' ] , unwrapField( $body , 'graph' , [ 'fallback' ] ) ) ;
    }

    public function testReturnsFallbackWhenBodyIsNotArray() :void
    {
        $this->assertNull( unwrapField( 'not-an-array' , 'graph' ) ) ;
        $this->assertNull( unwrapField( null , 'graph' ) ) ;
        $this->assertSame( 42 , unwrapField( 'not-an-array' , 'graph' , 42 ) ) ;
    }

    public function testFallbackOnSelfPattern() :void
    {
        // Common pattern: when the wrapper is absent, treat the body
        // itself as the payload (defensive against bare responses).
        $bareBody = [ 'name' => 'g' , 'edgeDefinitions' => [] ] ;

        $this->assertSame( $bareBody , unwrapField( $bareBody , 'graph' , $bareBody ) ) ;
    }
}
