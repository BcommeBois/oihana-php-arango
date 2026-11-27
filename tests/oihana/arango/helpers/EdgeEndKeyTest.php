<?php

namespace tests\oihana\arango\helpers;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

use org\schema\constants\Schema;

use stdClass;

use function oihana\arango\helpers\edgeEndKey;

/**
 * Unit coverage for {@see edgeEndKey()} : the `_key` of one end of an edge.
 */
#[CoversFunction( 'oihana\arango\helpers\edgeEndKey' )]
class EdgeEndKeyTest extends TestCase
{
    public function testAMissingOrNonStringEndIsNull() :void
    {
        $this->assertNull( edgeEndKey( [ Schema::_TO => 'policies/p1' ] , Schema::_FROM ) ) ;
        $this->assertNull( edgeEndKey( [ Schema::_FROM => 42 ]           , Schema::_FROM ) ) ;
        $this->assertNull( edgeEndKey( [ Schema::_FROM => '' ]           , Schema::_FROM ) ) ;
    }

    public function testAnythingElseThanAnObjectOrAnArrayIsNull() :void
    {
        $this->assertNull( edgeEndKey( null          , Schema::_FROM ) ) ;
        $this->assertNull( edgeEndKey( 'roles/42'    , Schema::_FROM ) ) ;
    }

    public function testBothEndsOfAnArrayEdgeAreRead() :void
    {
        $edge = [ Schema::_FROM => 'roles/42' , Schema::_TO => 'permissions/7' ] ;

        $this->assertSame( '42' , edgeEndKey( $edge , Schema::_FROM ) ) ;
        $this->assertSame( '7'  , edgeEndKey( $edge , Schema::_TO   ) ) ;
    }

    public function testBothEndsOfAnObjectEdgeAreRead() :void
    {
        $edge        = new stdClass() ;
        $edge->_from = 'services/s1' ;
        $edge->_to   = 'policies/p1' ;

        $this->assertSame( 's1' , edgeEndKey( $edge , Schema::_FROM ) ) ;
        $this->assertSame( 'p1' , edgeEndKey( $edge , Schema::_TO   ) ) ;
    }

    public function testABareKeyPassesThrough() :void
    {
        $this->assertSame( 'p1' , edgeEndKey( [ Schema::_TO => 'p1' ] , Schema::_TO ) ) ;
    }
}
