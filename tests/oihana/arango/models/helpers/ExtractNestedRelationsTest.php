<?php

namespace tests\oihana\arango\models\helpers;

use DI\Container;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\extractNestedRelations;

/**
 * Characterization coverage for {@see extractNestedRelations()} — resolves the
 * nested edges / joins of a relation target, either from an already-resolved
 * model, or by resolving the edge/join model first, then merges the config's
 * own explicit edges / joins.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class ExtractNestedRelationsTest extends TestCase
{
    public function testTargetModelModeReadsItsEdgesAndJoins() :void
    {
        $target = new MockDocuments( 'roles' ) ;
        $target->edges = [ 'perms' => [] ] ;
        $target->joins = [ 'org' => [] ] ;

        $result = extractNestedRelations( [] , $target ) ;

        $this->assertSame( [ 'perms' => [] ] , $result[ AQL::EDGES ] ) ;
        $this->assertSame( [ 'org' => [] ] , $result[ AQL::JOINS ] ) ;
    }

    public function testEdgeModeResolvesTargetFromDirection() :void
    {
        $to = new MockDocuments( 'roles' ) ;
        $to->initializeDeleteSignals() ;
        $to->edges = [ 'perms' => [] ] ;
        $to->joins = [ 'org' => [] ] ;

        $edgeModel = new MockEdges( 'user_has_roles' ) ;
        $edgeModel->to = $to ;

        $result = extractNestedRelations
        (
            [ AQL::MODEL => $edgeModel , AQL::DIRECTION => Traversal::OUTBOUND ] ,
            null ,
            true , // isEdge
            new Container() ,
        ) ;

        $this->assertSame( [ 'perms' => [] ] , $result[ AQL::EDGES ] ) ;
        $this->assertSame( [ 'org' => [] ] , $result[ AQL::JOINS ] ) ;
    }

    public function testJoinModeSwallowsUnresolvableModel() :void
    {
        // container->get('missing.service') throws → caught, empty relations
        $result = extractNestedRelations
        (
            [ AQL::MODEL => 'missing.service' ] ,
            null ,
            false , // isEdge → join branch
            new Container() ,
        ) ;

        $this->assertSame( [] , $result[ AQL::EDGES ] ) ;
        $this->assertSame( [] , $result[ AQL::JOINS ] ) ;
    }

    public function testJoinModeReadsResolvedModelRelations() :void
    {
        $target = new MockDocuments( 'roles' ) ;
        $target->edges = [ 'perms' => [] ] ;

        $container = new Container() ;
        $container->set( 'roles.model' , $target ) ;

        $result = extractNestedRelations
        (
            [ AQL::MODEL => 'roles.model' ] ,
            null ,
            false ,
            $container ,
        ) ;

        $this->assertSame( [ 'perms' => [] ] , $result[ AQL::EDGES ] ) ;
    }

    public function testMergesExplicitConfigEdgesAndJoins() :void
    {
        $result = extractNestedRelations
        ([
            AQL::EDGES => [ 'extraEdge' => [] ] ,
            AQL::JOINS => [ 'extraJoin' => [] ] ,
        ]) ;

        $this->assertArrayHasKey( 'extraEdge' , $result[ AQL::EDGES ] ) ;
        $this->assertArrayHasKey( 'extraJoin' , $result[ AQL::JOINS ] ) ;
    }
}
