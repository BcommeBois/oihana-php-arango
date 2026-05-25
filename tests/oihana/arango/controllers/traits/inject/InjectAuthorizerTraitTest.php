<?php

namespace tests\oihana\arango\controllers\traits\inject;

use Closure;

use PHPUnit\Framework\TestCase;

use oihana\arango\controllers\traits\inject\InjectAuthorizerTrait;
use oihana\arango\enums\Arango;

/**
 * Concrete fixture exposing the protected trait surface. PHP 8.2+ forbids
 * `TraitName::CONST` access through the trait directly (and the same applies
 * to protected methods in tests), so we go through a named consumer class.
 */
final class InjectAuthorizerTraitFixture
{
    use InjectAuthorizerTrait ;

    public function callInitialize( array $init , string|array|object|null $authorizer = null ) : static
    {
        return $this->initializeArangoAuthorizer( $init , $authorizer ) ;
    }

    public function callInject( array &$init ) : void
    {
        $this->injectAuthorizer( $init ) ;
    }

    public function authorizer() : ?Closure
    {
        return $this->arangoAuthorizer ;
    }
}

final class InjectAuthorizerTraitTest extends TestCase
{
    public function testInitialiseLeavesAuthorizerNullWhenNothingProvided() : void
    {
        $fixture = new InjectAuthorizerTraitFixture()->callInitialize( [] ) ;

        $this->assertNull( $fixture->authorizer() ) ;
    }

    public function testInitialiseAcceptsExplicitClosure() : void
    {
        $closure = fn() : bool => true ;
        $fixture = ( new InjectAuthorizerTraitFixture() )->callInitialize( [] , $closure ) ;

        $this->assertSame( $closure , $fixture->authorizer() ) ;
    }

    public function testInitialisePullsFromInitWhenNoExplicitArg() : void
    {
        $closure = fn() : bool => true ;
        $fixture = new InjectAuthorizerTraitFixture()
            ->callInitialize( [ Arango::AUTHORIZER => $closure ] ) ;

        $this->assertSame( $closure , $fixture->authorizer() ) ;
    }

    public function testInitialiseExplicitArgumentBeatsInit() : void
    {
        $explicit = fn() : bool => true ;
        $fromInit = fn() : bool => false ;

        $fixture = new InjectAuthorizerTraitFixture()
            ->callInitialize( [ Arango::AUTHORIZER => $fromInit ] , $explicit ) ;

        $this->assertSame( $explicit , $fixture->authorizer() ) ;
    }

    public function testInitialiseWrapsPlainCallableIntoClosure() : void
    {
        $callable = [ self::class , 'staticGrant' ] ;

        $fixture = ( new InjectAuthorizerTraitFixture() )->callInitialize( [] , $callable ) ;

        $this->assertInstanceOf( Closure::class , $fixture->authorizer() ) ;
        $this->assertTrue( ( $fixture->authorizer() )( 'any' ) ) ;
    }

    public function testInitialiseIgnoresNonCallableInitEntry() : void
    {
        $fixture = new InjectAuthorizerTraitFixture()
            ->callInitialize( [ Arango::AUTHORIZER => 'not a callable' ] ) ;

        $this->assertNull( $fixture->authorizer() ) ;
    }

    public function testInjectIsNoOpWhenAuthorizerIsNull() : void
    {
        $init = [] ;
        new InjectAuthorizerTraitFixture()->callInject( $init ) ;

        $this->assertArrayNotHasKey( Arango::AUTHORIZER , $init ) ;
    }

    public function testInjectAddsAuthorizerWhenStored() : void
    {
        $closure = fn() : bool => true ;
        $fixture = new InjectAuthorizerTraitFixture()->callInitialize( [] , $closure ) ;

        $init = [] ;
        $fixture->callInject( $init ) ;

        $this->assertSame( $closure , $init[ Arango::AUTHORIZER ] ?? null ) ;
    }

    public function testInjectDoesNotOverrideExistingEntry() : void
    {
        $stored   = fn() : bool => true ;
        $existing = fn() : bool => false ;

        $fixture = new InjectAuthorizerTraitFixture()->callInitialize( [] , $stored ) ;

        $init = [ Arango::AUTHORIZER => $existing ] ;
        $fixture->callInject( $init ) ;

        $this->assertSame( $existing , $init[ Arango::AUTHORIZER ] ) ;
    }

    public static function staticGrant( string $subject ) : bool
    {
        return true ;
    }
}
