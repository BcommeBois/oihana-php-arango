<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\enums\Boolean;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

/**
 * Permission gate on `?filter=` (lot 1): a field hidden from the projection
 * (`Field::REQUIRES`) stays unfilterable — the refused predicate is neutralised
 * to `false` (never dropped, which would loosen an AND), and a relation locked at
 * its definition (`AQL::REQUIRES`) cannot be filtered through.
 */
class FilterRequiresGateTest extends TestCase
{
    private Container $container;
    private array $binds;

    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function model(): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'people' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'name' => FilterType::STRING , 'salary' => FilterType::NUMBER ] ,
            AQL::FIELDS     => [ 'name' => true , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ,
        ]);
    }

    private function filter( array $node , callable $authorizer ): array
    {
        return [ Arango::FILTER => $node , Arango::AUTHORIZER => $authorizer ] ;
    }

    // ---------------------------------------------------------------- Gate A (scalar keys)

    public function testRefusedScalarFilterIsNeutralisedToFalse(): void
    {
        $init   = $this->filter( [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        // Neutralised, not dropped — and no value bound (the predicate never built).
        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 1000 , $this->binds ) ;
    }

    public function testGrantedScalarFilterProducesThePredicate(): void
    {
        $init   = $this->filter( [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] , fn( string $s ) => $s === 'hr:read' ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.salary' , $result ) ;
        $this->assertContains( 1000 , $this->binds ) ;
    }

    public function testFilterWithoutRequiresIsUnaffected(): void
    {
        // `name` carries no Field::REQUIRES → filters freely, even under a denying authorizer.
        $init   = $this->filter( [ 'key' => 'name' , 'val' => 'Bob' ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'doc.name' , $result ) ;
    }

    public function testGatedFilterFailsOpenWithoutAuthorizer(): void
    {
        // A gated field with no authorizer injected filters (fail-open — field-level semantics).
        $result = $this->model()->prepareFilter( [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] , $this->binds ) ;

        $this->assertStringContainsString( 'doc.salary' , $result ) ;
    }

    // ---------------------------------------------------------------- Composition in and/or/not

    public function testRefusedFilterEmptiesAnAndBranch(): void
    {
        // (false && doc.name ...) → the AND branch is fail-closed, no salary leak.
        $init   = $this->filter( [ 'and' , [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] , [ 'key' => 'name' , 'val' => 'Bob' ] ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( Boolean::FALSE , $result ) ;
        $this->assertStringContainsString( 'doc.name' , $result ) ;
        $this->assertNotContains( 1000 , $this->binds ) ;
    }

    public function testRefusedFilterIsNeutralInAnOrBranch(): void
    {
        // (false || doc.name ...) → the refused term contributes nothing, no leak.
        $init   = $this->filter( [ 'or' , [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] , [ 'key' => 'name' , 'val' => 'Bob' ] ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( Boolean::FALSE , $result ) ;
        $this->assertStringContainsString( 'doc.name' , $result ) ;
    }

    public function testRefusedFilterUnderNotDoesNotLeak(): void
    {
        // NOT(false) → true : the negated refused term excludes nothing (no oracle).
        $init   = $this->filter( [ 'not' , [ 'key' => 'salary' , 'op' => 'gt' , 'val' => 1000 ] ] , fn() => false ) ;
        $result = $this->model()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 1000 , $this->binds ) ;
    }

    // ---------------------------------------------------------------- Gate C (relations)

    private function relationModel(): Documents
    {
        $this->container->set( 'EmployeeEdge' , new MockEdges( 'employee_edges' ) ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'employee' => [ AQL::TYPE => Filter::EDGES , AQL::FILTERS => [ 'name' => FilterType::STRING ] ] ,
            ],
            AQL::EDGES =>
            [
                // The relation is locked at its definition.
                'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::OUTBOUND , AQL::REQUIRES => 'hr:read' ] ,
            ],
        ]);
    }

    public function testRefusedRelationFilterIsNeutralisedToFalse(): void
    {
        $init   = $this->filter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , fn() => false ) ;
        $result = $this->relationModel()->prepareFilter( $init , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 'Bob' , $this->binds ) ;
    }

    public function testGrantedRelationFilterTraverses(): void
    {
        $init   = $this->filter( [ 'key' => 'employee[*].name' , 'val' => 'Bob' ] , fn( string $s ) => $s === 'hr:read' ) ;
        $result = $this->relationModel()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertContains( 'Bob' , $this->binds ) ;
    }

    private function joinModel(): Documents
    {
        $company = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'name' => FilterType::STRING ] ,
        ]);
        $this->container->set( 'CompanyModel' , $company ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'company' => [ AQL::TYPE => Filter::JOIN , AQL::FILTERS => [ 'name' => FilterType::STRING ] ] ,
            ],
            AQL::JOINS =>
            [
                // The join is locked at its definition.
                'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' , AQL::REQUIRES => 'org:read' ] ,
            ],
        ]);
    }

    public function testRefusedJoinFilterIsNeutralisedToFalse(): void
    {
        $init   = $this->filter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , fn() => false ) ;
        $result = $this->joinModel()->prepareFilter( $init , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 'Acme' , $this->binds ) ;
    }

    public function testGrantedJoinFilterTraverses(): void
    {
        $init   = $this->filter( [ 'key' => 'company.name' , 'val' => 'Acme' ] , fn( string $s ) => $s === 'org:read' ) ;
        $result = $this->joinModel()->prepareFilter( $init , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertContains( 'Acme' , $this->binds ) ;
    }
}
