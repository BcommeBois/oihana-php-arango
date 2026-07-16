<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use function oihana\arango\models\helpers\joins\buildJoinSubquery;

/**
 * Focused coverage for {@see buildJoinSubquery()} — the inner join sub-query
 * body ({@see \oihana\arango\models\helpers\joins\buildJoinVariable()} wraps it
 * in `LET name = ( … )`, {@see \oihana\arango\models\helpers\joins\buildPolymorphicJoinVariable()}
 * wraps several bodies in `APPEND`). The historical behaviour is already covered
 * through `buildJoinVariable`; here we pin the two additions: the `$extraConditions`
 * injection and the *no-LET* output shape.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class BuildJoinSubqueryTest extends TestCase
{
    public function testReturnsBodyWithoutLetOrOuterParentheses() :void
    {
        $result = $this->normalize( buildJoinSubquery( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ) ;

        $this->assertSame
        (
            'FOR doc_join IN roles FILTER doc_join._key == doc.role RETURN doc_join' ,
            $result
        ) ;
    }

    public function testExtraConditionsArePrependedBeforeDefinitionConditions() :void
    {
        $result = $this->normalize( buildJoinSubquery
        (
            'role' ,
            [ AQL::MODEL => new MockDocuments( 'roles' ) , Arango::CONDITIONS => [ 'doc.active == true' ] ] ,
            AQL::DOC ,
            null ,
            [] ,
            false ,
            [ 'doc.kind == "guard"' ] // extra condition (e.g. discriminator guard)
        ) ) ;

        $this->assertSame
        (
            'FOR doc_join IN roles FILTER doc_join._key == doc.role ' .
            '&& doc.kind == "guard" && doc.active == true RETURN doc_join' ,
            $result
        ) ;
    }

    /**
     * Normalizes the random `doc_join_<n>` loop ref to a stable token.
     *
     * @param string $aql
     *
     * @return string
     */
    private function normalize( string $aql ) :string
    {
        return preg_replace( '/doc_join_\d+/' , 'doc_join' , $aql ) ;
    }
}
