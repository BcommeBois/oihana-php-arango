<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\authorizeRelationFields;

/**
 * Unit coverage for {@see authorizeRelationFields()} — the field-side purge of
 * the definition-level permission gating (`AQL::REQUIRES` on an edge/join
 * definition). The helper removes the relation markers whose paired definition
 * is denied, so the `LET` walk (buildVariables) and the projection walk
 * (aqlFields) stay symmetric.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class AuthorizeRelationFieldsTest extends TestCase
{
    private function deny() :array
    {
        return [ Arango::AUTHORIZER => fn() :bool => false ] ;
    }

    private function grant() :array
    {
        return [ Arango::AUTHORIZER => fn() :bool => true ] ;
    }

    // ---------------------------------------------------------------- purge per marker type

    public function testDeniedEdgeMarkersArePurged() :void
    {
        foreach ( [ Filter::EDGE , Filter::EDGES , Filter::EDGES_COUNT ] as $filter )
        {
            $out = authorizeRelationFields
            (
                [ 'name' => [] , 'roles' => [ Field::FILTER => $filter ] ] ,
                [ 'roles' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'users.roles:list' ] ] ,
                [] ,
                $this->deny()
            ) ;

            $this->assertSame( [ 'name' ] , array_keys( $out ) , 'filter: ' . $filter ) ;
        }
    }

    public function testDeniedJoinMarkersArePurged() :void
    {
        foreach ( [ Filter::JOIN , Filter::JOINS ] as $filter )
        {
            $out = authorizeRelationFields
            (
                [ 'name' => [] , 'role' => [ Field::FILTER => $filter ] ] ,
                [] ,
                [ 'role' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'roles:read' ] ] ,
                $this->deny()
            ) ;

            $this->assertSame( [ 'name' ] , array_keys( $out ) , 'filter: ' . $filter ) ;
        }
    }

    // ---------------------------------------------------------------- kept cases

    public function testGrantedDefinitionKeepsTheMarker() :void
    {
        $out = authorizeRelationFields
        (
            [ 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            [ 'roles' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'users.roles:list' ] ] ,
            [] ,
            $this->grant()
        ) ;

        $this->assertSame( [ 'roles' ] , array_keys( $out ) ) ;
    }

    public function testDefinitionWithoutRequiresIsNotGated() :void
    {
        $out = authorizeRelationFields
        (
            [ 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            [ 'roles' => [ AQL::MODEL => 'x' ] ] ,
            [] ,
            $this->deny()
        ) ;

        $this->assertSame( [ 'roles' ] , array_keys( $out ) ) ;
    }

    public function testFailOpenWithoutAuthorizer() :void
    {
        $out = authorizeRelationFields
        (
            [ 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            [ 'roles' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'users.roles:list' ] ] ,
            [] ,
            []
        ) ;

        $this->assertSame( [ 'roles' ] , array_keys( $out ) ) ;
    }

    public function testNonRelationFieldsAreNeverTouched() :void
    {
        $fields = [ 'name' => [] , 'active' => [ Field::FILTER => Filter::BOOL ] ] ;

        $this->assertSame( $fields , authorizeRelationFields( $fields , [] , [] , $this->deny() ) ) ;
    }

    public function testMarkerWithoutResolvableDefinitionIsLeftUntouched() :void
    {
        // buildVariables skips a marker with no definition anyway — nothing to desynchronize
        $out = authorizeRelationFields
        (
            [ 'roles' => [ Field::FILTER => Filter::EDGES ] ] ,
            [] ,
            [] ,
            $this->deny()
        ) ;

        $this->assertSame( [ 'roles' ] , array_keys( $out ) ) ;
    }

    // ---------------------------------------------------------------- aliases & shapes

    public function testStringAliasFollowsItsTargetAuthorization() :void
    {
        $edges =
        [
            'alias' => 'roles' ,
            'roles' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'users.roles:list' ] ,
        ] ;

        $denied = authorizeRelationFields
        (
            [ 'alias' => [ Field::FILTER => Filter::EDGE ] ] ,
            $edges , [] , $this->deny()
        ) ;
        $this->assertSame( [] , $denied ) ;

        $granted = authorizeRelationFields
        (
            [ 'alias' => [ Field::FILTER => Filter::EDGE ] ] ,
            $edges , [] , $this->grant()
        ) ;
        $this->assertSame( [ 'alias' ] , array_keys( $granted ) ) ;
    }

    public function testEmptyAndNullFieldsPassThrough() :void
    {
        $this->assertNull( authorizeRelationFields( null , [] , [] , $this->deny() ) ) ;
        $this->assertSame( [] , authorizeRelationFields( [] , [] , [] , $this->deny() ) ) ;
    }

    public function testIdempotency() :void
    {
        $fields = [ 'name' => [] , 'roles' => [ Field::FILTER => Filter::EDGES ] ] ;
        $edges  = [ 'roles' => [ AQL::MODEL => 'x' , AQL::REQUIRES => 'users.roles:list' ] ] ;

        $once  = authorizeRelationFields( $fields , $edges , [] , $this->deny() ) ;
        $twice = authorizeRelationFields( $once   , $edges , [] , $this->deny() ) ;

        $this->assertSame( $once , $twice ) ;
    }
}
