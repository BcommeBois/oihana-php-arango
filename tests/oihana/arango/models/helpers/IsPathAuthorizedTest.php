<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;

use function oihana\arango\models\helpers\isPathAuthorized;

/**
 * Unit coverage for {@see isPathAuthorized()} — the depth-aware permission gate
 * that inherits `Field::REQUIRES` from the exact sub-field of a dotted path, shared
 * by the filter / facet / group surfaces.
 */
class IsPathAuthorizedTest extends TestCase
{
    // ------------------------------------------------------------------ short-circuits

    public function testNullFieldsMapIsAuthorized(): void
    {
        $this->assertTrue( isPathAuthorized( 'address.city' , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testAbsentRootIsAuthorized(): void
    {
        $this->assertTrue( isPathAuthorized( 'salary' , [ 'name' => true ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testScalarRootDefinitionIsAuthorized(): void
    {
        // A scalar / bool definition carries no REQUIRES → no gating.
        $this->assertTrue( isPathAuthorized( 'name' , [ 'name' => true ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    // ------------------------------------------------------ single segment = parity with isAttributeAuthorized

    public function testRootGatedDeniedByAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertFalse( isPathAuthorized( 'salary' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testRootGatedGrantedByAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertTrue( isPathAuthorized( 'salary' , $fields , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'hr:read' ] ) ) ;
    }

    public function testRootGatedFailsOpenWithoutAuthorizer(): void
    {
        $fields = [ 'salary' => [ Field::REQUIRES => 'hr:read' ] ] ;
        $this->assertTrue( isPathAuthorized( 'salary' , $fields , [] ) ) ;
    }

    // ------------------------------------------------------------------ deep path (Field::FIELDS)

    public function testDeepLeafDeniedIsRefused(): void
    {
        $fields = [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ;
        $this->assertFalse( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testDeepLeafGrantedIsAuthorized(): void
    {
        $fields = [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ;
        $this->assertTrue( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ) ) ;
    }

    public function testDeepIntermediateDeniedIsRefused(): void
    {
        // The lock is on the intermediate 'address', not on the leaf 'city'.
        $fields = [ 'address' => [ Field::REQUIRES => 'geo:read' , Field::FIELDS => [ 'city' => true ] ] ] ;
        $this->assertFalse( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testDeepUndeclaredSubFieldIsAuthorized(): void
    {
        // 'address' declares 'city' but not 'zip' → nothing to inherit for 'zip'.
        $fields = [ 'address' => [ Field::FIELDS => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ] ;
        $this->assertTrue( isPathAuthorized( 'address.zip' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testDeepPastScalarIntermediateIsAuthorized(): void
    {
        // 'name' is scalar → the walk cannot descend, nothing deeper to gate.
        $this->assertTrue( isPathAuthorized( 'name.first' , [ 'name' => true ] , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testDeepIntermediateWithoutSubFieldsIsAuthorized(): void
    {
        // 'address' is an (authorized) array but declares no sub-fields → the deeper
        // 'city' has nothing to inherit → authorized.
        $fields = [ 'address' => [ Field::REQUIRES => 'geo:read' ] ] ;
        $this->assertTrue( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ) ) ;
    }

    // ------------------------------------------------------------------ SKIN_FIELDS buckets (fail-closed union)

    public function testDeepLeafLockedInSkinBucketIsRefused(): void
    {
        // 'city' is locked only inside a SKIN_FIELDS bucket → still gated (union).
        $fields =
        [
            'address' =>
            [
                AQL::SKIN_FIELDS => [ 'full' => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ,
            ] ,
        ] ;
        $this->assertFalse( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    public function testDeepLeafViaSkinBucketGrantedIsAuthorized(): void
    {
        $fields =
        [
            'address' =>
            [
                AQL::SKIN_FIELDS => [ 'full' => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ] ,
            ] ,
        ] ;
        $this->assertTrue( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn( string $s ) => $s === 'geo:read' ] ) ) ;
    }

    public function testNonArraySkinBucketIsIgnored(): void
    {
        // A malformed (non-array) bucket is skipped; the valid bucket still gates.
        $fields =
        [
            'address' =>
            [
                AQL::SKIN_FIELDS =>
                [
                    '*'    => 'not-an-array' ,
                    'full' => [ 'city' => [ Field::REQUIRES => 'geo:read' ] ] ,
                ] ,
            ] ,
        ] ;
        $this->assertFalse( isPathAuthorized( 'address.city' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }

    // ------------------------------------------------------------------ array-expansion marker

    public function testArrayExpansionMarkerIsStripped(): void
    {
        $fields = [ 'contactPoint' => [ Field::FIELDS => [ 'email' => [ Field::REQUIRES => 'pii:read' ] ] ] ] ;
        $this->assertFalse( isPathAuthorized( 'contactPoint[*].email' , $fields , [ Arango::AUTHORIZER => fn() => false ] ) ) ;
    }
}
