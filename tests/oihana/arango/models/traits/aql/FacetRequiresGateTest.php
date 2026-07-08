<?php

namespace tests\oihana\arango\models\traits\aql;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\models\enums\Facet;
use oihana\enums\Boolean;

/**
 * Permission gate on `?facets=` (lot 2): a facet on a field hidden from the
 * projection (`Field::REQUIRES`, inherited from `$fields` or declared on the
 * facet) is neutralised to `false` — no facet oracle.
 */
class FacetRequiresGateTest extends TestCase
{
    private function stub( array $fields = [] ): FacetTraitStub
    {
        $stub = new FacetTraitStub() ;
        $stub->fields = $fields ;
        $stub->facets = [ 'salary' => [ Facet::TYPE => Facet::FIELD ] ] ;
        return $stub ;
    }

    private function init( callable $authorizer ): array
    {
        return [ Arango::FACETS => [ 'salary' => 'x' ] , Arango::AUTHORIZER => $authorizer ] ;
    }

    public function testRefusedFacetIsNeutralisedToFalse(): void
    {
        // salary is gated in the projection → the facet-filter neutralises to `false`.
        $stub  = $this->stub( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $binds = [] ;
        $result = $stub->callPrepareFacets( $this->init( fn() => false ) , $binds ) ;

        $this->assertSame( Boolean::FALSE , $result ) ;
    }

    public function testGrantedFacetProducesThePredicate(): void
    {
        $stub  = $this->stub( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $binds = [] ;
        $result = $stub->callPrepareFacets( $this->init( fn( string $s ) => $s === 'hr:read' ) , $binds ) ;

        $this->assertNotSame( Boolean::FALSE , $result ) ;
        $this->assertStringContainsString( 'doc.salary' , $result ) ;
    }

    public function testFacetWithoutRequiresIsUnaffected(): void
    {
        // No REQUIRES anywhere → the facet builds freely, even under a denying authorizer.
        $stub  = $this->stub( [ 'salary' => true ] ) ;
        $binds = [] ;
        $result = $stub->callPrepareFacets( $this->init( fn() => false ) , $binds ) ;

        $this->assertStringContainsString( 'doc.salary' , $result ) ;
    }

    public function testFacetFailsOpenWithoutAuthorizer(): void
    {
        $stub  = $this->stub( [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ) ;
        $binds = [] ;
        $result = $stub->callPrepareFacets( [ Arango::FACETS => [ 'salary' => 'x' ] ] , $binds ) ;

        $this->assertStringContainsString( 'doc.salary' , $result ) ;
    }

    public function testExplicitRequiresOnTheFacetDefinitionIsHonored(): void
    {
        // No projection field, but the facet definition carries its own REQUIRES.
        $stub = new FacetTraitStub() ;
        $stub->facets = [ 'salary' => [ Facet::TYPE => Facet::FIELD , Field::REQUIRES => 'hr:read' ] ] ;

        $binds   = [] ;
        $refused = $stub->callPrepareFacets( $this->init( fn() => false ) , $binds ) ;
        $this->assertSame( Boolean::FALSE , $refused ) ;

        $binds   = [] ;
        $granted = $stub->callPrepareFacets( $this->init( fn( string $s ) => $s === 'hr:read' ) , $binds ) ;
        $this->assertStringContainsString( 'doc.salary' , $granted ) ;
    }
}
