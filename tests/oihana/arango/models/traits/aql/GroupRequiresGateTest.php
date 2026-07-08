<?php

namespace tests\oihana\arango\models\traits\aql;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\Group;
use oihana\arango\models\traits\aql\GroupTrait;

use function oihana\arango\db\operations\aqlCollect;

/**
 * Self-contained host exposing {@see GroupTrait} with a projection map.
 */
class GroupGateStub
{
    use GroupTrait ;

    public ?array $fields = null ;
}

/**
 * Permission gate on `?groupBy=` (lot 3): a dimension or an aggregate on a field
 * hidden from the projection (`Field::REQUIRES`) is dropped — grouping by it would
 * leak its distinct values, aggregating it would leak a bound (no group-by oracle).
 */
class GroupRequiresGateTest extends TestCase
{
    private function stub(): GroupGateStub
    {
        $stub = new GroupGateStub() ;
        $stub->groupable = [ 'salary' => 'salary' ] ;
        $stub->fields    = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        return $stub ;
    }

    // ---------------------------------------------------------------- dimension gate

    public function testRefusedDimensionIsDropped(): void
    {
        $spec = $this->stub()->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'salary' ] ,
            Arango::AUTHORIZER => fn() => false ,
        ]) ;

        // salary dropped → no ASSIGN → empty COLLECT.
        $this->assertSame( '' , aqlCollect( $spec ) ) ;
    }

    public function testGrantedDimensionIsGrouped(): void
    {
        $spec = $this->stub()->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'salary' ] ,
            Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ,
        ]) ;

        $this->assertSame( 'COLLECT salary = doc.salary' , aqlCollect( $spec ) ) ;
    }

    public function testUngatedDimensionIsUnaffected(): void
    {
        $stub = new GroupGateStub() ;
        $stub->groupable = [ 'category' => 'category' ] ;
        $stub->fields    = [ 'category' => true ] ; // no REQUIRES

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'category' ] ,
            Arango::AUTHORIZER => fn() => false ,
        ]) ;

        $this->assertSame( 'COLLECT category = doc.category' , aqlCollect( $spec ) ) ;
    }

    public function testGatedDimensionFailsOpenWithoutAuthorizer(): void
    {
        $spec = $this->stub()->prepareCollect( [ Arango::GROUP => [ Group::BY => 'salary' ] ] ) ;

        $this->assertSame( 'COLLECT salary = doc.salary' , aqlCollect( $spec ) ) ;
    }

    // ---------------------------------------------------------------- aggregate gate

    public function testRefusedAggregateIsDropped(): void
    {
        $stub = new GroupGateStub() ;
        $stub->groupable = [ 'category' => 'category' ] ;
        $stub->fields    = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'category' , Group::AGG => [ 'top' => 'max:salary' ] ] ,
            Arango::AUTHORIZER => fn() => false ,
        ]) ;

        // The MAX(salary) bound is not leaked — only the grouping dimension remains.
        $this->assertSame( 'COLLECT category = doc.category' , aqlCollect( $spec ) ) ;
    }

    public function testGrantedAggregateIsKept(): void
    {
        $stub = new GroupGateStub() ;
        $stub->groupable = [ 'category' => 'category' ] ;
        $stub->fields    = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $spec = $stub->prepareCollect(
        [
            Arango::GROUP      => [ Group::BY => 'category' , Group::AGG => [ 'top' => 'max:salary' ] ] ,
            Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ,
        ]) ;

        $this->assertSame( 'COLLECT category = doc.category AGGREGATE top = MAX(doc.salary)' , aqlCollect( $spec ) ) ;
    }

    // ---------------------------------------------------------------- group-sort guardrail

    public function testGroupSortKeepsOnlyEmittedVariables(): void
    {
        $init = [ Arango::GROUP => [ Group::SORT => 'category,-secret' ] ] ;

        // 'secret' is not an emitted variable → dropped; 'category' kept.
        $this->assertSame( 'category ASC' , $this->stub()->prepareGroupSort( $init , [ 'category' ] ) ) ;
    }

    public function testGroupSortWithoutAvailableVarsKeepsEverything(): void
    {
        // Backward-compatible: no available-vars list → no filtering.
        $init = [ Arango::GROUP => [ Group::SORT => 'category,-total' ] ] ;

        $this->assertSame( 'category ASC, total DESC' , $this->stub()->prepareGroupSort( $init ) ) ;
    }
}
