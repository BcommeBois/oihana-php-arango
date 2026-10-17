<?php

namespace tests\oihana\arango\db\options;

use oihana\arango\db\options\UpsertOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The options of an `UPSERT`.
 */
#[CoversClass( UpsertOptions::class )]
final class UpsertOptionsTest extends TestCase
{
    public function testAnIndexHintMayBeOneNameOrAListOfNames() :void
    {
        $this->assertSame( 'byKey' , new UpsertOptions( [ UpsertOptions::INDEX_HINT => 'byKey' ] )->indexHint ) ;
        $this->assertSame( [ 'byKey' , 'byId' ] , new UpsertOptions( [ UpsertOptions::INDEX_HINT => [ 'byKey' , 'byId' ] ] )->indexHint ) ;
    }
}
