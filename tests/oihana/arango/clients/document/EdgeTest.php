<?php

namespace tests\oihana\arango\clients\document ;

use org\schema\constants\Schema ;

use oihana\arango\clients\document\Document ;
use oihana\arango\clients\document\Edge ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Edge} — value object wrapping a single ArangoDB edge
 * document. Inherits every accessor from {@see Document} and adds
 * `getFrom()` / `getTo()`.
 */
#[CoversClass( Edge::class )]
class EdgeTest extends TestCase
{
    public function testIsAlsoADocument() :void
    {
        $this->assertInstanceOf( Document::class , new Edge() ) ;
    }

    public function testGetFromReturnsUnderscoreFrom() :void
    {
        $edge = new Edge( [ Schema::_FROM => 'users/alice' ] ) ;
        $this->assertSame( 'users/alice' , $edge->getFrom() ) ;
    }

    public function testGetToReturnsUnderscoreTo() :void
    {
        $edge = new Edge( [ Schema::_TO => 'users/bob' ] ) ;
        $this->assertSame( 'users/bob' , $edge->getTo() ) ;
    }

    public function testGetFromAndGetToReturnNullWhenAbsent() :void
    {
        $edge = new Edge() ;

        $this->assertNull( $edge->getFrom() ) ;
        $this->assertNull( $edge->getTo()   ) ;
    }

    public function testReservedDocumentAccessorsRemainAvailable() :void
    {
        $edge = new Edge
        (
            [
                Schema::_KEY  => 'e1' ,
                Schema::_ID   => 'follows/e1' ,
                Schema::_REV  => 'r1' ,
                Schema::_FROM => 'users/alice' ,
                Schema::_TO   => 'users/bob'   ,
                'since'       => '2026-01-01'  ,
            ]
        ) ;

        $this->assertSame( 'e1'           , $edge->getKey()  ) ;
        $this->assertSame( 'follows/e1'   , $edge->getId()   ) ;
        $this->assertSame( 'r1'           , $edge->getRev()  ) ;
        $this->assertSame( 'users/alice'  , $edge->getFrom() ) ;
        $this->assertSame( 'users/bob'    , $edge->getTo()   ) ;
        $this->assertSame( '2026-01-01'   , $edge->get( 'since' ) ) ;
        $this->assertFalse( $edge->isNew() ) ;
    }
}
