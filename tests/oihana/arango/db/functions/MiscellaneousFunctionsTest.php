<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\functions\checkDocument;
use function oihana\arango\db\functions\collectionCount;
use function oihana\arango\db\functions\currentDatabase;
use function oihana\arango\db\functions\currentUser;
use function oihana\arango\db\functions\decodeRev;
use function oihana\arango\db\functions\document;
use function oihana\arango\db\functions\firstDocument;
use function oihana\arango\db\functions\firstList;
use function oihana\arango\db\functions\length;
use function oihana\arango\db\functions\notNull;
use function oihana\core\strings\betweenBrackets;
use function oihana\core\strings\betweenDoubleQuotes;

class MiscellaneousFunctionsTest extends TestCase
{
    public function testCheckDocument():void
    {
        $this->assertEquals("CHECK_DOCUMENT(doc)", checkDocument('doc' ) );
    }

    public function testCollectionCount():void
    {
        $this->assertEquals("COLLECTION_COUNT(coll)", collectionCount('coll' ) );
    }

    public function testCurrentDatabase():void
    {
        $this->assertEquals("CURRENT_DATABASE()", currentDatabase() );
    }

    public function testCurrentUser():void
    {
        $this->assertEquals("CURRENT_USER()", currentUser() );
    }

    public function testDecodeRev():void
    {
        $this->assertEquals('DECODE_REV("_YU0HOEG---")', decodeRev('"_YU0HOEG---"' ) );
    }

    public function testDocument():void
    {
        $this->assertEquals('DOCUMENT(persons,"Alice")', document('persons','"Alice"') );
    }

    public function testFirstDocument():void
    {
        $this->assertEquals("FIRST_DOCUMENT(doc)", firstDocument('doc' ) );
        $this->assertEquals('FIRST_DOCUMENT("hello")', firstDocument( betweenDoubleQuotes('hello') ) );
        $this->assertEquals('FIRST_DOCUMENT(null,"hello",doc)', firstDocument( 'null', betweenDoubleQuotes('hello') , 'doc' ) );
    }

    public function testFirstList():void
    {
        $this->assertEquals("FIRST_LIST(doc)", firstList('doc' ) );
        $this->assertEquals('FIRST_LIST("hello")', firstList( betweenDoubleQuotes('hello') ) );
        $this->assertEquals('FIRST_LIST(null,["hello"],doc)', firstList( 'null', betweenBrackets(betweenDoubleQuotes('hello')) , 'doc' ) );
    }

    public function testLength():void
    {
        $this->assertEquals("LENGTH(coll)", length('coll' ) );
    }

    public function testNotNull(): void
    {
        $this->assertEquals("NOT_NULL(doc)", notNull('doc' ) );
        $this->assertEquals('NOT_NULL("hello")', notNull( betweenDoubleQuotes('hello') ) );
        $this->assertEquals('NOT_NULL(null,"hello",doc)', notNull( 'null', betweenDoubleQuotes('hello') , 'doc' ) );
    }
}
