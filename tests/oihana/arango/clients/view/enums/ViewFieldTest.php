<?php

namespace tests\oihana\arango\clients\view\enums ;

use oihana\arango\clients\view\enums\ViewField ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see ViewField} — the catalogue of wire field names
 * exchanged with `/_api/view`.
 */
#[CoversClass( ViewField::class )]
class ViewFieldTest extends TestCase
{
    public function testTopLevelFieldValues() :void
    {
        $this->assertSame( 'name'             , ViewField::NAME               ) ;
        $this->assertSame( 'type'             , ViewField::TYPE               ) ;
        $this->assertSame( 'id'               , ViewField::ID                 ) ;
        $this->assertSame( 'globallyUniqueId' , ViewField::GLOBALLY_UNIQUE_ID ) ;
        $this->assertSame( 'result'           , ViewField::RESULT             ) ;
        $this->assertSame( 'properties'       , ViewField::PROPERTIES         ) ;
    }

    public function testLinkLevelFieldValues() :void
    {
        $this->assertSame( 'analyzers'          , ViewField::ANALYZERS            ) ;
        $this->assertSame( 'fields'             , ViewField::FIELDS               ) ;
        $this->assertSame( 'includeAllFields'   , ViewField::INCLUDE_ALL_FIELDS   ) ;
        $this->assertSame( 'trackListPositions' , ViewField::TRACK_LIST_POSITIONS ) ;
        $this->assertSame( 'storeValues'        , ViewField::STORE_VALUES         ) ;
        $this->assertSame( 'inBackground'       , ViewField::IN_BACKGROUND        ) ;
        $this->assertSame( 'links'              , ViewField::LINKS                ) ;
    }

    public function testArangosearchPropertyFieldValues() :void
    {
        $this->assertSame( 'cleanupIntervalStep'       , ViewField::CLEANUP_INTERVAL_STEP       ) ;
        $this->assertSame( 'consolidationIntervalMsec' , ViewField::CONSOLIDATION_INTERVAL_MSEC ) ;
        $this->assertSame( 'commitIntervalMsec'        , ViewField::COMMIT_INTERVAL_MSEC        ) ;
        $this->assertSame( 'consolidationPolicy'       , ViewField::CONSOLIDATION_POLICY        ) ;
        $this->assertSame( 'writebufferActive'         , ViewField::WRITEBUFFER_ACTIVE          ) ;
        $this->assertSame( 'writebufferIdle'           , ViewField::WRITEBUFFER_IDLE            ) ;
        $this->assertSame( 'writebufferSizeMax'        , ViewField::WRITEBUFFER_SIZE_MAX        ) ;
        $this->assertSame( 'primarySort'               , ViewField::PRIMARY_SORT                ) ;
        $this->assertSame( 'storedValues'              , ViewField::STORED_VALUES               ) ;
    }

    public function testFieldsAreUnique() :void
    {
        $values     = array_values( ViewField::getAll() ) ;
        $duplicates = array_diff_assoc( $values , array_unique( $values ) ) ;

        $this->assertSame
        (
            [] ,
            $duplicates ,
            'ViewField must not contain duplicate wire values: ' . implode( ', ' , $duplicates ) ,
        ) ;
    }
}
