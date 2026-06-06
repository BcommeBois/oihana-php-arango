<?php

namespace tests\oihana\arango\auth\mocks;

use oihana\arango\auth\traits\models\InvitationsModelTrait;
use oihana\arango\models\Documents;

use Psr\Log\LoggerInterface;

/**
 * Minimal host composing {@see InvitationsModelTrait} for unit testing its
 * `cancelPendingInvitations()` cascade helper in isolation.
 *
 * Declares the `$logger` seam the trait relies on (`$this->logger?->warning`)
 * and exposes a public proxy for the protected helper.
 *
 * @package tests\oihana\arango\auth\mocks
 * @author  Marc Alcaraz
 */
class InvitationsModelHost
{
    use InvitationsModelTrait ;

    /**
     * The optional logger the trait writes cascade-failure warnings to.
     */
    public ?LoggerInterface $logger = null ;

    /**
     * @param Documents|null        $invitationsModel
     * @param LoggerInterface|null $logger
     */
    public function __construct( ?Documents $invitationsModel = null , ?LoggerInterface $logger = null )
    {
        $this->invitationsModel = $invitationsModel ;
        $this->logger           = $logger ;
    }

    /**
     * Public proxy for {@see InvitationsModelTrait::cancelPendingInvitations()}.
     *
     * @param string $userKey
     * @param bool   $loggable
     *
     * @return void
     */
    public function callCancel( string $userKey , bool $loggable = true ) :void
    {
        $this->cancelPendingInvitations( $userKey , $loggable ) ;
    }
}
