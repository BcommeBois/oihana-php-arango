<?php

namespace tests\oihana\arango\clients\cursor\enums ;

use oihana\arango\clients\cursor\enums\CursorField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see CursorField} — JSON field names exchanged through the
 * ArangoDB AQL cursor API. Values must match the canonical server
 * spelling exactly.
 */
#[CoversClass( CursorField::class )]
class CursorFieldTest extends TestCase
{
    public function testCanonicalFieldNames() :void
    {
        $this->assertSame( 'batchSize'   , CursorField::BATCH_SIZE   ) ;
        $this->assertSame( 'bindVars'    , CursorField::BIND_VARS    ) ;
        $this->assertSame( 'cache'       , CursorField::CACHE        ) ;
        $this->assertSame( 'count'       , CursorField::COUNT        ) ;
        $this->assertSame( 'extra'       , CursorField::EXTRA        ) ;
        $this->assertSame( 'fullCount'   , CursorField::FULL_COUNT   ) ;
        $this->assertSame( 'hasMore'     , CursorField::HAS_MORE     ) ;
        $this->assertSame( 'id'          , CursorField::ID           ) ;
        $this->assertSame( 'maxRuntime'  , CursorField::MAX_RUNTIME  ) ;
        $this->assertSame( 'memoryLimit' , CursorField::MEMORY_LIMIT ) ;
        $this->assertSame( 'options'     , CursorField::OPTIONS      ) ;
        $this->assertSame( 'query'       , CursorField::QUERY        ) ;
        $this->assertSame( 'result'      , CursorField::RESULT       ) ;
        $this->assertSame( 'stats'       , CursorField::STATS        ) ;
        $this->assertSame( 'ttl'         , CursorField::TTL          ) ;
    }

    public function testIncludesRecognisesAllFields() :void
    {
        $fields =
        [
            'batchSize' , 'bindVars'  , 'cache'   , 'count'     , 'extra'  ,
            'fullCount' , 'hasMore'   , 'id'      , 'maxRuntime', 'memoryLimit' ,
            'options'   , 'query'     , 'result'  , 'stats'     , 'ttl'    ,
        ] ;

        foreach ( $fields as $field )
        {
            $this->assertTrue( CursorField::includes( $field ) , "Expected '$field' to be recognised by CursorField" ) ;
        }
        $this->assertFalse( CursorField::includes( 'unknown' ) ) ;
    }

    public function testRootOptionsListsThe5RootCursorOptions() :void
    {
        $this->assertSame
        (
            [ 'count' , 'batchSize' , 'cache' , 'memoryLimit' , 'ttl' ] ,
            CursorField::ROOT_OPTIONS ,
        ) ;
    }

    public function testRootOptionsDoesNotIncludeQueryOrBindVars() :void
    {
        $this->assertNotContains( CursorField::QUERY     , CursorField::ROOT_OPTIONS ) ;
        $this->assertNotContains( CursorField::BIND_VARS , CursorField::ROOT_OPTIONS ) ;
    }

    public function testRootOptionsDoesNotIncludeNestedCursorOptions() :void
    {
        $this->assertNotContains( CursorField::FULL_COUNT  , CursorField::ROOT_OPTIONS ) ;
        $this->assertNotContains( CursorField::MAX_RUNTIME , CursorField::ROOT_OPTIONS ) ;
    }
}
