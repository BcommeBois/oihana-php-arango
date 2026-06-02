<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\traits\aql\PositionTrait;

use org\schema\constants\Prop;
use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see PositionTrait}. PositionTrait pulls in ArangoTrait
 * only for the `$collection` property, which is set directly here.
 */
class PositionTraitStub
{
    use PositionTrait ;

    public function __construct()
    {
        $this->collection = 'places' ;
    }
}

/**
 * Characterization coverage for {@see PositionTrait::preparePosition()} — emits a
 * `LET <unique> = ( FOR ... RETURN POSITION(...) )[0]` sub-query when the fields
 * definition carries a `position` entry, otherwise an empty string.
 */
class PositionTraitTest extends TestCase
{
    private function stub() :PositionTraitStub
    {
        return new PositionTraitStub() ;
    }

    public function testBuildsLetPositionSubQuery() :void
    {
        $fields = [ Prop::POSITION => [ AQL::UNIQUE => 'pos_u123' ] ] ;

        $this->assertSame
        (
            'LET pos_u123 = ( FOR doc_coll IN places '
            . 'FILTER POSITION( doc_coll.order , TO_NUMBER( @value ) ) == true '
            . 'RETURN POSITION( doc_coll.order , TO_NUMBER( @value ) , true ) )[0]' ,
            $this->stub()->preparePosition( $fields , 'order' ) ,
        ) ;
    }

    public function testReturnsEmptyWhenNoPositionDefinition() :void
    {
        $this->assertSame( '' , $this->stub()->preparePosition( [] , 'order' ) ) ;
    }

    public function testReturnsEmptyWhenPositionIsNull() :void
    {
        $this->assertSame( '' , $this->stub()->preparePosition( [ Prop::POSITION => null ] , 'order' ) ) ;
    }
}
