<?php

namespace tests\oihana\arango\models\helpers\relations;

use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\models\Edges;

use UnexpectedValueException;
use PHPUnit\Framework\TestCase;
use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

/**
 * Records the edge operations triggered by updateEdgeRelation().
 */
class EdgesSpy extends Edges
{
    public array $calls        = [] ;
    public bool  $existFromRet = false ;
    public bool  $existToRet   = false ;

    public function __construct()
    {
        $this->collection = 'rel_edges' ;
        $this->queryId    = 'q' ;
    }

    public function existEdgeFrom( string $from , array $init = [] ) :bool
    {
        $this->calls[] = 'existFrom:' . $from ;
        return $this->existFromRet ;
    }

    public function existEdgeTo( string $to , array $init = [] ) :bool
    {
        $this->calls[] = 'existTo:' . $to ;
        return $this->existToRet ;
    }

    public function deleteEdgeFrom( string $from , array $init = [] ) :array|object|null
    {
        $this->calls[] = 'delFrom:' . $from ;
        return null ;
    }

    public function deleteEdgeTo( string $to , array $init = [] ) :array|object|null
    {
        $this->calls[] = 'delTo:' . $to ;
        return null ;
    }

    public function insertEdge( string $from , string $to , array $doc = [] , array $init = [] ) :?object
    {
        $this->calls[] = 'insert:' . $from . '->' . $to ;
        return (object) [ '_key' => 'e1' ] ;
    }
}

/**
 * Coverage for {@see updateEdgeRelation()} — the per-relation edge sync used by
 * the OnUpdateRelations callback: resolve the Edges model, optionally clean the
 * existing edge (unique), insert the new edge, and touch the vertex (touch).
 */
final class UpdateEdgeRelationTest extends TestCase
{
    private function spy() :EdgesSpy
    {
        return new EdgesSpy() ;
    }

    public function testOutboundUniqueReplacesExistingEdgeThenInserts() :void
    {
        $spy = $this->spy() ;
        $spy->existFromRet = true ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertSame( [ 'existFrom:u1' , 'delFrom:u1' , 'insert:u1->roles/9' ] , $spy->calls ) ;
    }

    public function testInboundUniqueReplacesExistingEdgeThenInserts() :void
    {
        $spy = $this->spy() ;
        $spy->existToRet = true ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' , Arango::DIRECTION => Traversal::INBOUND ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertSame( [ 'existTo:u1' , 'delTo:u1' , 'insert:roles/9->u1' ] , $spy->calls ) ;
    }

    public function testNoExistingEdgeJustInserts() :void
    {
        $spy = $this->spy() ; // existFromRet stays false

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertSame( [ 'existFrom:u1' , 'insert:u1->roles/9' ] , $spy->calls ) ;
    }

    public function testWithoutValueOnlyCleansTheExistingEdge() :void
    {
        $spy = $this->spy() ;
        $spy->existFromRet = true ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy ] , // no VALUE
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertSame( [ 'existFrom:u1' , 'delFrom:u1' ] , $spy->calls ) ;
    }

    public function testNonUniqueSkipsTheExistenceCheckAndJustInserts() :void
    {
        $spy = $this->spy() ;
        $spy->existFromRet = true ; // would be deleted if checked

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' , Arango::UNIQUE => false ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertSame( [ 'insert:u1->roles/9' ] , $spy->calls ) ;
    }

    public function testTouchUpdatesTheOriginVertexDate() :void
    {
        $from = new MockDocuments( 'users' ) ;
        $from->objectResult = (object) [ '_key' => 'u1' ] ;
        $from->initializeDeleteSignals() ; // destructor (releaseVertices) touches from->afterDelete

        $spy = $this->spy() ;
        $spy->from = $from ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' , Arango::TOUCH => true ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        // touch → $edges->from->updateDate() runs an UPDATE on the from model.
        $this->assertStringContainsString( 'UPDATE doc WITH @update' , $from->lastQuery ) ;
    }

    public function testTouchUpdatesTheTargetVertexDateOnInbound() :void
    {
        $to = new MockDocuments( 'roles' ) ;
        $to->objectResult = (object) [ '_key' => 'r9' ] ;
        $to->initializeDeleteSignals() ;

        $spy = $this->spy() ;
        $spy->to = $to ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $spy , Arango::VALUE => 'roles/9' , Arango::TOUCH => true , Arango::DIRECTION => Traversal::INBOUND ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;

        $this->assertStringContainsString( 'UPDATE doc WITH @update' , $to->lastQuery ) ;
    }

    public function testFallsBackToTheModelEdgeDefinitions() :void
    {
        $spy = $this->spy() ;

        $documents = new MockDocuments( 'users' ) ;
        $documents->edges = [ 'roles' => [ Arango::MODEL => $spy ] ] ;

        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::VALUE => 'roles/9' ] , // no EDGES → fallback to $documents->edges
            (object) [ '_key' => 'u1' ] ,
            null ,
            $documents ,
        ) ;

        $this->assertSame( [ 'existFrom:u1' , 'insert:u1->roles/9' ] , $spy->calls ) ;
    }

    public function testThrowsWhenNoValidEdgesModelResolved() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::VALUE => 'roles/9' ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;
    }

    public function testThrowsWhenDocumentMissesTheKey() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;
        \oihana\arango\models\helpers\relations\updateEdgeRelation
        (
            'roles' ,
            [ Arango::EDGES => $this->spy() , Arango::KEY => 'absent' ] ,
            (object) [ '_key' => 'u1' ] ,
        ) ;
    }
}
