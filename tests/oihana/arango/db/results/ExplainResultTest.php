<?php

namespace tests\oihana\arango\db\results;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\results\ExplainResult;
use oihana\arango\db\results\IndexUse;

class ExplainResultTest extends TestCase
{
    /**
     * A realistic `/_api/explain` fixture (trimmed), mirroring an ArangoDB 3.12
     * response for `FOR u IN users FILTER u.age > @a SORT u.name LIMIT 5 RETURN u`
     * with a persistent index on `age`.
     *
     * @return array<string,mixed>
     */
    private function fixture() : array
    {
        return
        [
            'plan' =>
            [
                'nodes' =>
                [
                    [ 'type' => 'SingletonNode' ] ,
                    [
                        'type'       => 'IndexNode' ,
                        'collection' => 'users' ,
                        'indexes'    =>
                        [
                            [
                                'id'                  => '227866' ,
                                'type'                => 'persistent' ,
                                'name'                => 'idx_age' ,
                                'fields'              => [ 'age' ] ,
                                'selectivityEstimate' => 1 ,
                                'unique'              => false ,
                                'sparse'              => false ,
                            ] ,
                        ] ,
                    ] ,
                    [ 'type' => 'CalculationNode' ] ,
                    [ 'type' => 'SortNode' ] ,
                    [ 'type' => 'LimitNode' ] ,
                    [ 'type' => 'ReturnNode' ] ,
                ] ,
                'rules'               => [ 'use-indexes' , 'remove-filter-covered-by-index' ] ,
                'collections'         => [ [ 'name' => 'users' , 'type' => 'read' ] ] ,
                'estimatedCost'       => 122.76 ,
                'estimatedNrItems'    => 5 ,
                'isModificationQuery' => false ,
            ] ,
            'cacheable' => true ,
            'warnings'  => [] ,
        ] ;
    }

    public function testRules() : void
    {
        $r = new ExplainResult( $this->fixture() );
        $this->assertSame( [ 'use-indexes' , 'remove-filter-covered-by-index' ] , $r->rules() );
    }

    public function testCollections() : void
    {
        $this->assertSame( [ 'users' ] , ( new ExplainResult( $this->fixture() ) )->collections() );
    }

    public function testNodeTypes() : void
    {
        $this->assertSame
        (
            [ 'SingletonNode' , 'IndexNode' , 'CalculationNode' , 'SortNode' , 'LimitNode' , 'ReturnNode' ] ,
            ( new ExplainResult( $this->fixture() ) )->nodeTypes()
        );
    }

    public function testIndexesUsed() : void
    {
        $indexes = ( new ExplainResult( $this->fixture() ) )->indexesUsed();

        $this->assertCount( 1 , $indexes );
        $this->assertInstanceOf( IndexUse::class , $indexes[ 0 ] );

        $ix = $indexes[ 0 ];
        $this->assertSame( 'idx_age' , $ix->name );
        $this->assertSame( 'persistent' , $ix->type );
        $this->assertSame( 'users' , $ix->collection );
        $this->assertSame( [ 'age' ] , $ix->fields );
        $this->assertFalse( $ix->unique );
        $this->assertFalse( $ix->sparse );
        $this->assertSame( 1.0 , $ix->selectivityEstimate );
    }

    public function testUsesIndex() : void
    {
        $this->assertTrue( ( new ExplainResult( $this->fixture() ) )->usesIndex() );
    }

    public function testEstimates() : void
    {
        $r = new ExplainResult( $this->fixture() );
        $this->assertSame( 122.76 , $r->estimatedCost() );
        $this->assertSame( 5 , $r->estimatedNrItems() );
    }

    public function testFlags() : void
    {
        $r = new ExplainResult( $this->fixture() );
        $this->assertTrue( $r->isCacheable() );
        $this->assertFalse( $r->isModificationQuery() );
        $this->assertSame( [] , $r->warnings() );
        $this->assertSame( $this->fixture() , $r->raw() );
        $this->assertSame( $this->fixture()[ 'plan' ] , $r->plan() );
    }

    public function testEmptyResponseDefaults() : void
    {
        $r = new ExplainResult( [] );
        $this->assertSame( [] , $r->plan() );
        $this->assertSame( [] , $r->rules() );
        $this->assertSame( [] , $r->collections() );
        $this->assertSame( [] , $r->nodeTypes() );
        $this->assertSame( [] , $r->indexesUsed() );
        $this->assertFalse( $r->usesIndex() );
        $this->assertSame( 0.0 , $r->estimatedCost() );
        $this->assertSame( 0 , $r->estimatedNrItems() );
        $this->assertFalse( $r->isCacheable() );
        $this->assertFalse( $r->isModificationQuery() );
        $this->assertSame( [] , $r->warnings() );
    }

    public function testIndexUseFromArrayWithoutSelectivity() : void
    {
        $ix = IndexUse::fromArray( [ 'name' => 'primary' , 'type' => 'primary' , 'fields' => [ '_key' ] ] );
        $this->assertSame( 'primary' , $ix->name );
        $this->assertNull( $ix->collection );
        $this->assertNull( $ix->selectivityEstimate );
        $this->assertSame( [ '_key' ] , $ix->fields );
    }
}
