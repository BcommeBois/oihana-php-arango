<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use function oihana\arango\models\helpers\isAuthorized;

final class IsAuthorizedTest extends TestCase
{
    public function testNoRequiresKeyAllowsByDefault() : void
    {
        $this->assertTrue( isAuthorized( [] ) ) ;
        $this->assertTrue( isAuthorized( [ AQL::MODEL => 'someEdge' ] ) ) ;
    }

    public function testEmptyRequiresListAllowsByDefault() : void
    {
        $denyAll = fn() : bool => false ;

        $this->assertTrue
        (
            isAuthorized
            (
                [ Field::REQUIRES => [] ] ,
                [ Arango::AUTHORIZER => $denyAll ]
            )
        ) ;
    }

    public function testMissingAuthorizerCallableFailsOpen() : void
    {
        $this->assertTrue
        (
            isAuthorized( [ Field::REQUIRES => 'users.roles:list' ] )
        ) ;
    }

    public function testNonCallableAuthorizerFailsOpen() : void
    {
        $this->assertTrue
        (
            isAuthorized
            (
                [ Field::REQUIRES => 'users.roles:list' ] ,
                [ Arango::AUTHORIZER => 'not a callable' ]
            )
        ) ;
    }

    public function testStringSubjectGrantedReturnsTrue() : void
    {
        $allow = fn( string $subject ) : bool => $subject === 'users.roles:list' ;

        $this->assertTrue
        (
            isAuthorized
            (
                [ Field::REQUIRES => 'users.roles:list' ] ,
                [ Arango::AUTHORIZER => $allow ]
            )
        ) ;
    }

    public function testStringSubjectDeniedReturnsFalse() : void
    {
        $deny = fn() : bool => false ;

        $this->assertFalse
        (
            isAuthorized
            (
                [ Field::REQUIRES => 'users.roles:list' ] ,
                [ Arango::AUTHORIZER => $deny ]
            )
        ) ;
    }

    public function testArrayOrAllowsWhenAtLeastOneSubjectMatches() : void
    {
        $authorizer = fn( string $subject ) : bool => $subject === 'users.roles:admin' ;

        $this->assertTrue
        (
            isAuthorized
            (
                [ Field::REQUIRES => [ 'users.roles:list' , 'users.roles:admin' ] ] ,
                [ Arango::AUTHORIZER => $authorizer ]
            )
        ) ;
    }

    public function testArrayOrDeniesWhenNoSubjectMatches() : void
    {
        $denyAll = fn() : bool => false ;

        $this->assertFalse
        (
            isAuthorized
            (
                [ Field::REQUIRES => [ 'users.roles:list' , 'users.roles:admin' ] ] ,
                [ Arango::AUTHORIZER => $denyAll ]
            )
        ) ;
    }

    public function testNonStringSubjectsAreFilteredOut() : void
    {
        $captured = [] ;
        $authorizer = function( string $subject ) use ( &$captured ) : bool
        {
            $captured[] = $subject ;
            return false ;
        } ;

        isAuthorized
        (
            [ Field::REQUIRES => [ 'users.roles:list' , 42 , null , 'users.roles:admin' ] ] ,
            [ Arango::AUTHORIZER => $authorizer ]
        ) ;

        $this->assertSame( [ 'users.roles:list' , 'users.roles:admin' ] , $captured ) ;
    }

    public function testAuthorizerShortCircuitsOnFirstGrant() : void
    {
        $callCount = 0 ;
        $authorizer = function( string $subject ) use ( &$callCount ) : bool
        {
            $callCount++ ;
            return $subject === 'users.roles:list' ;
        } ;

        $this->assertTrue
        (
            isAuthorized
            (
                [ Field::REQUIRES => [ 'users.roles:list' , 'users.roles:admin' , 'users.roles:owner' ] ] ,
                [ Arango::AUTHORIZER => $authorizer ]
            )
        ) ;

        $this->assertSame( 1 , $callCount , 'Authorizer should stop on first grant' ) ;
    }

    public function testAuthorizerOnlyCountsBoolTrueAsGrant() : void
    {
        // truthy non-bool should NOT count — keeps the contract strict.
        $authorizer = fn() : mixed => 1 ;

        $this->assertFalse
        (
            isAuthorized
            (
                [ Field::REQUIRES => 'users.roles:list' ] ,
                [ Arango::AUTHORIZER => $authorizer ]
            )
        ) ;
    }
}
