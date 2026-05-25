<?php

namespace tests\oihana\arango\clients\graph ;

use oihana\arango\clients\graph\EdgeDefinition ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Tests for {@see EdgeDefinition} — value object describing the
 * collection / from / to of one edge definition inside a named graph.
 */
#[CoversClass( EdgeDefinition::class )]
class EdgeDefinitionTest extends TestCase
{
    public function testConstructionExposesAllFields() :void
    {
        $def = new EdgeDefinition( 'employs' , [ 'companies' ] , [ 'people' ] ) ;

        $this->assertSame( 'employs'      , $def->collection ) ;
        $this->assertSame( [ 'companies' ] , $def->from       ) ;
        $this->assertSame( [ 'people' ]    , $def->to         ) ;
    }

    public function testToArrayMatchesWireShape() :void
    {
        $def = new EdgeDefinition( 'reports_to' , [ 'people' ] , [ 'people' ] ) ;

        $this->assertSame
        (
            [
                'collection' => 'reports_to' ,
                'from'       => [ 'people' ] ,
                'to'         => [ 'people' ] ,
            ] ,
            $def->toArray() ,
        ) ;
    }

    public function testFromArrayParsesFullPayload() :void
    {
        $def = EdgeDefinition::fromArray
        ([
            'collection' => 'employs' ,
            'from'       => [ 'companies' , 'startups' ] ,
            'to'         => [ 'people' ] ,
        ]) ;

        $this->assertSame( 'employs'                       , $def->collection ) ;
        $this->assertSame( [ 'companies' , 'startups' ]    , $def->from       ) ;
        $this->assertSame( [ 'people' ]                    , $def->to         ) ;
    }

    public function testFromArrayDefaultsToEmptyListsOnMissingFields() :void
    {
        $def = EdgeDefinition::fromArray( [ 'collection' => 'orphan' ] ) ;

        $this->assertSame( 'orphan' , $def->collection ) ;
        $this->assertSame( []       , $def->from       ) ;
        $this->assertSame( []       , $def->to         ) ;
    }

    public function testFromArrayFiltersNonStringEntriesFromFromAndTo() :void
    {
        $def = EdgeDefinition::fromArray
        ([
            'collection' => 'employs' ,
            'from'       => [ 'companies' , 42 , null , 'startups' ] ,
            'to'         => 'not-an-array' ,
        ]) ;

        $this->assertSame( [ 'companies' , 'startups' ] , $def->from ) ;
        $this->assertSame( []                            , $def->to   ) ;
    }

    public function testFromArrayCollectionFieldFallsBackToEmptyStringWhenMissing() :void
    {
        $def = EdgeDefinition::fromArray( [] ) ;

        $this->assertSame( '' , $def->collection ) ;
    }
}
