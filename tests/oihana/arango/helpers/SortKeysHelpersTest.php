<?php

namespace tests\oihana\arango\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\helpers\ascKey;
use function oihana\arango\helpers\descKey;
use function oihana\arango\helpers\sortKeys;

/**
 * Unit coverage for the three textual sort-grammar helpers
 * (`ascKey`, `descKey`, `sortKeys`) — the input grammar consumed by
 * `SortTrait::prepareSort` and the HTTP `?sort=` query parameter.
 */
class SortKeysHelpersTest extends TestCase
{
    // -------------------------------------------------------------------------
    // ascKey
    // -------------------------------------------------------------------------

    public function testAscKeyReturnsKeyAsIs() :void
    {
        $this->assertSame( 'name'    , ascKey( 'name' ) ) ;
        $this->assertSame( 'created' , ascKey( 'created' ) ) ;
        $this->assertSame( '_key'    , ascKey( '_key' ) ) ;
    }

    public function testAscKeyAcceptsEmptyString() :void
    {
        $this->assertSame( '' , ascKey( '' ) ) ;
    }

    // -------------------------------------------------------------------------
    // descKey
    // -------------------------------------------------------------------------

    public function testDescKeyPrependsHyphen() :void
    {
        $this->assertSame( '-name'    , descKey( 'name' ) ) ;
        $this->assertSame( '-created' , descKey( 'created' ) ) ;
        $this->assertSame( '-_key'    , descKey( '_key' ) ) ;
    }

    public function testDescKeyOnEmptyStringProducesLoneHyphen() :void
    {
        // Degenerate case — callers should never pass an empty key.
        $this->assertSame( '-' , descKey( '' ) ) ;
    }

    // -------------------------------------------------------------------------
    // sortKeys
    // -------------------------------------------------------------------------

    public function testSortKeysJoinsTokensWithComma() :void
    {
        $this->assertSame( '-created'      , sortKeys( descKey( 'created' ) ) ) ;
        $this->assertSame( '-created,name' , sortKeys( descKey( 'created' ) , 'name' ) ) ;
        $this->assertSame
        (
            '-created,-name' ,
            sortKeys( descKey( 'created' ) , descKey( 'name' ) )
        ) ;
    }

    public function testSortKeysAcceptsPlainAscKey() :void
    {
        $this->assertSame
        (
            'name,-created' ,
            sortKeys( ascKey( 'name' ) , descKey( 'created' ) )
        ) ;
    }

    public function testSortKeysReturnsEmptyStringWhenNoArguments() :void
    {
        $this->assertSame( '' , sortKeys() ) ;
    }

    public function testSortKeysSkipsEmptyTokens() :void
    {
        // `compile()` cleans empty entries, so conditional tokens passed
        // as '' are silently dropped — convenient for caller branches.
        $this->assertSame( '-created'      , sortKeys( '' , descKey( 'created' ) , '' ) ) ;
        $this->assertSame( '-created,name' , sortKeys( descKey( 'created' ) , '' , 'name' ) ) ;
    }
}
