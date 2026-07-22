<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\traits\MetaOnlyTrait;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

class MetaOnlyHost
{
    use MetaOnlyTrait ;
}

/**
 * Unit coverage for {@see MetaOnlyTrait}: holds and initializes the durable
 * `metaOnly` default of a controller.
 */
class MetaOnlyTraitTest extends TestCase
{
    public function testDefaultIsFalse() :void
    {
        $this->assertFalse( new MetaOnlyHost()->metaOnly ) ;
    }

    public function testInitializeReturnsStaticForChaining() :void
    {
        $host = new MetaOnlyHost() ;
        $this->assertSame( $host , $host->initializeMetaOnly( [] ) ) ;
    }

    public function testInitializeFromBooleanTrue() :void
    {
        $host = new MetaOnlyHost()->initializeMetaOnly( [ Arango::META_ONLY => true ] ) ;
        $this->assertTrue( $host->metaOnly ) ;
    }

    public function testInitializeFromTruthyStringForms() :void
    {
        foreach ( [ 'true' , '1' , 'yes' , 'on' ] as $truthy )
        {
            $host = new MetaOnlyHost()->initializeMetaOnly( [ Arango::META_ONLY => $truthy ] ) ;
            $this->assertTrue( $host->metaOnly , "value: $truthy" ) ;
        }
    }

    public function testInitializeFromFalsyValue() :void
    {
        $host = new MetaOnlyHost() ;
        $host->metaOnly = true ;
        $host->initializeMetaOnly( [ Arango::META_ONLY => 'false' ] ) ;
        $this->assertFalse( $host->metaOnly ) ;
    }

    public function testInitializeWithoutKeyKeepsCurrentValue() :void
    {
        $host = new MetaOnlyHost() ;
        $host->metaOnly = true ;
        $host->initializeMetaOnly( [] ) ; // key absent → the current value is preserved
        $this->assertTrue( $host->metaOnly ) ;
    }
}
