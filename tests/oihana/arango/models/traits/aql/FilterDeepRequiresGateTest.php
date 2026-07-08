<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
 * Permission gate on `?filter=` deep leaves (lot B1): a leaf hidden from the
 * projection is gated at the EXACT sub-field — through a nested document
 * (`address.city`, same model) or across an edge / join (`employee[*].salary`,
 * the target model's projection). A refused leaf neutralises the WHOLE predicate
 * / traversal to `false` (Option B), robust to the `all` / `none` quantifier.
 */
class FilterDeepRequiresGateTest extends TestCase
{
    private Container $container;
    private array $binds;

    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
    }

    private function filter( array $node , ?callable $authorizer = null ): array
    {
        $init = [ Arango::FILTER => $node ] ;
        if ( $authorizer !== null )
        {
            $init[ Arango::AUTHORIZER ] = $authorizer ;
        }
        return $init ;
    }

    // ---------------------------------------------------------------- nested document (same model)

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function docModel( array $fields ): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'address' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'city' => FilterType::STRING ] ] ] ,
            AQL::FIELDS     => $fields ,
        ]);
    }

    public function testRefusedDeepDocumentLeafIsNeutralised(): void
    {
        // `address` is unlocked, only the sub-field `city` carries a REQUIRES — the
        // old root-only gate would have leaked it.
        $model  = $this->docModel( [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'address.city' , 'val' => 'Paris' ] , fn() => false ) , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 'Paris' , $this->binds ) ;
    }

    public function testGrantedDeepDocumentLeafProducesThePredicate(): void
    {
        $model  = $this->docModel( [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'address.city' , 'val' => 'Paris' ] , fn( string $s ) => $s === 'geo:read' ) , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.city' , $result ) ;
        $this->assertContains( 'Paris' , $this->binds ) ;
    }

    public function testDeepDocumentLeafFailsOpenWithoutAuthorizer(): void
    {
        $model  = $this->docModel( [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'address.city' , 'val' => 'Paris' ] ) , $this->binds ) ;

        $this->assertStringContainsString( 'doc.address.city' , $result ) ;
    }

    // ---------------------------------------------------------------- edge leaf (target model)

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function edgeModel( array $employeeFields ): Documents
    {
        $employee = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'employees' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'salary' => FilterType::NUMBER ] ,
            AQL::FIELDS     => $employeeFields ,
        ]);

        $edge = new MockEdges( 'employee_edges' ) ;
        $edge->to = $employee ; // the OUTBOUND target model carries the leaf projection
        $this->container->set( 'EmployeeEdge' , $edge ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'organizations' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'employee' => [ AQL::TYPE => Filter::EDGES , AQL::FILTERS => [ 'salary' => FilterType::NUMBER ] ] ] ,
            AQL::EDGES      => [ 'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ] ,
        ]);
    }

    public function testRefusedEdgeLeafNeutralisesTheWholeTraversal(): void
    {
        $model  = $this->edgeModel( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'employee[*].salary' , 'op' => 'gt' , 'val' => 1000 ] , fn() => false ) , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 1000 , $this->binds ) ;
    }

    public function testGrantedEdgeLeafTraverses(): void
    {
        $model  = $this->edgeModel( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'employee[*].salary' , 'op' => 'gt' , 'val' => 1000 ] , fn( string $s ) => $s === 'hr:read' ) , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertStringContainsString( 'OUTBOUND doc' , $result ) ;
        $this->assertContains( 1000 , $this->binds ) ;
    }

    public function testRefusedEdgeLeafUnderAllQuantifierDoesNotBecomeAnExistenceOracle(): void
    {
        // The security crux: with `all`, neutralising only the inner leaf would give
        // NOT(false) = true → an existence oracle. Option B short-circuits the whole
        // traversal to `false` BEFORE the quantifier negation.
        $model  = $this->edgeModel( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'employee[*].salary' , 'op' => 'gt' , 'val' => 1000 , 'quant' => 'all' ] , fn() => false ) , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 1000 , $this->binds ) ;
    }

    public function testEdgeLeafFailsOpenWhenTargetModelUnresolved(): void
    {
        // MockEdges leaves ->to null → the target projection is unresolvable, so the
        // leaf cannot be gated (fail-open — same philosophy as isAuthorized).
        $edge = new MockEdges( 'employee_edges' ) ; // ->to stays null
        $this->container->set( 'EmployeeEdge' , $edge ) ;

        $model = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'organizations' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'employee' => [ AQL::TYPE => Filter::EDGES , AQL::FILTERS => [ 'salary' => FilterType::NUMBER ] ] ] ,
            AQL::EDGES      => [ 'employee' => [ AQL::MODEL => 'EmployeeEdge' , AQL::DIRECTION => Traversal::OUTBOUND ] ] ,
        ]);

        $result = $model->prepareFilter( $this->filter( [ 'key' => 'employee[*].salary' , 'op' => 'gt' , 'val' => 1000 ] , fn() => false ) , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertContains( 1000 , $this->binds ) ;
    }

    // ---------------------------------------------------------------- join leaf (target model)

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function joinModel( array $companyFields ): Documents
    {
        $company = new Documents( $this->container ,
        [
            AQL::COLLECTION => 'companies' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'revenue' => FilterType::NUMBER ] ,
            AQL::FIELDS     => $companyFields ,
        ]);
        $this->container->set( 'CompanyModel' , $company ) ;

        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'customers' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    => [ 'company' => [ AQL::TYPE => Filter::JOIN , AQL::FILTERS => [ 'revenue' => FilterType::NUMBER ] ] ] ,
            AQL::JOINS      => [ 'company' => [ AQL::MODEL => 'CompanyModel' , AQL::KEY => '_key' ] ] ,
        ]);
    }

    public function testRefusedJoinLeafNeutralisesTheWholeJoin(): void
    {
        $model  = $this->joinModel( [ 'revenue' => [ Field::REQUIRES => 'finance:read' ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'company.revenue' , 'op' => 'gt' , 'val' => 1000000 ] , fn() => false ) , $this->binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertNotContains( 1000000 , $this->binds ) ;
    }

    public function testGrantedJoinLeafTraverses(): void
    {
        $model  = $this->joinModel( [ 'revenue' => [ Field::REQUIRES => 'finance:read' ] ] ) ;
        $result = $model->prepareFilter( $this->filter( [ 'key' => 'company.revenue' , 'op' => 'gt' , 'val' => 1000000 ] , fn( string $s ) => $s === 'finance:read' ) , $this->binds ) ;

        $this->assertStringContainsString( 'LENGTH(FOR ' , $result ) ;
        $this->assertContains( 1000000 , $this->binds ) ;
    }
}
