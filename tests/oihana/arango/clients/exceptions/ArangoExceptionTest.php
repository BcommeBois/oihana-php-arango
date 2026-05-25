<?php

namespace tests\oihana\arango\clients\exceptions ;

use Exception ;
use RuntimeException ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\HttpException ;
use oihana\arango\clients\exceptions\MaintenanceException ;
use oihana\arango\clients\exceptions\enums\ErrorCode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ArangoException} — base exception of the ArangoDB client.
 */
#[CoversClass( ArangoException::class )]
class ArangoExceptionTest extends TestCase
{
    public function testDefaultsAreEmpty() :void
    {
        $exception = new ArangoException() ;

        $this->assertSame( ''   , $exception->getMessage() ) ;
        $this->assertSame( 0    , $exception->getCode()    ) ;
        $this->assertNull( $exception->errorNum ) ;
        $this->assertNull( $exception->getPrevious() ) ;
    }

    public function testFullConstructorWiresAllFields() :void
    {
        $previous  = new RuntimeException( 'boom' ) ;
        $exception = new ArangoException
        (
            message    : 'document not found' ,
            errorNum   : 1202 ,
            httpStatus : 404 ,
            previous   : $previous ,
        ) ;

        $this->assertSame( 'document not found' , $exception->getMessage() ) ;
        $this->assertSame( 404                  , $exception->getCode()    ) ;
        $this->assertSame( 1202                 , $exception->errorNum     ) ;
        $this->assertSame( $previous            , $exception->getPrevious() ) ;
    }

    public function testIsSafeToRetryDefaultsToFalse() :void
    {
        $this->assertFalse( ( new ArangoException() )->isSafeToRetry() ) ;
    }

    public function testIsAStandardExceptionForCatchAllInterop() :void
    {
        $exception = new ArangoException( 'boom' ) ;

        $this->assertInstanceOf( Exception::class , $exception ) ;
    }

    // =========================================================================
    // fromResponse() — factory mapping
    // =========================================================================

    public function testFromResponseReturnsConflictForErrorNum1200() :void
    {
        $exception = ArangoException::fromResponse
        (
            httpStatus : 409 ,
            body       : [ 'errorNum' => 1200 , 'errorMessage' => 'conflict on doc' ] ,
        ) ;

        $this->assertInstanceOf( ConflictException::class , $exception ) ;
        $this->assertSame( 'conflict on doc'           , $exception->getMessage() ) ;
        $this->assertSame( 409                         , $exception->getCode()    ) ;
        $this->assertSame( ErrorCode::ARANGO_CONFLICT  , $exception->errorNum     ) ;
        $this->assertTrue( $exception->isSafeToRetry() ) ;
    }

    public function testFromResponseReturnsMaintenanceForErrorNum3002() :void
    {
        $exception = ArangoException::fromResponse
        (
            httpStatus : 503 ,
            body       : [ 'errorNum' => 3002 , 'errorMessage' => 'cluster backend unavailable' ] ,
        ) ;

        $this->assertInstanceOf( MaintenanceException::class , $exception ) ;
        $this->assertSame( 503                                    , $exception->getCode() ) ;
        $this->assertSame( ErrorCode::CLUSTER_BACKEND_UNAVAILABLE , $exception->errorNum  ) ;
        $this->assertTrue( $exception->isSafeToRetry() ) ;
    }

    public function testFromResponseReturnsHttpExceptionForUnknownErrorNum() :void
    {
        $exception = ArangoException::fromResponse
        (
            httpStatus : 404 ,
            body       : [ 'errorNum' => 1202 , 'errorMessage' => 'document not found' ] ,
        ) ;

        $this->assertInstanceOf( HttpException::class , $exception ) ;
        $this->assertSame( 'document not found' , $exception->getMessage() ) ;
        $this->assertSame( 404                  , $exception->getCode()    ) ;
        $this->assertSame( 1202                 , $exception->errorNum     ) ;
        $this->assertFalse( $exception->isSafeToRetry() ) ;
    }

    public function testFromResponseHandlesMissingErrorNum() :void
    {
        $exception = ArangoException::fromResponse
        (
            httpStatus : 500 ,
            body       : [ 'errorMessage' => 'internal server error' ] ,
        ) ;

        $this->assertInstanceOf( HttpException::class , $exception ) ;
        $this->assertNull( $exception->errorNum ) ;
        $this->assertSame( 500                     , $exception->getCode()    ) ;
        $this->assertSame( 'internal server error' , $exception->getMessage() ) ;
    }

    public function testFromResponseFallsBackToGenericMessageWhenAbsent() :void
    {
        $exception = ArangoException::fromResponse( httpStatus : 500 , body : [] ) ;

        $this->assertSame( 'ArangoDB error' , $exception->getMessage() ) ;
    }

    public function testFromResponsePreservesPreviousException() :void
    {
        $previous  = new RuntimeException( 'guzzle blew up' ) ;
        $exception = ArangoException::fromResponse
        (
            httpStatus : 409 ,
            body       : [ 'errorNum' => 1200 , 'errorMessage' => 'conflict' ] ,
            previous   : $previous ,
        ) ;

        $this->assertSame( $previous , $exception->getPrevious() ) ;
    }
}
