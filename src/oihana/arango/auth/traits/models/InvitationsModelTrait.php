<?php

namespace oihana\arango\auth\traits\models;

use org\iso\Iso8601Format;
use Throwable;

use DI\Container;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use xyz\oihana\schema\auth\Invitation;

use org\schema\constants\Schema;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\binds\aqlBind;
use function oihana\arango\db\operators\equal;
use function oihana\arango\models\helpers\getDocuments;
use function oihana\core\strings\key;

/**
 * Standalone trait for the `invitations` Documents model dependency.
 *
 * Used by middlewares / controllers / commands that need to read or update
 * invitation records (user invite flow, password-set acceptance, email-change
 * confirmation, post-callback finalization, etc.).
 *
 * @package oihana\arango\auth\traits\models
 * @author  Marc Alcaraz
 */
trait InvitationsModelTrait
{
    /**
     * Initialization key for the invitations Documents model.
     */
    public const string INVITATIONS_MODEL = 'invitationsModel' ;

    /**
     * The invitations Documents model.
     */
    protected ?Documents $invitationsModel = null ;

    /**
     * Soft-cancels every pending invitation targeting the given user.
     *
     * Runs as part of the user deletion cascade: the invitation document
     * is kept for audit with `actionStatus = cancelled` and a fresh
     * `modified` timestamp.
     *
     * Failures are logged but swallowed — a broken invitation update must
     * never prevent the core user deletion from proceeding.
     *
     * @param string $userKey
     * @param bool   $loggable
     *
     * @return void
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    protected function cancelPendingInvitations( string $userKey , bool $loggable = true ) :void
    {
        if( !$this->invitationsModel )
        {
            return ;
        }

        try
        {
            $binds   = [] ;
            $pending = $this->invitationsModel->list
            ([
                AQL::CONDITIONS =>
                [
                    equal( key( Schema::OBJECT        , AQL::DOC ) , aqlBind( $userKey                                , $binds , 'invitationUserKey' ) ) ,
                    equal( key( Schema::ACTION_STATUS , AQL::DOC ) , aqlBind( Invitation::ACTION_STATUS_PENDING , $binds , 'invitationStatus'  ) ) ,
                ] ,
                AQL::BINDS => $binds ,
            ]) ?? [] ;

            if( empty( $pending ) )
            {
                return ;
            }

            $now = gmdate( Iso8601Format::DATE_TIME_ZULU ) ;

            foreach( $pending as $invitation )
            {
                if( empty( $invitation->_key ) )
                {
                    continue ;
                }

                $this->invitationsModel->update
                ([
                    Arango::KEY   => Schema::_KEY ,
                    Arango::VALUE => $invitation->_key ,
                    Arango::DOC   =>
                    [
                        Schema::ACTION_STATUS => Invitation::ACTION_STATUS_CANCELLED ,
                        Schema::MODIFIED      => $now ,
                    ] ,
                ]) ;
            }
        }
        catch( Throwable $e )
        {
            if( $loggable )
            {
                $this->logger?->warning( "Cascade cancel of pending invitations failed for user $userKey: " . $e->getMessage() ) ;
            }
        }
    }

    /**
     * Initializes the invitations model dependency from the $init array.
     *
     * @param array $init The initialization array.
     * @param Container|null $container The DI container.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     *
     * @return static
     */
    protected function initializeInvitationsModel( array $init , ?Container $container ) :static
    {
        $this->invitationsModel = getDocuments( $init , $container , self::INVITATIONS_MODEL ) ;
        return $this ;
    }
}
