<?php

namespace tests\oihana\arango\clients\exceptions ;

use oihana\arango\clients\exceptions\ArangoException ;
use oihana\arango\clients\exceptions\MaintenanceException ;
use oihana\arango\clients\exceptions\enums\ErrorCode ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see MaintenanceException} — cluster backend unavailable
 * (errorNum 3002), typically raised during maintenance windows or
 * coordinator failovers.
 */
#[CoversClass( MaintenanceException::class )]
class MaintenanceExceptionTest extends TestCase
{
    public function testDefaultsCarryMaintenanceSemantics() :void
    {
        $exception = new MaintenanceException() ;

        $this->assertInstanceOf( ArangoException::class , $exception ) ;
        $this->assertSame( ErrorCode::CLUSTER_BACKEND_UNAVAILABLE , $exception->errorNum ) ;
        $this->assertSame( 503                                    , $exception->getCode() ) ;
        $this->assertSame( 'Cluster backend unavailable'          , $exception->getMessage() ) ;
    }

    public function testIsSafeToRetry() :void
    {
        $this->assertTrue( ( new MaintenanceException() )->isSafeToRetry() ) ;
    }
}
