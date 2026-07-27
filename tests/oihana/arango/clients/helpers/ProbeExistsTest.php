<?php

namespace tests\oihana\arango\clients\helpers;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\clients\exceptions\ConflictException;
use oihana\arango\clients\exceptions\HttpException;
use oihana\arango\clients\exceptions\MaintenanceException;
use oihana\arango\clients\exceptions\NetworkException;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use RuntimeException;

use function oihana\arango\clients\helpers\probeExists;

/**
 * Coverage for {@see probeExists()} — the existence probe shared by the seven
 * `exists()` / `documentExists()` methods of the client.
 *
 * The class this suite really documents is the **net**: which failures are read
 * as "the resource is missing" and which ones must reach the caller. A 404
 * carried by an {@see HttpException} is the only answer; every sibling of that
 * class describes a server that could not answer at all.
 *
 * @package tests\oihana\arango\clients\helpers
 * @author  Marc Alcaraz
 */
final class ProbeExistsTest extends TestCase
{
    /**
     * @return void
     * @throws ArangoException
     */
    public function testASuccessfulRequestMeansItExists() :void
    {
        $this->assertTrue( probeExists( fn() => 'any response' ) ) ;
    }

    /**
     * @return void
     * @throws ArangoException
     */
    public function testA404MeansItDoesNotExist() :void
    {
        $this->assertFalse( probeExists( fn() => throw new HttpException( 'not found' , 1202 , 404 ) ) ) ;
    }

    /**
     * Any other HTTP status is a failure, not an answer.
     */
    public function testAnyOtherHttpStatusIsRethrownUnchanged() :void
    {
        $thrown = new HttpException( 'forbidden' , null , 403 ) ;

        try
        {
            probeExists( fn() => throw $thrown ) ;
            $this->fail( 'The exception should have been rethrown.' ) ;
        }
        catch ( ArangoException $caught )
        {
            $this->assertSame( $thrown , $caught , 'The original exception must reach the caller untouched.' ) ;
        }
    }

    /**
     * The three siblings of HttpException describe a server that could not
     * answer — a write conflict, a cluster under maintenance, an unreachable
     * host — never a missing resource. They must go through the net whatever
     * status they carry, which is why the probe catches HttpException and not
     * its parent ArangoException.
     *
     * @return array<string,array{0:ArangoException}>
     * @return array
     */
    public static function nonHttpFailures() :array
    {
        return
        [
            'conflict (409)'    => [ new ConflictException() ] ,
            'maintenance (503)' => [ new MaintenanceException() ] ,
            'network (0)'       => [ new NetworkException( 'connection refused' ) ] ,
        ] ;
    }

    /**
     * @param ArangoException $failure
     * @throws ArangoException
     */
    #[ DataProvider( 'nonHttpFailures' ) ]
    public function testANonHttpFailureIsNeverReadAsAnAnswer( ArangoException $failure ) :void
    {
        $this->expectException( $failure::class ) ;

        probeExists( fn() => throw $failure ) ;
    }

    /**
     * The guard that makes the choice explicit: were one of those siblings ever
     * to carry a 404, it would still not be swallowed. This is the whole reason
     * the net is HttpException rather than ArangoException — the three call
     * sites that used to catch the parent would have answered "missing" here.
     * @return void
     * @throws ArangoException
     */
    public function testASiblingCarryingA404IsStillNotSwallowed() :void
    {
        $this->expectException( MaintenanceException::class ) ;

        probeExists( fn() => throw new MaintenanceException( 'odd but not a 404 answer' , 404 ) ) ;
    }

    /**
     * The probe only interprets ArangoDB failures; anything else is somebody else's problem and travels untouched.
     * @return void
     * @throws ArangoException
     */
    public function testAForeignExceptionIsNotCaught() :void
    {
        $this->expectException( RuntimeException::class ) ;

        probeExists( fn() => throw new RuntimeException( 'not an Arango failure' ) ) ;
    }
}
