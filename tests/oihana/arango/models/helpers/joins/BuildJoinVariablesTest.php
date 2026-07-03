<?php

namespace tests\oihana\arango\models\helpers\joins;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use function oihana\arango\models\helpers\joins\buildJoinVariables;

/**
 * Characterization coverage for {@see buildJoinVariables()} — iterates a map of
 * join definitions, building one variable per entry (resolving string aliases).
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class BuildJoinVariablesTest extends TestCase
{
    public function testReturnsEmptyStringForNoDefinitions() :void
    {
        $variables = [] ;

        $this->assertSame( '' , buildJoinVariables( $variables ) ) ;
        $this->assertSame( [] , $variables ) ;
    }

    public function testBuildsOneVariablePerDefinitionAndFillsTheReference() :void
    {
        $variables = [] ;
        $result = buildJoinVariables( $variables , [ 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ] ) ;

        $this->assertCount( 1 , $variables ) ;
        $this->assertSame( $variables[ 0 ] , $result ) ;
        $this->assertStringStartsWith( 'LET role = (' , $result ) ;
    }

    public function testSkipsADefinitionDeniedByItsRequires() :void
    {
        $variables = [] ;
        $result = buildJoinVariables( $variables ,
        [
            'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) , AQL::REQUIRES => 'roles:read' ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertSame( [] , $variables ) ;
    }

    public function testResolvesStringAliasToAnotherDefinition() :void
    {
        $variables = [] ;
        buildJoinVariables( $variables ,
        [
            'role'  => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ,
            'alias' => 'role' , // string → resolved to the 'role' definition
        ] ) ;

        $this->assertCount( 2 , $variables ) ;
    }

    public function testSkipsUnresolvableStringAlias() :void
    {
        $variables = [] ;
        buildJoinVariables( $variables ,
        [
            'role'  => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ,
            'ghost' => 'missing' , // alias to a non-existent definition → skipped
        ] ) ;

        $this->assertCount( 1 , $variables ) ;
    }

    public function testHonorsCustomDocRef() :void
    {
        $variables = [] ;
        $result = buildJoinVariables( $variables , [ 'role' => [ AQL::MODEL => new MockDocuments( 'roles' ) ] ] , 'parent' ) ;

        $this->assertStringContainsString( '== parent.role' , $result ) ;
    }
}
