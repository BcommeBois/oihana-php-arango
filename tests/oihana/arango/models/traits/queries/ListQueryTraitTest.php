<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\ListQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see ListQueryTrait}. It composes FieldsTrait → ArangoTrait
 * (which declares `$collection`), so the property is set in the constructor.
 */
class ListQueryTraitStub
{
    use ListQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }
}

/**
 * Characterization coverage for {@see ListQueryTrait::buildListQuery()} — the full
 * `FOR ... [FILTER ...] [SORT ...] [LIMIT ...] RETURN { fields }` listing query,
 * orchestrating active/facets/filter/search/sort/limit/fields.
 */
class ListQueryTraitTest extends TestCase
{
    private function stub() :ListQueryTraitStub
    {
        return new ListQueryTraitStub() ;
    }

    public function testEmptyInitListsEverything() :void
    {
        $binds = [] ;
        $this->assertSame( 'FOR doc IN @@collection RETURN doc' , $this->stub()->buildListQuery( [] , $binds ) ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testLimitOffsetAndSort() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection SORT doc.name ASC, doc.age DESC LIMIT 5, 10 RETURN doc' ,
            $this->stub()->buildListQuery( [ Arango::LIMIT => 10 , Arango::OFFSET => 5 , 'sort' => 'name,-age' ] , $binds ) ,
        ) ;
    }

    public function testActiveAndExtraConditions() :void
    {
        $stub = $this->stub() ;
        $stub->activable = true ;

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.active == @active && doc.y==2 RETURN doc' ,
            $stub->buildListQuery( [ Arango::ACTIVE => false , Arango::CONDITIONS => [ 'doc.y==2' ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'users' , 'active' => 0 ] , $binds ) ;
    }

    public function testDebugFlagDoesNotAlterTheQuery() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection RETURN doc' ,
            $this->stub()->buildListQuery( [ Arango::DEBUG => true ] , $binds ) ,
        ) ;
    }

    public function testInstanceConditionsAreAppliedAsFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.k==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.k==1 RETURN doc' ,
            $stub->buildListQuery( [] , $binds ) ,
        ) ;
    }
}
