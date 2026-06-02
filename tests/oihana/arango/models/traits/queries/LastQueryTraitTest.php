<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\enums\Arango;
use oihana\arango\models\traits\queries\LastQueryTrait;

use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see LastQueryTrait}. It composes FieldsTrait → ArangoTrait
 * (which declares `$collection`), so the property is set in the constructor.
 */
class LastQueryTraitStub
{
    use LastQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }
}

/**
 * Characterization coverage for {@see LastQueryTrait::buildLastQuery()} — the
 * `FOR ... [FILTER conditions] SORT doc.<property> DESC LIMIT 1 RETURN { fields }`
 * latest-document lookup, defaulting to sorting on `modified`.
 */
class LastQueryTraitTest extends TestCase
{
    private function stub() :LastQueryTraitStub
    {
        return new LastQueryTraitStub() ;
    }

    public function testDefaultSortsOnModifiedDescendingLimitOne() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection SORT doc.modified DESC LIMIT 1 RETURN doc' ,
            $this->stub()->buildLastQuery( [] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'users' ] , $binds ) ;
    }

    public function testCustomSortProperty() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection SORT doc.created DESC LIMIT 1 RETURN doc' ,
            $this->stub()->buildLastQuery( [ Arango::PROPERTY => 'created' ] , $binds ) ,
        ) ;
    }

    public function testDebugFlagDoesNotAlterTheQuery() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection SORT doc.modified DESC LIMIT 1 RETURN doc' ,
            $this->stub()->buildLastQuery( [ Arango::DEBUG => true ] , $binds ) ,
        ) ;
    }

    public function testInstanceConditionsAreAppliedAsFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.x == 1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'FOR doc IN @@collection FILTER doc.x == 1 SORT doc.modified DESC LIMIT 1 RETURN doc' ,
            $stub->buildLastQuery( [] , $binds ) ,
        ) ;
    }
}
