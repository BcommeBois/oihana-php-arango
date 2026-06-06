<?php

namespace tests\oihana\arango\auth;

use oihana\arango\enums\Arango;
use oihana\arango\auth\traits\models\InvitationsModelTrait;

use org\schema\constants\Schema;

use xyz\oihana\schema\auth\Invitation;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;

use tests\oihana\arango\auth\mocks\FakeDocuments;
use tests\oihana\arango\auth\mocks\InvitationsModelHost;

/**
 * Characterization coverage for {@see InvitationsModelTrait::cancelPendingInvitations()}
 * — the user-deletion cascade that soft-cancels every pending invitation.
 *
 * @package tests\oihana\arango\auth
 * @author  Marc Alcaraz
 */
#[CoversTrait( InvitationsModelTrait::class )]
class InvitationsModelTraitTest extends TestCase
{
    public function testIsNoOpWithoutModel() :void
    {
        $host = new InvitationsModelHost( null ) ;

        $host->callCancel( 'u1' ) ;

        $this->assertTrue( true ) ;
    }

    public function testIsNoOpWhenNoPendingInvitation() :void
    {
        $model = new FakeDocuments( 'invitations' ) ;
        $model->listResult = [] ;

        $host = new InvitationsModelHost( $model ) ;
        $host->callCancel( 'u1' ) ;

        $this->assertCount( 1 , $model->listCalls ) ;
        $this->assertSame( [] , $model->updateCalls ) ;
    }

    public function testListQueryFiltersOnUserAndPendingStatus() :void
    {
        $model = new FakeDocuments( 'invitations' ) ;
        $model->listResult = [] ;

        $host = new InvitationsModelHost( $model ) ;
        $host->callCancel( 'user-42' ) ;

        [ $init ] = $model->listCalls ;

        // both binds are materialised for the conditions
        $this->assertSame( 'user-42' , $init[ Arango::BINDS ][ 'invitationUserKey' ] ) ;
        $this->assertSame( Invitation::ACTION_STATUS_PENDING , $init[ Arango::BINDS ][ 'invitationStatus' ] ) ;
    }

    public function testCancelsEachPendingInvitationAndSkipsKeyless() :void
    {
        $model = new FakeDocuments( 'invitations' ) ;
        $model->listResult =
        [
            (object) [ '_key' => 'inv1' ] ,
            (object) [ '_key' => '' ] ,    // keyless → skipped
            (object) [ '_key' => 'inv2' ] ,
        ] ;

        $host = new InvitationsModelHost( $model ) ;
        $host->callCancel( 'u1' ) ;

        $this->assertCount( 2 , $model->updateCalls ) ;

        [ $first ] = $model->updateCalls ;

        $this->assertSame( Schema::_KEY , $first[ Arango::KEY ] ) ;
        $this->assertSame( 'inv1' , $first[ Arango::VALUE ] ) ;
        $this->assertSame
        (
            Invitation::ACTION_STATUS_CANCELLED ,
            $first[ Arango::DOC ][ Schema::ACTION_STATUS ]
        ) ;
        $this->assertArrayHasKey( Schema::MODIFIED , $first[ Arango::DOC ] ) ;

        $this->assertSame( 'inv2' , $model->updateCalls[ 1 ][ Arango::VALUE ] ) ;
    }

    public function testSwallowsFailureAndLogsWhenLoggable() :void
    {
        $model = new FakeDocuments( 'invitations' ) ;
        $model->listThrows = new \RuntimeException( 'list boom' ) ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->once() )
               ->method( 'warning' )
               ->with( $this->stringContains( 'Cascade cancel of pending invitations failed for user u1' ) ) ;

        $host = new InvitationsModelHost( $model , $logger ) ;

        // must not bubble
        $host->callCancel( 'u1' ) ;
    }

    public function testSwallowsFailureSilentlyWhenNotLoggable() :void
    {
        $model = new FakeDocuments( 'invitations' ) ;
        $model->updateThrows = new \RuntimeException( 'update boom' ) ;
        $model->listResult = [ (object) [ '_key' => 'inv1' ] ] ;

        $logger = $this->createMock( LoggerInterface::class ) ;
        $logger->expects( $this->never() )->method( 'warning' ) ;

        $host = new InvitationsModelHost( $model , $logger ) ;

        $host->callCancel( 'u1' , loggable: false ) ;

        $this->assertCount( 1 , $model->updateCalls ) ;
    }
}
