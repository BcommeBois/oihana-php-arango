<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\normalizeSortable;

/**
 * Unit coverage for {@see normalizeSortable()} — folds the three `sortable`
 * notations (associative legacy, indexed shorthand, indexed alias) into the
 * canonical `urlKey => fieldPath` map, idempotently.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class NormalizeSortableTest extends TestCase
{
    public function testNullIsPreserved() :void
    {
        // Open mode (no whitelist) flows through untouched.
        $this->assertNull( normalizeSortable( null ) ) ;
    }

    public function testEmptyArrayStaysEmpty() :void
    {
        $this->assertSame( [] , normalizeSortable( [] ) ) ;
    }

    public function testAssociativeLegacyIsReturnedUntouched() :void
    {
        $input = [ 'created' => 'created' , 'name' => 'givenName' ] ;
        $this->assertSame( $input , normalizeSortable( $input ) ) ;
    }

    public function testAssociativeKeepsArrayFieldPath() :void
    {
        // A multi-segment field path (resolved later by compile()) is kept verbatim.
        $input = [ 'city' => [ 'address' , 'city' ] ] ;
        $this->assertSame( $input , normalizeSortable( $input ) ) ;
    }

    public function testIndexedShorthandMapsTokenToItself() :void
    {
        $this->assertSame
        (
            [ '_from' => '_from' , '_to' => '_to' , 'created' => 'created' ] ,
            normalizeSortable( [ '_from' , '_to' , 'created' ] )
        ) ;
    }

    public function testIndexedAliasMapsTokenToField() :void
    {
        $this->assertSame
        (
            [ 'name' => 'givenName' ] ,
            normalizeSortable( [ [ 'name' => 'givenName' ] ] )
        ) ;
    }

    public function testIndexedAliasKeepsArrayFieldPath() :void
    {
        // The field side of an alias may itself be a multi-segment path.
        $this->assertSame
        (
            [ 'city' => [ 'address' , 'city' ] ] ,
            normalizeSortable( [ [ 'city' => [ 'address' , 'city' ] ] ] )
        ) ;
    }

    public function testHybridShorthandAndAliasMixed() :void
    {
        $this->assertSame
        (
            [ 'name' => 'givenName' , '_to' => '_to' , 'created' => 'created' ] ,
            normalizeSortable( [ [ 'name' => 'givenName' ] , '_to' , 'created' ] )
        ) ;
    }

    public function testMultiPairIndexedArrayYieldsEveryAlias() :void
    {
        // A lenient indexed array carrying several pairs maps each one.
        $this->assertSame
        (
            [ 'name' => 'givenName' , 'city' => 'addressLocality' ] ,
            normalizeSortable( [ [ 'name' => 'givenName' , 'city' => 'addressLocality' ] ] )
        ) ;
    }

    public function testPureListIndexedElementIsDropped() :void
    {
        // No token (numeric keys only) => not an alias => contributes nothing.
        $this->assertSame( [] , normalizeSortable( [ [ 'address' , 'city' ] ] ) ) ;
    }

    public function testNonStringNonArrayIndexedValueIsIgnored() :void
    {
        // An indexed value that is neither a string nor an array adds nothing,
        // while a valid neighbour is still resolved.
        $this->assertSame
        (
            [ 'created' => 'created' ] ,
            normalizeSortable( [ 123 , 'created' , null ] )
        ) ;
    }

    public function testIsIdempotent() :void
    {
        $once  = normalizeSortable( [ [ 'name' => 'givenName' ] , '_to' , 'created' ] ) ;
        $twice = normalizeSortable( $once ) ;
        $this->assertSame( $once , $twice ) ;
    }
}
