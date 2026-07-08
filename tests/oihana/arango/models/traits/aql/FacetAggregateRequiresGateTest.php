<?php

namespace tests\oihana\arango\models\traits\aql;

use DI\Container;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\Documents;
use oihana\enums\Boolean;

/**
 * Permission gate on aggregate facets (lot C, Levier 2): the aggregated field
 * inherits the `Field::REQUIRES` of the facet's target model (`AQL::MODEL`). A
 * refused field neutralises the facet to `false` — closing the aggregate bound
 * oracle (`agg:max` + threshold dichotomy) on a hidden field. Opt-in: with no
 * `AQL::MODEL` declared, only the Levier 1 whitelist applies.
 */
class FacetAggregateRequiresGateTest extends TestCase
{
    private FacetTraitStub $stub;
    private array $binds;

    protected function setUp(): void
    {
        $container = new Container() ;
        $container->set( 'BalanceModel' , new Documents( $container ,
        [
            AQL::COLLECTION => 'balances' ,
            AQL::LAZY       => false ,
            AQL::FIELDS     => [ 'revenue' => [ Field::REQUIRES => 'finance:read' ] , 'headcount' => true ] ,
        ]) ) ;

        $this->stub            = new FacetTraitStub() ;
        $this->stub->container = $container ;
        $this->binds           = [] ;
    }

    private function facet( array $extra = [] ): array
    {
        return [ AQL::EDGE => 'balance_edges' , AQL::FIELDS => [ 'revenue' , 'headcount' ] ] + $extra ;
    }

    private function value( string $field ): array
    {
        return [ 'agg' => 'avg' , 'field' => $field , 'val' => 1000000 ] ;
    }

    public function testRefusedTargetFieldNeutralisesTheFacet(): void
    {
        $facet  = $this->facet( [ AQL::MODEL => 'BalanceModel' ] ) ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'revenue' ) , $this->binds , $facet , AQL::DOC , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
        $this->assertSame( [] , $this->binds ) ;
    }

    public function testGrantedTargetFieldAggregates(): void
    {
        $facet  = $this->facet( [ AQL::MODEL => 'BalanceModel' ] ) ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'revenue' ) , $this->binds , $facet , AQL::DOC , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'finance:read' ] ) ;

        $this->assertStringContainsString( 'AVERAGE(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue)' , $result ) ;
    }

    public function testUngatedTargetFieldAggregatesUnderDenyingAuthorizer(): void
    {
        // `headcount` carries no REQUIRES → aggregated even when the authorizer denies.
        $facet  = $this->facet( [ AQL::MODEL => 'BalanceModel' ] ) ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'headcount' ) , $this->binds , $facet , AQL::DOC , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertStringContainsString( 'doc_balanceSheets.headcount' , $result ) ;
    }

    public function testWithoutModelTheTargetFieldIsNotGated(): void
    {
        // No AQL::MODEL → Levier 2 skipped: the locked `revenue` still aggregates
        // (only the Levier 1 whitelist + the facet-level REQUIRES protect).
        $facet  = $this->facet() ; // no AQL::MODEL
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'revenue' ) , $this->binds , $facet , AQL::DOC , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertStringContainsString( 'doc_balanceSheets.revenue' , $result ) ;
    }

    public function testUnknownModelSkipsTheGate(): void
    {
        // AQL::MODEL declared but absent from the container → gate skipped (aggregates).
        $facet  = $this->facet( [ AQL::MODEL => 'MissingModel' ] ) ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'revenue' ) , $this->binds , $facet , AQL::DOC , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertStringContainsString( 'doc_balanceSheets.revenue' , $result ) ;
    }

    public function testGatedTargetFieldFailsOpenWithoutAuthorizer(): void
    {
        $facet  = $this->facet( [ AQL::MODEL => 'BalanceModel' ] ) ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $this->value( 'revenue' ) , $this->binds , $facet , AQL::DOC ) ; // no authorizer

        $this->assertStringContainsString( 'doc_balanceSheets.revenue' , $result ) ;
    }
}
