<?php

namespace tests\oihana\arango\models\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\prepareRelationDefinition;

/**
 * Coverage for {@see prepareRelationDefinition()} — the shared preamble that
 * resolves (with shortcut dereference), permission-gates and stamps a relation
 * definition (edge or join) before it reaches a relation builder.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class PrepareRelationDefinitionTest extends TestCase
{
    public function testResolvesDirectDefinitionAndStampsUnique() :void
    {
        $result = prepareRelationDefinition( [ 'role' => [ AQL::MODEL => 'm' ] ] , 'role' , [] , 'r' , [] ) ;

        $this->assertSame( [ AQL::MODEL => 'm' , Field::UNIQUE => 'r' ] , $result ) ;
    }

    public function testResolvesStringShortcutToAnotherEntry() :void
    {
        $registry = [ 'alias' => 'role' , 'role' => [ AQL::MODEL => 'm' ] ] ;

        $result = prepareRelationDefinition( $registry , 'alias' , [] , 'r' , [] ) ;

        $this->assertSame( [ AQL::MODEL => 'm' , Field::UNIQUE => 'r' ] , $result ) ;
    }

    public function testStampsANullUnique() :void
    {
        $result = prepareRelationDefinition( [ 'role' => [ AQL::MODEL => 'm' ] ] , 'role' , [] , null , [] ) ;

        $this->assertSame( [ AQL::MODEL => 'm' , Field::UNIQUE => null ] , $result ) ;
    }

    public function testReturnsNullWhenKeyIsMissing() :void
    {
        $this->assertNull( prepareRelationDefinition( [] , 'role' , [] , 'r' , [] ) ) ;
    }

    public function testReturnsNullWhenRegistryIsNull() :void
    {
        $this->assertNull( prepareRelationDefinition( null , 'role' , [] , 'r' , [] ) ) ;
    }

    public function testReturnsNullWhenShortcutDoesNotResolve() :void
    {
        $this->assertNull( prepareRelationDefinition( [ 'alias' => 'missing' ] , 'alias' , [] , 'r' , [] ) ) ;
    }

    public function testReturnsNullWhenFieldLevelGateDenies() :void
    {
        $result = prepareRelationDefinition
        (
            [ 'role' => [ AQL::MODEL => 'm' ] ] ,
            'role' ,
            [ Field::REQUIRES => 'field.subject' ] ,
            'r' ,
            [ Arango::AUTHORIZER => fn() => false ]
        ) ;

        $this->assertNull( $result ) ;
    }

    public function testReturnsNullWhenDefinitionLevelGateDenies() :void
    {
        $result = prepareRelationDefinition
        (
            [ 'role' => [ AQL::MODEL => 'm' , AQL::REQUIRES => 'definition.subject' ] ] ,
            'role' ,
            [] , // no field-level gate
            'r' ,
            [ Arango::AUTHORIZER => fn() => false ]
        ) ;

        $this->assertNull( $result ) ;
    }
}
