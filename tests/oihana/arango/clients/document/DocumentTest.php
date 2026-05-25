<?php

namespace tests\oihana\arango\clients\document ;

use org\schema\constants\Schema ;

use oihana\arango\clients\document\Document ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see Document} — immutable wrapper around a single ArangoDB
 * document payload.
 */
#[CoversClass( Document::class )]
class DocumentTest extends TestCase
{
    // =========================================================================
    // Construction
    // =========================================================================

    public function testDefaultsToEmptyDocument() :void
    {
        $document = new Document() ;

        $this->assertSame( [] , $document->data    ) ;
        $this->assertSame( [] , $document->toArray() ) ;
        $this->assertTrue ( $document->isNew() ) ;
    }

    public function testStoresProvidedData() :void
    {
        $data     = [ Schema::_KEY => 'k1' , Schema::_ID => 'col/k1' , 'name' => 'Marc' ] ;
        $document = new Document( $data ) ;

        $this->assertSame( $data , $document->data    ) ;
        $this->assertSame( $data , $document->toArray() ) ;
    }

    // =========================================================================
    // Reserved-attribute accessors
    // =========================================================================

    public function testGetKeyReturnsUnderscoreKey() :void
    {
        $document = new Document( [ Schema::_KEY => 'abc' ] ) ;
        $this->assertSame( 'abc' , $document->getKey() ) ;
    }

    public function testGetIdReturnsUnderscoreId() :void
    {
        $document = new Document( [ Schema::_ID => 'users/abc' ] ) ;
        $this->assertSame( 'users/abc' , $document->getId() ) ;
    }

    public function testGetRevReturnsUnderscoreRev() :void
    {
        $document = new Document( [ Schema::_REV => '_h7q0X--' ] ) ;
        $this->assertSame( '_h7q0X--' , $document->getRev() ) ;
    }

    public function testGetKeyIdRevReturnNullWhenAbsent() :void
    {
        $document = new Document( [ 'name' => 'Marc' ] ) ;

        $this->assertNull( $document->getKey() ) ;
        $this->assertNull( $document->getId()  ) ;
        $this->assertNull( $document->getRev() ) ;
    }

    // =========================================================================
    // get() / has() — generic field access
    // =========================================================================

    public function testGetReturnsFieldValue() :void
    {
        $document = new Document( [ 'name' => 'Marc' , 'age' => 42 ] ) ;

        $this->assertSame( 'Marc' , $document->get( 'name' ) ) ;
        $this->assertSame( 42      , $document->get( 'age'  ) ) ;
    }

    public function testGetReturnsDefaultWhenFieldAbsent() :void
    {
        $document = new Document( [ 'name' => 'Marc' ] ) ;

        $this->assertNull         ( $document->get( 'missing' ) ) ;
        $this->assertSame( 'fallback' , $document->get( 'missing' , 'fallback' ) ) ;
    }

    public function testHasReturnsTrueEvenForNullValue() :void
    {
        $document = new Document( [ 'optional' => null ] ) ;

        $this->assertTrue ( $document->has( 'optional' ) ) ;
        $this->assertFalse( $document->has( 'missing'  ) ) ;
    }

    // =========================================================================
    // isNew()
    // =========================================================================

    public function testIsNewTrueWhenNoUnderscoreKey() :void
    {
        $this->assertTrue( ( new Document() )->isNew() ) ;
        $this->assertTrue( ( new Document( [ 'name' => 'Marc' ] ) )->isNew() ) ;
    }

    public function testIsNewFalseWhenUnderscoreKeyPresent() :void
    {
        $this->assertFalse( ( new Document( [ Schema::_KEY => 'abc' ] ) )->isNew() ) ;
    }
}
