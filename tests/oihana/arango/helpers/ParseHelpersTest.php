<?php

namespace tests\oihana\arango\helpers;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;

use function oihana\arango\helpers\parseCollection;
use function oihana\arango\helpers\parseIdentifier;
use function oihana\arango\helpers\parseKey;

/**
 * Unit coverage for the three ArangoDB document handle parsers
 * (`parseIdentifier`, `parseKey`, `parseCollection`) — PHP mirrors
 * of the AQL functions `PARSE_IDENTIFIER`, `PARSE_KEY` and
 * `PARSE_COLLECTION`.
 */
class ParseHelpersTest extends TestCase
{
    // -------------------------------------------------------------------------
    // parseIdentifier
    // -------------------------------------------------------------------------

    public function testParseIdentifierSplitsCollectionAndKey() :void
    {
        $result = parseIdentifier( 'users/abc123' ) ;

        $this->assertSame
        (
            [ Arango::COLLECTION => 'users' , Arango::KEY => 'abc123' ] ,
            $result
        ) ;
    }

    public function testParseIdentifierWithoutSlashReturnsKeyOnly() :void
    {
        $result = parseIdentifier( 'just-a-key' ) ;

        $this->assertSame
        (
            [ Arango::COLLECTION => null , Arango::KEY => 'just-a-key' ] ,
            $result
        ) ;
    }

    public function testParseIdentifierReturnsNullOnNullOrEmpty() :void
    {
        $this->assertNull( parseIdentifier( null ) ) ;
        $this->assertNull( parseIdentifier( '' ) ) ;
    }

    // -------------------------------------------------------------------------
    // parseKey
    // -------------------------------------------------------------------------

    public function testParseKeyReturnsKeyPortion() :void
    {
        $this->assertSame( 'abc123' , parseKey( 'users/abc123' ) ) ;
        $this->assertSame( '42' , parseKey( 'roles/42' ) ) ;
    }

    public function testParseKeyPassesThroughWhenNoSlash() :void
    {
        $this->assertSame( 'just-a-key' , parseKey( 'just-a-key' ) ) ;
    }

    public function testParseKeyReturnsNullOnNullOrEmpty() :void
    {
        $this->assertNull( parseKey( null ) ) ;
        $this->assertNull( parseKey( '' ) ) ;
    }

    // -------------------------------------------------------------------------
    // parseCollection
    // -------------------------------------------------------------------------

    public function testParseCollectionReturnsCollectionName() :void
    {
        $this->assertSame( 'users' , parseCollection( 'users/abc123' ) ) ;
        $this->assertSame( 'roles' , parseCollection( 'roles/42' ) ) ;
    }

    public function testParseCollectionReturnsNullWithoutSlash() :void
    {
        // A bare `_key` is not a valid handle — there's nothing to extract.
        $this->assertNull( parseCollection( 'just-a-key' ) ) ;
    }

    public function testParseCollectionReturnsNullOnNullOrEmpty() :void
    {
        $this->assertNull( parseCollection( null ) ) ;
        $this->assertNull( parseCollection( '' ) ) ;
    }
}
