<?php

namespace tests\oihana\arango\models\helpers;

use UnexpectedValueException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\buildPolymorphicRelationVariable;

/**
 * Coverage for {@see buildPolymorphicRelationVariable()} — the shared assembler
 * (validation + per-branch gating + `FALLBACK` + `APPEND` combine) behind the
 * polymorphic join and edge builders.
 *
 * A trivial stub `$buildBranch` (`"(<tag> <guard>)"`) isolates the shared logic
 * from any relation-specific sub-query building, so the assertions pin the
 * guards, the ordering and the combine exactly.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class BuildPolymorphicRelationVariableTest extends TestCase
{
    /**
     * @param string $type A short discriminator value, also the branch tag.
     *
     * @return array
     */
    private function branch( string $type ) : array
    {
        return [ 'tag' => strtoupper( $type ) ] ;
    }

    /**
     * A stub branch builder rendering "(<tag> <guard>)" so guard + order are visible.
     *
     * @return callable
     */
    private function stub() : callable
    {
        return fn( array $branch , string $guard ) : string => '(' . ( $branch[ 'tag' ] ?? '?' ) . ' ' . $guard . ')' ;
    }

    // ---- validation -----------------------------------------------------

    public function testThrowsWhenNameIsEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicRelationVariable( '' , [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [ 'w' => $this->branch( 'w' ) ] ] , AQL::DOC , [] , $this->stub() ) ;
    }

    public function testThrowsWhenMapMissingOrEmpty() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicRelationVariable( 'rel' , [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [] ] , AQL::DOC , [] , $this->stub() ) ;
    }

    public function testThrowsWhenDiscriminatorMissing() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicRelationVariable( 'rel' , [ Arango::MAP => [ 'w' => $this->branch( 'w' ) ] ] , AQL::DOC , [] , $this->stub() ) ;
    }

    public function testThrowsWhenBranchIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicRelationVariable( 'rel' , [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [ 'w' => 'nope' ] ] , AQL::DOC , [] , $this->stub() ) ;
    }

    public function testThrowsWhenFallbackIsNotAnArray() :void
    {
        $this->expectException( UnexpectedValueException::class ) ;

        buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [ 'w' => $this->branch( 'w' ) ] , Arango::FALLBACK => 'nope' ] ,
            AQL::DOC , [] , $this->stub()
        ) ;
    }

    // ---- combine + guards ----------------------------------------------

    public function testTwoBranchesAppendWithGuards() :void
    {
        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [ 'w' => $this->branch( 'w' ) , 'c' => $this->branch( 'c' ) ] ] ,
            AQL::DOC , [] , $this->stub()
        ) ;

        $this->assertSame( 'LET rel = APPEND((W doc.kind == "w"),(C doc.kind == "c"))' , $result ) ;
    }

    public function testSingleBranchHasNoAppend() :void
    {
        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => [ 'w' => $this->branch( 'w' ) ] ] ,
            AQL::DOC , [] , $this->stub()
        ) ;

        $this->assertSame( 'LET rel = (W doc.kind == "w")' , $result ) ;
    }

    public function testUniqueOverridesTheVariableNameAndRefIsHonored() :void
    {
        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::UNIQUE => 'z' , Arango::DISCRIMINATOR => 'selector.kind' , Arango::MAP => [ 'w' => $this->branch( 'w' ) ] ] ,
            'parent' , [] , $this->stub()
        ) ;

        $this->assertSame( 'LET z = (W parent.selector.kind == "w")' , $result ) ;
    }

    // ---- per-branch gating + fallback ----------------------------------

    public function testDeniedBranchIsDroppedFromAppend() :void
    {
        $map = [ 'w' => [ 'tag' => 'W' , AQL::REQUIRES => 'w:read' ] , 'c' => $this->branch( 'c' ) ] ;

        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => $map ] ,
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn( string $s ) => $s !== 'w:read' ] ,
            $this->stub()
        ) ;

        $this->assertSame( 'LET rel = (C doc.kind == "c")' , $result ) ;
    }

    public function testAllBranchesDeniedEmitsEmptyArray() :void
    {
        $map = [ 'w' => [ 'tag' => 'W' , AQL::REQUIRES => 'x' ] , 'c' => [ 'tag' => 'C' , AQL::REQUIRES => 'y' ] ] ;

        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => $map ] ,
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn() => false ] ,
            $this->stub()
        ) ;

        $this->assertSame( 'LET rel = []' , $result ) ;
    }

    public function testFallbackIsGuardedByNotInKnownTypes() :void
    {
        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [
                Arango::DISCRIMINATOR => 'kind' ,
                Arango::MAP           => [ 'w' => $this->branch( 'w' ) , 'c' => $this->branch( 'c' ) ] ,
                Arango::FALLBACK      => [ 'tag' => 'F' ] ,
            ] ,
            AQL::DOC , [] , $this->stub()
        ) ;

        $this->assertSame
        (
            'LET rel = APPEND(APPEND((W doc.kind == "w"),(C doc.kind == "c")),(F doc.kind NOT IN ["w","c"]))' ,
            $result
        ) ;
    }

    public function testFallbackDeniedIsDropped() :void
    {
        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [
                Arango::DISCRIMINATOR => 'kind' ,
                Arango::MAP           => [ 'w' => $this->branch( 'w' ) ] ,
                Arango::FALLBACK      => [ 'tag' => 'F' , AQL::REQUIRES => 'f:read' ] ,
            ] ,
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn() => false ] ,
            $this->stub()
        ) ;

        $this->assertSame( 'LET rel = (W doc.kind == "w")' , $result ) ;
    }

    public function testDeniedBranchTypeStaysExcludedFromFallback() :void
    {
        $map = [ 'w' => [ 'tag' => 'W' , AQL::REQUIRES => 'w:read' ] , 'c' => $this->branch( 'c' ) ] ;

        $result = buildPolymorphicRelationVariable
        (
            'rel' ,
            [ Arango::DISCRIMINATOR => 'kind' , Arango::MAP => $map , Arango::FALLBACK => [ 'tag' => 'F' ] ] ,
            AQL::DOC ,
            [ Arango::AUTHORIZER => fn( string $s ) => $s !== 'w:read' ] ,
            $this->stub()
        ) ;

        // "w" is dropped as a branch but STILL excluded from the fallback guard.
        $this->assertSame
        (
            'LET rel = APPEND((C doc.kind == "c"),(F doc.kind NOT IN ["w","c"]))' ,
            $result
        ) ;
    }
}
