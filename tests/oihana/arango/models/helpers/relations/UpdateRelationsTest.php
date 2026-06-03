<?php

namespace tests\oihana\arango\models\helpers\relations;

use oihana\arango\controllers\enums\AQLType;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\relations\updateRelations;

/**
 * Coverage for the guard branches of {@see updateRelations()} — the no-op paths
 * when there is nothing to do (missing document, missing/empty relations) and the
 * skip of non-edge relation types. The EDGE dispatch to updateEdgeRelation needs a
 * resolved Edges model from the container and is out of scope here.
 */
final class UpdateRelationsTest extends TestCase
{
    public function testReturnsEarlyWhenDocumentIsNull() :void
    {
        updateRelations( document: null , relations: [ 'perm' => [ Arango::TYPE => AQLType::EDGE ] ] ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testReturnsEarlyWhenRelationsAreNull() :void
    {
        updateRelations( document: (object) [ '_key' => '1' ] , relations: null ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testReturnsEarlyWhenRelationsAreEmpty() :void
    {
        updateRelations( document: (object) [ '_key' => '1' ] , relations: [] ) ;
        $this->expectNotToPerformAssertions() ;
    }

    public function testSkipsNonEdgeRelationTypes() :void
    {
        updateRelations
        (
            document  : (object) [ '_key' => '1' ] ,
            relations : [ 'whatever' => [ Arango::TYPE => 'not_an_edge' ] ] ,
        ) ;
        $this->expectNotToPerformAssertions() ;
    }
}
