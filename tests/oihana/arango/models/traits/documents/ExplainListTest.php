<?php

namespace tests\oihana\arango\models\traits\documents;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\ArangoDB;
use oihana\arango\db\results\ExplainResult;
use oihana\arango\models\traits\documents\DocumentsListTrait;

/**
 * Bare host exposing {@see DocumentsListTrait}. Mirrors `ListQueryTraitStub`:
 * it sets the query id and collection so `buildListQuery()` works, and lets a
 * test inject a mock {@see ArangoDB} façade.
 */
class DocumentsListExplainStub
{
    use DocumentsListTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'users' ;
    }

    public function setArango( ArangoDB $arango ) : void
    {
        $this->arangodb = $arango ;
    }
}

/**
 * Coverage for {@see DocumentsListTrait::explainList()} and, through it,
 * {@see \oihana\arango\models\traits\ArangoTrait::explain()} — both delegate to
 * the façade without executing the query.
 */
class ExplainListTest extends TestCase
{
    public function testExplainListBuildsAndForwardsTheListQuery() : void
    {
        $sentinel = new ExplainResult( [ 'plan' => [ 'rules' => [ 'use-indexes' ] ] ] );

        $arango = $this->createMock( ArangoDB::class );
        $arango->expects( $this->once() )
               ->method( 'explain' )
               ->with( 'FOR doc IN @@collection RETURN doc' , [ '@collection' => 'users' ] , [] )
               ->willReturn( $sentinel );

        $stub = new DocumentsListExplainStub() ;
        $stub->setArango( $arango ) ;

        $result = $stub->explainList() ;

        $this->assertSame( $sentinel , $result );
        $this->assertSame( [ 'use-indexes' ] , $result->rules() );
    }
}
