<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use function oihana\arango\models\helpers\isAttributeAuthorized;

/**
 * Unit coverage for {@see isAttributeAuthorized()} — the projection-inherited
 * permission gate shared by the filter / facet / group surfaces.
 */
class IsAttributeAuthorizedTest extends TestCase
{
    public function testNullFieldsMapIsAuthorized(): void
    {
        $this->assertTrue( isAttributeAuthorized( 'salary' , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testAttributeAbsentFromFieldsIsAuthorized(): void
    {
        $this->assertTrue( isAttributeAuthorized( 'salary' , [ 'name' => true ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testNonArrayFieldDefinitionIsAuthorized(): void
    {
        // A scalar/bool field definition carries no REQUIRES → no gating.
        $this->assertTrue( isAttributeAuthorized( 'name' , [ 'name' => true ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testFieldWithoutRequiresIsAuthorized(): void
    {
        $this->assertTrue( isAttributeAuthorized( 'salary' , [ 'salary' => [ 'format' => 'x' ] ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testGatedFieldDeniedByAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertFalse( isAttributeAuthorized( 'salary' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testGatedFieldGrantedByAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertTrue( isAttributeAuthorized( 'salary' , $fields , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ) ) ;
    }

    public function testGatedFieldFailsOpenWithoutAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertTrue( isAttributeAuthorized( 'salary' , $fields , [] ) ) ;
    }
}
