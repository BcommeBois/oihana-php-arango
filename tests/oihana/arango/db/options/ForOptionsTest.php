<?php

namespace tests\oihana\arango\db\options;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\options\ForOptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\operations\aqlFor;

/**
 * The options of a `FOR` loop.
 */
#[CoversClass( ForOptions::class )]
final class ForOptionsTest extends TestCase
{
    public function testAnIndexHintMayBeOneName() :void
    {
        $this->assertSame( 'byOwner' , new ForOptions( [ ForOptions::INDEX_HINT => 'byOwner' ] )->indexHint ) ;
    }

    /**
     * 🚨 A list of names, in order of preference, is what the server reads too — the
     * property typed as a string turned it into a `TypeError`.
     */
    public function testAnIndexHintMayBeAListOfNames() :void
    {
        $options = new ForOptions( [ ForOptions::INDEX_HINT => [ 'byOwner' , 'byDue' ] , ForOptions::FORCE_INDEX_HINT => true ] ) ;

        $this->assertSame( [ 'byOwner' , 'byDue' ] , $options->indexHint ) ;
        $this->assertSame
        (
            'FOR doc IN tasks OPTIONS {"forceIndexHint":true,"indexHint":["byOwner","byDue"]}' ,
            aqlFor( [ AQL::IN => 'tasks' , AQL::OPTIONS => [ ForOptions::INDEX_HINT => [ 'byOwner' , 'byDue' ] , ForOptions::FORCE_INDEX_HINT => true ] ] )
        ) ;
    }
}
