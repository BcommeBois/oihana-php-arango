<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\exceptions\UnsupportedOperationException;

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

    public function testKeyPathAnchorsTheMatchWhileNameStaysTheNestingPrefix() :void
    {
        // $keyPath overrides the match root (doc.selector.providerId), decoupled
        // from $name which keeps prefixing nested-relation variable names.
        $result = $this->normalize( buildJoinSubquery
        (
            'provider' ,
            [ AQL::MODEL => new MockDocuments( 'providers' ) ] ,
            AQL::DOC ,
            null ,
            [] ,
            false ,
            [] ,
            'selector.providerId' // $keyPath
        ) ) ;

        $this->assertSame
        (
            'FOR doc_join IN providers FILTER doc_join._key == doc.selector.providerId RETURN doc_join' ,
            $result
        ) ;
    }

    public function testNullKeyPathFallsBackOnNameByteIdentically() :void
    {
        $with    = $this->normalize( buildJoinSubquery( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] , AQL::DOC , null , [] , false , [] , null ) ) ;
        $without = $this->normalize( buildJoinSubquery( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ) ;

        $this->assertSame( $without , $with ) ;
        $this->assertSame( 'FOR doc_join IN roles FILTER doc_join._key == doc.role RETURN doc_join' , $with ) ;
    }

    public function testAdHocProjectionInheritsTargetFieldRequires() :void
    {
        // The join's own AQL::FIELDS re-projects `salary` WITHOUT re-declaring its
        // permission; the target model masks it. The gate must inherit that (T6).
        $target = new MockDocuments( 'people' ) ;
        $target->fields = [ 'name' => [] , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;

        $definition = [ AQL::MODEL => $target , AQL::FIELDS => [ 'name' => [] , 'salary' => [] ] ] ;

        // Denied → salary dropped from the join projection (no field oracle via the join).
        $denied = buildJoinSubquery( 'employer' , $definition , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ;
        $this->assertStringContainsString   ( 'name'   , $denied ) ;
        $this->assertStringNotContainsString( 'salary' , $denied ) ;

        // Granted → salary kept.
        $granted = buildJoinSubquery( 'employer' , $definition , AQL::DOC , null , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ) ;
        $this->assertStringContainsString( 'salary' , $granted ) ;
    }

    // ------------------------------------------------- a list of objects, a list that keeps its references

    /**
     * 🚨 A list of objects is joined on `'[*]._key'` : the expansion is glued to the
     * list, where it used to be written `doc.refs.[*]._key` — a syntax error.
     */
    public function testAListOfObjectsIsJoinedOnItsExpandedKey() :void
    {
        $result = $this->normalize( buildJoinSubquery
        (
            'refs' ,
            [ AQL::MODEL => new MockDocuments( 'notes' ) , Arango::PROPERTY => '[*]._key' ] ,
            AQL::DOC , null , [] , true
        ) ) ;

        $this->assertSame
        (
            'FOR doc_join IN notes FILTER doc_join._key IN (IS_ARRAY(doc.refs[*]._key) ? doc.refs[*]._key : []) SORT doc_join._key DESC RETURN doc_join' ,
            $result
        ) ;
    }

    /**
     * Without a Field::ELSE, a list join is the one it always was, character for
     * character — the default sort included.
     */
    public function testAListJoinWithoutElseIsUnchanged() :void
    {
        $result = $this->normalize( buildJoinSubquery( 'roles' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] , AQL::DOC , null , [] , true ) ) ;

        $this->assertSame
        (
            'FOR doc_join IN roles FILTER doc_join._key IN (IS_ARRAY(doc.roles) ? doc.roles : []) SORT doc_join._key DESC RETURN doc_join' ,
            $result
        ) ;
    }

    /**
     * With `Field::ELSE => true`, the stored list is walked element by element : each
     * element becomes its document, or stays as stored — in its stored order, with no
     * sort.
     */
    public function testElseTrueResolvesTheListElementByElement() :void
    {
        $result = $this->normalize( buildJoinSubquery
        (
            'refs' ,
            [ AQL::MODEL => new MockDocuments( 'notes' ) , Arango::PROPERTY => '[*]._key' ] ,
            AQL::DOC , null , [] , true , [] , null , true
        ) ) ;

        $this->assertSame
        (
            'FOR item IN (IS_ARRAY(doc.refs) ? doc.refs : []) ' .
            'LET doc_join_hit = FIRST((FOR doc_join IN notes FILTER doc_join._key == item._key LIMIT 1 RETURN doc_join)) ' .
            'RETURN NOT_NULL(doc_join_hit,item)' ,
            $result
        ) ;
        $this->assertStringNotContainsString( 'SORT' , $result ) ;
    }

    /**
     * A list of bare keys : the element IS the key.
     */
    public function testElseOnAListOfBareKeysComparesTheElementItself() :void
    {
        $result = $this->normalize( buildJoinSubquery( 'tracks' , [ AQL::MODEL => new MockDocuments( 'tracks' ) ] , AQL::DOC , null , [] , true , [] , null , true ) ) ;

        $this->assertStringContainsString( 'FILTER doc_join._key == item LIMIT 1' , $result ) ;
        $this->assertStringContainsString( 'RETURN NOT_NULL(doc_join_hit,item)' , $result ) ;
    }

    /**
     * The two other forms of Field::ELSE are read on the ELEMENT : one of its
     * attributes, or a literal.
     */
    public function testElseTakesTheWhenElseGrammarReadOnTheElement() :void
    {
        $definition = [ AQL::MODEL => new MockDocuments( 'notes' ) , Arango::PROPERTY => '[*]._key' ] ;

        $attribute = $this->normalize( buildJoinSubquery( 'refs' , $definition , AQL::DOC , null , [] , true , [] , null , [ Field::PROPERTY => 'id' ] ) ) ;
        $literal   = $this->normalize( buildJoinSubquery( 'refs' , $definition , AQL::DOC , null , [] , true , [] , null , 'unknown' ) ) ;

        $this->assertStringContainsString( 'RETURN NOT_NULL(doc_join_hit,item.id)' , $attribute ) ;
        $this->assertStringContainsString( "RETURN NOT_NULL(doc_join_hit,'unknown')" , $literal ) ;
    }

    /**
     * The conditions and the projection of the join live in the INNER loop, beside
     * the key match : an element whose document a condition masks keeps its fallback.
     */
    public function testConditionsAndProjectionStayInTheInnerLoop() :void
    {
        $result = $this->normalize( buildJoinSubquery
        (
            'tracks' ,
            [ AQL::MODEL => new MockDocuments( 'tracks' ) , Arango::CONDITIONS => [ 'doc.ok == true' ] , AQL::FIELDS => [ 'name' => [] ] ] ,
            AQL::DOC , null , [] , true , [] , null , true
        ) ) ;

        $this->assertStringContainsString
        (
            'FIRST((FOR doc_join IN tracks FILTER doc_join._key == item && doc.ok == true LIMIT 1 RETURN {name:doc_join.name}))' ,
            $result
        ) ;
    }

    /**
     * The anchor of the list follows Arango::SOURCE, as the key match always did.
     */
    public function testElseFollowsTheKeyPathAnchor() :void
    {
        $result = $this->normalize( buildJoinSubquery
        (
            'providers' ,
            [ AQL::MODEL => new MockDocuments( 'providers' ) ] ,
            AQL::DOC , null , [] , true , [] , 'selector.providerIds' , true
        ) ) ;

        $this->assertStringStartsWith( 'FOR item IN (IS_ARRAY(doc.selector.providerIds) ? doc.selector.providerIds : [])' , $result ) ;
    }

    /**
     * 🚨 A sort and a Field::ELSE together are refused, naming the join.
     */
    public function testASortWithElseIsRefused() :void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( "'refs'" ) ;

        buildJoinSubquery
        (
            'refs' ,
            [ AQL::MODEL => new MockDocuments( 'notes' ) , AQL::SORT => 'name' ] ,
            AQL::DOC , null , [] , true , [] , null , true
        ) ;
    }

    /**
     * A sort WITHOUT Field::ELSE is the sort it always was.
     */
    public function testASortWithoutElseStillSorts() :void
    {
        $result = $this->normalize( buildJoinSubquery( 'refs' , [ AQL::MODEL => new MockDocuments( 'notes' ) , AQL::SORT => 'name' ] , AQL::DOC , null , [] , true ) ) ;

        $this->assertStringContainsString( 'SORT doc_join.name ASC' , $result ) ;
    }

    /**
     * On a SINGLE join the Field::ELSE is aqlFields()'s business : the sub-query is
     * the one it always was.
     */
    public function testElseIsIgnoredOnASingleJoin() :void
    {
        $with    = $this->normalize( buildJoinSubquery( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] , AQL::DOC , null , [] , false , [] , null , true ) ) ;
        $without = $this->normalize( buildJoinSubquery( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ) ;

        $this->assertSame( $without , $with ) ;
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
        return preg_replace( [ '/doc_join_\d+/' , '/item_\d+/' ] , [ 'doc_join' , 'item' ] , $aql ) ;
    }
}
