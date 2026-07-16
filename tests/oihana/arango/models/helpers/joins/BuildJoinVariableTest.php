<?php

namespace tests\oihana\arango\models\helpers\joins;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;
use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\joins\buildJoinVariable;

/**
 * Characterization coverage for {@see buildJoinVariable()} — builds a
 * `LET name = ( FOR doc_join IN collection FILTER ... [SORT] RETURN ... )`
 * join sub-query variable.
 *
 * The join loop ref is random (`doc_join_<n>`), normalized to `doc_join`
 * before the exact assertions.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class BuildJoinVariableTest extends TestCase
{
    public function testThrowsWhenNameIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildJoinVariable( '' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ;
    }

    public function testThrowsWhenModelIsNotDocuments() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        // MockEdges IS a Documents subclass, so use a plainly invalid model value
        buildJoinVariable( 'role' , [ AQL::MODEL => 'not-a-model' ] ) ;
    }

    public function testThrowsWhenCollectionIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildJoinVariable( 'role' , [ AQL::MODEL => new MockDocuments( '' ) ] ) ;
    }

    public function testScalarJoinBuildsEqualityFilter() :void
    {
        $result = $this->normalize( buildJoinVariable( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] ) ) ;

        $this->assertSame
        (
            'LET role = (FOR doc_join IN roles FILTER doc_join._key == doc.role RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testArrayJoinBuildsInFilterWithIsArrayGuardAndSort() :void
    {
        $result = $this->normalize
        (
            buildJoinVariable( 'roles' , [ AQL::MODEL => new MockDocuments( 'roles' ) ] , AQL::DOC , null , [] , true )
        ) ;

        $this->assertSame
        (
            'LET roles = (FOR doc_join IN roles ' .
            'FILTER doc_join._key IN (IS_ARRAY(doc.roles) ? doc.roles : []) ' .
            'SORT doc_join._key DESC RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testPropertyNestsTheForeignKeyPath() :void
    {
        $result = $this->normalize
        (
            buildJoinVariable( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) , Arango::PROPERTY => 'ref' ] )
        ) ;

        $this->assertStringContainsString( 'FILTER doc_join._key == doc.role.ref' , $result ) ;
    }

    public function testHonorsUniqueNameKeyAndCustomDocRef() :void
    {
        $result = $this->normalize
        (
            buildJoinVariable
            (
                'role' ,
                [ AQL::MODEL => new MockDocuments( 'roles' ) , Arango::UNIQUE => 'r' , Arango::KEY => 'code' ] ,
                'parent'
            )
        ) ;

        $this->assertSame
        (
            'LET r = (FOR doc_join IN roles FILTER doc_join.code == parent.role RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testArrayConditionsAreAppendedToTheFilter() :void
    {
        $result = $this->normalize( buildJoinVariable( 'role' ,
        [
            AQL::MODEL          => new MockDocuments( 'roles' ) ,
            Arango::CONDITIONS  => [ 'doc.active == true' ] ,
        ] ) ) ;

        $this->assertStringContainsString( 'FILTER doc_join._key == doc.role && doc.active == true' , $result ) ;
    }

    public function testCallableConditionsWithOneAndTwoArguments() :void
    {
        $one = $this->normalize( buildJoinVariable( 'role' ,
        [
            AQL::MODEL         => new MockDocuments( 'roles' ) ,
            Arango::CONDITIONS => fn( string $join ) => [ $join . '.active == true' ] ,
        ] ) ) ;

        $this->assertStringContainsString( '&& doc_join.active == true' , $one ) ;

        $two = $this->normalize( buildJoinVariable( 'role' ,
        [
            AQL::MODEL         => new MockDocuments( 'roles' ) ,
            Arango::CONDITIONS => fn( string $join , string $parent ) => [ $join . '.x == ' . $parent . '.y' ] ,
        ] ) ) ;

        $this->assertStringContainsString( '&& doc_join.x == doc.y' , $two ) ;
    }

    public function testThrowsWhenConditionsIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildJoinVariable( 'role' ,
        [
            AQL::MODEL         => new MockDocuments( 'roles' ) ,
            Arango::CONDITIONS => 'not-an-array' ,
        ] ) ;
    }

    public function testFieldsBranchReturnsAProjection() :void
    {
        $result = $this->normalize
        (
            buildJoinVariable( 'role' , [ AQL::MODEL => new MockDocuments( 'roles' ) , AQL::FIELDS => [ 'name' ] ] )
        ) ;

        $this->assertStringContainsString( 'RETURN {' , $result ) ;
    }

    public function testSourceAnchorsTheKeyAtAnAbsolutePathDecoupledFromTheOutputName() :void
    {
        // Output field "provider" but the foreign key value lives elsewhere in
        // the document (doc.selector.providerId), decoupled from the field name.
        $result = $this->normalize
        (
            buildJoinVariable( 'provider' ,
            [
                AQL::MODEL     => new MockDocuments( 'providers' ) ,
                Arango::SOURCE => 'selector.providerId' ,
            ] )
        ) ;

        $this->assertSame
        (
            'LET provider = (FOR doc_join IN providers FILTER doc_join._key == doc.selector.providerId RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testSourceComposesWithPropertyAsARelativeSuffix() :void
    {
        // SOURCE fixes the root, PROPERTY still appends relative to it.
        $result = $this->normalize
        (
            buildJoinVariable( 'provider' ,
            [
                AQL::MODEL       => new MockDocuments( 'providers' ) ,
                Arango::SOURCE   => 'selector.provider' ,
                Arango::PROPERTY => 'id' ,
            ] )
        ) ;

        $this->assertStringContainsString
        (
            'FILTER doc_join._key == doc.selector.provider.id' ,
            $result
        ) ;
    }

    public function testSourceIsHonoredOnTheArrayInFilter() :void
    {
        $result = $this->normalize
        (
            buildJoinVariable( 'providers' ,
            [
                AQL::MODEL     => new MockDocuments( 'providers' ) ,
                Arango::SOURCE => 'selector.providerIds' ,
            ] , AQL::DOC , null , [] , true )
        ) ;

        $this->assertStringContainsString
        (
            'FILTER doc_join._key IN (IS_ARRAY(doc.selector.providerIds) ? doc.selector.providerIds : [])' ,
            $result
        ) ;
    }

    public function testSourceHonorsUniqueNameAndCustomKeyAndDocRef() :void
    {
        // The output/LET name (via UNIQUE), the foreign attribute (via KEY) and
        // the anchor path (via SOURCE) are all independent.
        $result = $this->normalize
        (
            buildJoinVariable( 'provider' ,
            [
                AQL::MODEL     => new MockDocuments( 'providers' ) ,
                Arango::UNIQUE => 'p' ,
                Arango::KEY    => 'code' ,
                Arango::SOURCE => 'selector.providerCode' ,
            ] , 'parent' )
        ) ;

        $this->assertSame
        (
            'LET p = (FOR doc_join IN providers FILTER doc_join.code == parent.selector.providerCode RETURN doc_join)' ,
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
