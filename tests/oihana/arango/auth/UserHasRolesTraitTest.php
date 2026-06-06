<?php

namespace tests\oihana\arango\auth;

use oihana\arango\auth\traits\edges\UserHasRolesTrait;
use oihana\exceptions\http\Error409;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use tests\oihana\arango\auth\mocks\FakeEdges;
use tests\oihana\arango\auth\mocks\UserHasRolesHost;

/**
 * Characterization coverage for {@see UserHasRolesTrait::assignRoles()} — the
 * cascade that wires user→role edges at user-creation time.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversTrait( UserHasRolesTrait::class )]
class UserHasRolesTraitTest extends TestCase
{
    public function testIsNoOpWithoutModel() :void
    {
        $host = new UserHasRolesHost( null ) ;

        $host->callAssignRoles( 'u1' , [ 'r1' , 'r2' ] ) ;

        $this->assertTrue( true ) ;
    }

    public function testInsertsOneEdgePerRole() :void
    {
        $edges = new FakeEdges( 'user_has_roles' ) ;

        $host = new UserHasRolesHost( $edges ) ;
        $host->callAssignRoles( 'u1' , [ 'r1' , 'r2' ] ) ;

        $this->assertSame
        (
            [ [ 'u1' , 'r1' ] , [ 'u1' , 'r2' ] ] ,
            $edges->insertEdgeCalls
        ) ;
    }

    public function testSwallowsError409AndContinuesWithRemainingRoles() :void
    {
        $edges = new FakeEdges( 'user_has_roles' ) ;

        // the first role already has the edge → Error409, the second succeeds
        $edges->insertEdgeThrowResolver = fn( string $from , string $to ) =>
            $to === 'r1' ? new Error409( 'duplicate' ) : null ;

        $host = new UserHasRolesHost( $edges ) ;
        $host->callAssignRoles( 'u1' , [ 'r1' , 'r2' ] ) ;

        // both were attempted; the 409 on r1 did not abort the loop
        $this->assertSame
        (
            [ [ 'u1' , 'r1' ] , [ 'u1' , 'r2' ] ] ,
            $edges->insertEdgeCalls
        ) ;
    }
}
