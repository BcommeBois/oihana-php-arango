<?php

namespace tests\oihana\arango\clients\http ;

use oihana\arango\clients\http\HttpResponse ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see HttpResponse} — value object returned by the HTTP transport.
 */
#[CoversClass( HttpResponse::class )]
class HttpResponseTest extends TestCase
{
    public function testStatusHeadersBodyAndRawArePreserved() :void
    {
        $response = new HttpResponse
        (
            status  : 200 ,
            headers : [ 'Content-Type' => [ 'application/json' ] ] ,
            body    : [ 'result' => [ 1 , 2 , 3 ] ] ,
            raw     : '{"result":[1,2,3]}' ,
        ) ;

        $this->assertSame( 200                                       , $response->status  ) ;
        $this->assertSame( [ 'Content-Type' => [ 'application/json' ] ] , $response->headers ) ;
        $this->assertSame( [ 'result' => [ 1 , 2 , 3 ] ]              , $response->body    ) ;
        $this->assertSame( '{"result":[1,2,3]}'                       , $response->raw     ) ;
    }

    public function testHeaderLookupIsCaseInsensitive() :void
    {
        $response = new HttpResponse( 200 , [ 'Content-Type' => [ 'application/json' ] ] ) ;

        $this->assertSame( 'application/json' , $response->header( 'content-type' ) ) ;
        $this->assertSame( 'application/json' , $response->header( 'Content-Type' ) ) ;
        $this->assertSame( 'application/json' , $response->header( 'CONTENT-TYPE' ) ) ;
    }

    public function testHeaderReturnsNullWhenAbsent() :void
    {
        $response = new HttpResponse( 200 , [ 'X-Foo' => [ 'bar' ] ] ) ;

        $this->assertNull( $response->header( 'X-Missing' ) ) ;
    }

    public function testHeaderReturnsFirstValueOfMultiValueHeader() :void
    {
        $response = new HttpResponse( 200 , [ 'Set-Cookie' => [ 'a=1' , 'b=2' ] ] ) ;

        $this->assertSame( 'a=1' , $response->header( 'Set-Cookie' ) ) ;
    }

    public function testHeaderCastsAScalarHeaderValueToString() :void
    {
        // Defensive branch: a header stored as a scalar (not PSR-7's array shape)
        // is returned as a string.
        $response = new HttpResponse( 200 , [ 'X-Count' => 42 ] ) ;

        $this->assertSame( '42' , $response->header( 'X-Count' ) ) ;
    }

    public function testIsSuccessTrueForTwoHundredRange() :void
    {
        $this->assertTrue( ( new HttpResponse( 200 ) )->isSuccess() ) ;
        $this->assertTrue( ( new HttpResponse( 201 ) )->isSuccess() ) ;
        $this->assertTrue( ( new HttpResponse( 204 ) )->isSuccess() ) ;
        $this->assertTrue( ( new HttpResponse( 299 ) )->isSuccess() ) ;
    }

    public function testIsSuccessFalseOutsideTwoHundredRange() :void
    {
        $this->assertFalse( ( new HttpResponse( 100 ) )->isSuccess() ) ;
        $this->assertFalse( ( new HttpResponse( 301 ) )->isSuccess() ) ;
        $this->assertFalse( ( new HttpResponse( 404 ) )->isSuccess() ) ;
        $this->assertFalse( ( new HttpResponse( 500 ) )->isSuccess() ) ;
    }

    public function testDefaultsAreEmpty() :void
    {
        $response = new HttpResponse( 204 ) ;

        $this->assertSame( [] , $response->headers ) ;
        $this->assertNull( $response->body ) ;
        $this->assertNull( $response->raw  ) ;
    }
}
