<?php

namespace tests\oihana\arango\models\helpers\joins;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use tests\oihana\arango\models\traits\documents\mocks\MockDocuments;

use function oihana\arango\models\helpers\joins\buildPolymorphicJoinVariable;

/**
 * Coverage for {@see buildPolymorphicJoinVariable()} — builds a
 * `LET name = APPEND( ( … ) , ( … ) )` polymorphic join whose branch
 * collection is chosen by a discriminator field of the parent document.
 *
 * The join loop ref is random (`doc_join_<n>`), normalized to `doc_join`
 * before the exact assertions.
 *
 * @package tests\oihana\arango\models\helpers\joins
 * @author  Marc Alcaraz
 */
final class BuildPolymorphicJoinVariableTest extends TestCase
{
    public function testThrowsWhenNameIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicJoinVariable( '' ,
        [
            Arango::DISCRIMINATOR => 'type' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ]) ;
    }

    public function testThrowsWhenMapMissingOrEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicJoinVariable( 'area' , [ Arango::DISCRIMINATOR => 'type' , Arango::MAP => [] ] ) ;
    }

    public function testThrowsWhenDiscriminatorMissing() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::MAP => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ]) ;
    }

    public function testThrowsWhenBranchIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'type' ,
            Arango::MAP           => [ 'W' => 'not-a-branch' ] ,
        ]) ;
    }

    public function testTwoBranchesBuildAppendWithGuards() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) ] ,
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) ] ,
            ] ,
        ]) ) ;

        $this->assertSame
        (
            'LET area = APPEND(' .
            '(FOR doc_join IN warehouses FILTER doc_join._key == doc.selector.areaServed ' .
            '&& doc.selector.areaScope == "W" RETURN doc_join),' .
            '(FOR doc_join IN subsidiaries FILTER doc_join._key == doc.selector.areaServed ' .
            '&& doc.selector.areaScope == "C" RETURN doc_join))' ,
            $result
        ) ;
    }

    public function testSingleBranchHasNoAppend() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ]) ) ;

        $this->assertSame
        (
            'LET area = (FOR doc_join IN warehouses FILTER doc_join._key == doc.selector.areaServed ' .
            '&& doc.selector.areaScope == "W" RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testKeyPathDefaultsToNameWhenNoProperty() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'type' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ]) ) ;

        $this->assertStringContainsString( 'FILTER doc_join._key == doc.area && doc.type == "W"' , $result ) ;
    }

    public function testUniqueOverridesTheVariableName() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::UNIQUE        => 'zone' ,
            Arango::DISCRIMINATOR => 'type' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ]) ) ;

        $this->assertStringStartsWith( 'LET zone = ' , $result ) ;
    }

    public function testSharedKeyIsInheritedAndBranchCanOverride() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'type' ,
            Arango::KEY           => 'code' , // shared foreign key attribute
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) ] ,                        // inherits code
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) , Arango::KEY => 'ref' ] , // overrides
            ] ,
        ]) ) ;

        $this->assertStringContainsString( '(FOR doc_join IN warehouses FILTER doc_join.code == doc.area'  , $result ) ;
        $this->assertStringContainsString( '(FOR doc_join IN subsidiaries FILTER doc_join.ref == doc.area' , $result ) ;
    }

    public function testArrayFormUsesInFilterAndSort() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'areas' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
        ] , AQL::DOC , null , [] , true ) ) ;

        $this->assertSame
        (
            'LET areas = (FOR doc_join IN warehouses ' .
            'FILTER doc_join._key IN (IS_ARRAY(doc.selector.areaServed) ? doc.selector.areaServed : []) ' .
            '&& doc.selector.areaScope == "W" ' .
            'SORT doc_join._key DESC RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testRealSchemaUriTypeValuesAreQuoted() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           =>
            [
                'https://schema.oihana.xyz/PricingAreaScope#Warehouse' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) ] ,
                'https://schema.oihana.xyz/PricingAreaScope#Company'   => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) ] ,
            ] ,
        ]) ) ;

        $this->assertStringContainsString
        (
            'doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Warehouse"' ,
            $result
        ) ;
        $this->assertStringContainsString
        (
            'doc.selector.areaScope == "https://schema.oihana.xyz/PricingAreaScope#Company"' ,
            $result
        ) ;
    }

    // ---- per-branch gating + fallback (lot 2) ---------------------------

    public function testDeniedBranchIsDroppedFromAppend() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) , AQL::REQUIRES => 'warehouses:read' ] ,
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) ] ,
            ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn( string $s ) => $s !== 'warehouses:read' ] ) ) ;

        // W is denied → only C survives → no APPEND, and no warehouses branch.
        $this->assertSame
        (
            'LET area = (FOR doc_join IN subsidiaries FILTER doc_join._key == doc.selector.areaServed ' .
            '&& doc.selector.areaScope == "C" RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testAllBranchesDeniedEmitsEmptyArray() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) , AQL::REQUIRES => 'x' ] ,
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) , AQL::REQUIRES => 'y' ] ,
            ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;

        $this->assertSame( 'LET area = []' , $result ) ;
    }

    public function testFallbackBranchIsGuardedByNotInKnownTypes() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) ] ,
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) ] ,
            ] ,
            Arango::FALLBACK      => [ AQL::MODEL => new MockDocuments( 'regions' ) ] ,
        ]) ) ;

        $this->assertStringContainsString
        (
            '(FOR doc_join IN regions FILTER doc_join._key == doc.selector.areaServed ' .
            '&& doc.selector.areaScope NOT IN ["W","C"] RETURN doc_join)' ,
            $result
        ) ;
    }

    public function testFallbackBranchDeniedIsDropped() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
            Arango::FALLBACK      => [ AQL::MODEL => new MockDocuments( 'regions' ) , AQL::REQUIRES => 'regions:read' ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn() => false ] ) ) ;

        $this->assertStringNotContainsString( 'regions' , $result ) ;
        $this->assertStringContainsString( 'IN warehouses' , $result ) ;
    }

    public function testDeniedBranchTypeStaysExcludedFromFallback() :void
    {
        $result = $this->normalize( buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::MAP           =>
            [
                'W' => [ AQL::MODEL => new MockDocuments( 'warehouses'   ) , AQL::REQUIRES => 'warehouses:read' ] ,
                'C' => [ AQL::MODEL => new MockDocuments( 'subsidiaries' ) ] ,
            ] ,
            Arango::FALLBACK      => [ AQL::MODEL => new MockDocuments( 'regions' ) ] ,
        ] , AQL::DOC , null , [ Arango::AUTHORIZER => fn( string $s ) => $s !== 'warehouses:read' ] ) ) ;

        // The denied type "W" is dropped as a branch but STILL excluded from the
        // fallback guard, so a "W" document routes to nothing (never the fallback).
        $this->assertStringNotContainsString( 'IN warehouses' , $result ) ;
        $this->assertStringContainsString( 'NOT IN ["W","C"]' , $result ) ;
    }

    public function testThrowsWhenFallbackIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicJoinVariable( 'area' ,
        [
            Arango::DISCRIMINATOR => 'type' ,
            Arango::MAP           => [ 'W' => [ AQL::MODEL => new MockDocuments( 'warehouses' ) ] ] ,
            Arango::FALLBACK      => 'not-a-branch' ,
        ]) ;
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
