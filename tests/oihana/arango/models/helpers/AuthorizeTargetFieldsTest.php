<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use function oihana\arango\models\helpers\authorizeTargetFields;

/**
 * Unit coverage for {@see authorizeTargetFields()} — the T6 gate that re-applies
 * a target model's `Field::REQUIRES` to a relation's ad-hoc projection.
 */
final class AuthorizeTargetFieldsTest extends TestCase
{
    private function target() :MockDocuments
    {
        $target = new MockDocuments( 'people' ) ;
        $target->fields = [ 'name' => [] , 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        return $target ;
    }

    public function testAdHocFieldMaskedOnTargetIsDroppedWhenDenied() :void
    {
        // The definition re-projects `salary` WITHOUT re-declaring its permission.
        $adhoc  = [ 'name' => [] , 'salary' => [] ] ;
        $result = authorizeTargetFields( $adhoc , $this->target() , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertArrayHasKey   ( 'name'   , $result ) ;
        $this->assertArrayNotHasKey( 'salary' , $result ) ;
    }

    public function testAdHocFieldKeptWhenGranted() :void
    {
        $adhoc  = [ 'name' => [] , 'salary' => [] ] ;
        $result = authorizeTargetFields( $adhoc , $this->target() , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ) ;

        $this->assertArrayHasKey( 'salary' , $result ) ;
    }

    public function testAliasIsGatedOnItsSourceAttributeNotTheOutputKey() :void
    {
        // Output key `label`, source attribute `salary` (masked) → dropped on the source.
        $adhoc  = [ 'label' => [ Field::NAME => 'salary' ] ] ;
        $result = authorizeTargetFields( $adhoc , $this->target() , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertArrayNotHasKey( 'label' , $result ) ;
    }

    public function testFieldWithoutTargetRequirementIsKept() :void
    {
        $adhoc  = [ 'name' => [] ] ;
        $result = authorizeTargetFields( $adhoc , $this->target() , [ Arango::AUTHORIZER => fn() => false ] ) ;

        $this->assertArrayHasKey( 'name' , $result ) ;
    }

    public function testFailsOpenWithoutAuthorizer() :void
    {
        $adhoc  = [ 'salary' => [] ] ;
        $result = authorizeTargetFields( $adhoc , $this->target() , [] ) ;

        $this->assertArrayHasKey( 'salary' , $result ) ;
    }

    public function testNullTargetLeavesFieldsUntouched() :void
    {
        $adhoc = [ 'salary' => [] ] ;
        $this->assertSame( $adhoc , authorizeTargetFields( $adhoc , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testEmptyOrNullFieldsAreReturnedAsIs() :void
    {
        $this->assertSame( [] , authorizeTargetFields( [] , $this->target() , [] ) ) ;
        $this->assertNull( authorizeTargetFields( null , $this->target() , [] ) ) ;
    }
}
