<?php

namespace tests\oihana\arango\clients\exceptions ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\ConflictException ;
use oihana\arango\clients\exceptions\enums\ErrorCode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ConflictException} — write-write conflict (errorNum 1200).
 */
#[CoversClass( ConflictException::class )]
class ConflictExceptionTest extends TestCase
{
    public function testDefaultsCarryConflictSemantics() :void
    {
        $exception = new ConflictException() ;

        $this->assertInstanceOf( ArangoException::class , $exception ) ;
        $this->assertSame( ErrorCode::ARANGO_CONFLICT , $exception->errorNum ) ;
        $this->assertSame( 409                        , $exception->getCode() ) ;
        $this->assertSame( 'Write-write conflict on document' , $exception->getMessage() ) ;
    }

    public function testCustomMessageAndHttpStatusArePreserved() :void
    {
        $exception = new ConflictException( 'duplicate write on key foo' , 412 ) ;

        $this->assertSame( 'duplicate write on key foo' , $exception->getMessage() ) ;
        $this->assertSame( 412                          , $exception->getCode()    ) ;
        $this->assertSame( ErrorCode::ARANGO_CONFLICT   , $exception->errorNum     ) ;
    }

    public function testIsSafeToRetry() :void
    {
        $this->assertTrue( ( new ConflictException() )->isSafeToRetry() ) ;
    }
}
