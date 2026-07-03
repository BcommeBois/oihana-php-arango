<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\edges\mocks\MockEdges;

use function oihana\arango\models\helpers\edges\buildEdgesVariables;

/**
 * Characterization coverage for {@see buildEdgesVariables()} — iterates a map of
 * edge definitions, building one variable per entry (skipping the `resolve`
 * meta-key and resolving string aliases).
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class BuildEdgesVariablesTest extends TestCase
{
    public function testReturnsEmptyStringForNoDefinitions() :void
    {
        $variables = [] ;

        $this->assertSame( '' , buildEdgesVariables( $variables ) ) ;
        $this->assertSame( [] , $variables ) ;
    }

    public function testBuildsOneVariablePerDefinitionAndFillsTheReference() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $variables = [] ;
        $result = buildEdgesVariables( $variables , [ 'roles' => [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] ] ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertSame( $variables[ 0 ] , $result ) ;
        $this->assertStringStartsWith( 'LET roles = (' , $result ) ;
    }

    public function testSkipsADefinitionDeniedByItsRequires() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $variables = [] ;
        $result = buildEdgesVariables( $variables ,
        [
            'roles' => [ AQL::MODEL => $edges , AQL::REQUIRES => 'users.roles:list' , Arango::PROPERTY => 'name' ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertSame( [] , $variables ) ;
    }

    public function testSkipsTheResolveMetaKey() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $variables = [] ;
        buildEdgesVariables( $variables ,
        [
            AQL::RESOLVE => [ 'whatever' => true ] ,
            'roles'      => [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] ,
        ] ) ;

        $this->assertCount( 1 , $variables ) ; // only 'roles', resolve skipped
    }

    public function testResolvesStringAliasToAnotherDefinition() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $variables = [] ;
        buildEdgesVariables( $variables ,
        [
            'roles' => [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] ,
            'alias' => 'roles' , // string → resolved to the 'roles' definition
        ] ) ;

        $this->assertCount( 2 , $variables ) ;
    }

    public function testSkipsUnresolvableStringAlias() :void
    {
        $edges = new MockEdges( 'user_has_roles' ) ;

        $variables = [] ;
        buildEdgesVariables( $variables ,
        [
            'roles' => [ AQL::MODEL => $edges , Arango::PROPERTY => 'name' ] ,
            'ghost' => 'missing' , // alias to a non-existent definition → skipped
        ] ) ;

        $this->assertCount( 1 , $variables ) ;
    }
}
