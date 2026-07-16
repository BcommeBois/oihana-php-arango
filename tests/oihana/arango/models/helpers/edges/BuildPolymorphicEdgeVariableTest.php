<?php

namespace tests\oihana\arango\models\helpers\edges;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\buildPolymorphicEdgeVariable;

/**
 * Coverage for {@see buildPolymorphicEdgeVariable()} — builds a
 * `LET name = APPEND( ( FOR … ) , ( FOR … ) )` polymorphic edge whose traversed
 * collection is chosen by a discriminator field of the start vertex.
 *
 * The vertex / edge loop refs are random (`vertex_<n>` / `edge_<n>`), normalized
 * to `vertex` / `edge` before the exact assertions.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildPolymorphicEdgeVariableTest extends TestCase
{
    private const string OPTIONS = 'OPTIONS {"order":"bfs","uniqueVertices":"global"}' ;

    public function testThrowsWhenNameIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicEdgeVariable( '' ,
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           => [ 'w' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) ] ] ,
        ]) ;
    }

    public function testThrowsWhenMapMissingOrEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicEdgeVariable( 'area' , [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [] ] ) ;
    }

    public function testThrowsWhenDiscriminatorMissing() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::MAP => [ 'w' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) ] ] ,
        ]) ;
    }

    public function testThrowsWhenBranchIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           => [ 'w' => 'not-a-branch' ] ,
        ]) ;
    }

    public function testTwoBranchesBuildAppendWithGuards() :void
    {
        $result = $this->normalize( buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           =>
            [
                'warehouse' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) , Arango::PROPERTY => 'name' ] ,
                'company'   => [ AQL::MODEL => new MockEdges( 'company_edges'   ) , Arango::PROPERTY => 'name' ] ,
            ] ,
        ]) ) ;

        $this->assertSame
        (
            'LET area = APPEND(' .
            '(FOR vertex, edge IN OUTBOUND doc warehouse_edges ' . self::OPTIONS . ' ' .
            'FILTER doc.kind == "warehouse" SORT edge.created DESC RETURN vertex.name),' .
            '(FOR vertex, edge IN OUTBOUND doc company_edges ' . self::OPTIONS . ' ' .
            'FILTER doc.kind == "company" SORT edge.created DESC RETURN vertex.name))' ,
            $result
        ) ;
    }

    public function testSingleBranchHasNoAppend() :void
    {
        $result = $this->normalize( buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           => [ 'warehouse' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) , Arango::PROPERTY => 'name' ] ] ,
        ]) ) ;

        $this->assertSame
        (
            'LET area = (FOR vertex, edge IN OUTBOUND doc warehouse_edges ' . self::OPTIONS . ' ' .
            'FILTER doc.kind == "warehouse" SORT edge.created DESC RETURN vertex.name)' ,
            $result
        ) ;
    }

    public function testUniqueOverridesTheVariableName() :void
    {
        $result = $this->normalize( buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::UNIQUE        => 'zone' ,
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           => [ 'warehouse' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) , Arango::PROPERTY => 'name' ] ] ,
        ]) ) ;

        $this->assertStringStartsWith( 'LET zone = ' , $result ) ;
    }

    public function testDirectionCanVaryPerBranch() :void
    {
        $result = $this->normalize( buildPolymorphicEdgeVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'kind' ,
            Arango::MAP           =>
            [
                'warehouse' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) , Arango::PROPERTY => 'name' ] ,
                'company'   => [ AQL::MODEL => new MockEdges( 'company_edges' ) , AQL::DIRECTION => Traversal::INBOUND , Arango::PROPERTY => 'name' ] ,
            ] ,
        ]) ) ;

        $this->assertStringContainsString( 'IN OUTBOUND doc warehouse_edges' , $result ) ;
        $this->assertStringContainsString( 'IN INBOUND doc company_edges'    , $result ) ;
    }

    public function testStartVertexIsHonoredInTheGuardAndTraversal() :void
    {
        $result = $this->normalize( buildPolymorphicEdgeVariable
        (
            'area' ,
            [
                Arango::DISCRIMINATOR => 'selector.kind' ,
                Arango::MAP           => [ 'warehouse' => [ AQL::MODEL => new MockEdges( 'warehouse_edges' ) , Arango::PROPERTY => 'name' ] ] ,
            ] ,
            'parent'
        ) ) ;

        $this->assertStringContainsString( 'IN OUTBOUND parent warehouse_edges' , $result ) ;
        $this->assertStringContainsString( 'FILTER parent.selector.kind == "warehouse"' , $result ) ;
    }

    /**
     * Normalizes the random `vertex_<n>` / `edge_<n>` loop refs to stable tokens.
     *
     * @param string $aql
     *
     * @return string
     */
    private function normalize( string $aql ) :string
    {
        return preg_replace( [ '/vertex_\d+/' , '/edge_\d+/' ] , [ 'vertex' , 'edge' ] , $aql ) ;
    }
}
