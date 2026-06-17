<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\traits\aql\FieldsTrait;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Bare host exposing {@see FieldsTrait} for isolated testing.
 */
class FieldsTraitStub
{
    use FieldsTrait ;
}

/**
 * Characterization coverage for the pure (no-I/O) surface of {@see FieldsTrait}:
 * - {@see FieldsTrait::initializeFields()}
 * - {@see FieldsTrait::prepareQueryFields()} and its private helpers
 *   (filterFieldsBySkin / generateUniqueKey / normalizeFieldDefinition)
 * - {@see FieldsTrait::returnFields()} on the container-free branches only
 *   (the `*` projection without edges/joins and the explicit field-list path).
 *
 * The edges/joins and prepared-queryFields branches of returnFields() reach into
 * the DI container (buildVariables/buildEdgesVariables/aqlFields) and belong to
 * the Tier-2 mock-transport suite; they are deliberately not exercised here.
 */
class FieldsTraitTest extends TestCase
{
    private function stub() :FieldsTraitStub
    {
        return new FieldsTraitStub() ;
    }

    // ---------------------------------------------------------------- initializeFields

    public function testInitializeFieldsSetsDefinitionsAndReturnsSelf() :void
    {
        $stub = $this->stub() ;
        $result = $stub->initializeFields( [ 'fields' => [ 'a' => Filter::BOOL ] ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertSame( [ 'a' => Filter::BOOL ] , $stub->fields ) ;
    }

    public function testInitializeFieldsWithEmptyArrayKeepsExisting() :void
    {
        $stub = $this->stub() ;
        $stub->initializeFields( [ 'fields' => [ 'a' => Filter::BOOL ] ] ) ;
        $stub->initializeFields( [] ) ;

        $this->assertSame( [ 'a' => Filter::BOOL ] , $stub->fields ) ;
    }

    // ---------------------------------------------------------------- prepareQueryFields : empties

    public function testPrepareQueryFieldsEmptyArrayReturnsNull() :void
    {
        $this->assertNull( $this->stub()->prepareQueryFields( [] ) ) ;
    }

    public function testPrepareQueryFieldsNullFallsBackToEmptyInstanceFields() :void
    {
        $this->assertNull( $this->stub()->prepareQueryFields( null ) ) ;
    }

    // ---------------------------------------------------------------- prepareQueryFields : normalization

    public function testStringFilterIsNormalizedWithUniqueEqualToKey() :void
    {
        $this->assertSame
        (
            [ 'active' => [ Field::FILTER => Filter::BOOL , Field::UNIQUE => 'active' ] ] ,
            $this->stub()->prepareQueryFields( [ 'active' => Filter::BOOL ] ) ,
        ) ;
    }

    public function testNullValueProducesNoFilterKey() :void
    {
        // Filter::DEFAULT is null, so clean(NULLS) strips the filter entry entirely.
        $this->assertSame
        (
            [ 'name' => [ Field::UNIQUE => 'name' ] ] ,
            $this->stub()->prepareQueryFields( [ 'name' => null ] ) ,
        ) ;
    }

    public function testArrayOptionsAreNormalized() :void
    {
        $this->assertSame
        (
            [ 'modified' => [ Field::FILTER => Filter::DATETIME , Field::UNIQUE => 'modified' ] ] ,
            $this->stub()->prepareQueryFields( [ 'modified' => [ Field::FILTER => Filter::DATETIME ] ] ) ,
        ) ;
    }

    public function testKnownOptionKeysArePassedThrough() :void
    {
        $out = $this->stub()->prepareQueryFields
        ([
            'x' =>
            [
                Field::FILTER   => Filter::BOOL ,
                Field::NAME     => 'n' ,
                Field::QUOTED   => true ,
                Field::FORMAT   => 'fmt' ,
                Field::PATH     => 'p' ,
                Field::PROPERTY => 'prop' ,
                Field::REQUIRES => 'r' ,
            ],
        ]) ;

        $x = $out[ 'x' ] ;

        $this->assertSame( Filter::BOOL , $x[ Field::FILTER   ] ) ;
        $this->assertSame( 'fmt'        , $x[ Field::FORMAT   ] ) ;
        $this->assertSame( 'n'          , $x[ Field::NAME     ] ) ;
        $this->assertSame( 'p'          , $x[ Field::PATH     ] ) ;
        $this->assertSame( 'prop'       , $x[ Field::PROPERTY ] ) ;
        $this->assertTrue( $x[ Field::QUOTED ] ) ;
        $this->assertSame( 'r'          , $x[ Field::REQUIRES ] ) ;
        $this->assertSame( 'x'          , $x[ Field::UNIQUE   ] ) ;
    }

    public function testWhenAndElseOptionsArePreserved() :void
    {
        // Field::WHEN / Field::ELSE must survive the model → query normalization,
        // otherwise the conditional projection is silently lost.
        $out = $this->stub()->prepareQueryFields
        ([
            'price' =>
            [
                Field::WHEN => [ 'visibility' , 'public' ] ,
                Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
            ],
        ]) ;

        $price = $out[ 'price' ] ;

        $this->assertSame( [ 'visibility' , 'public' ] , $price[ Field::WHEN ] ) ;
        $this->assertSame( [ Field::PROPERTY => 'basePrice' ] , $price[ Field::ELSE ] ) ;
    }

    public function testFieldDefaultOptionLandsUnderTheNullKey() :void
    {
        // Field::DEFAULT === null, so [ Field::DEFAULT => 'd' ] becomes [ '' => 'd' ]
        // and survives clean(NULLS) (which strips null *values*, not null keys).
        $out = $this->stub()->prepareQueryFields( [ 'x' => [ Field::FILTER => Filter::BOOL , Field::DEFAULT => 'd' ] ] ) ;

        $this->assertArrayHasKey( '' , $out[ 'x' ] ) ;
        $this->assertSame( 'd' , $out[ 'x' ][ '' ] ) ;
        $this->assertSame( 'd' , $out[ 'x' ][ Field::DEFAULT ] ) ; // read back via the same null constant
    }

    // ---------------------------------------------------------------- prepareQueryFields : skin filtering

    private function skinFields() :array
    {
        return
        [
            'name'   => Filter::DEFAULT ,
            'secret' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
            'main'   => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => 'main,full' ] ,
        ] ;
    }

    public function testSkinNullKeepsAllFields() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->skinFields() ) ;
        $this->assertSame( [ 'name' , 'secret' , 'main' ] , array_keys( $out ) ) ;
    }

    public function testSkinFullMatchesArrayAndCsvSkins() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->skinFields() , 'full' ) ;
        $this->assertSame( [ 'name' , 'secret' , 'main' ] , array_keys( $out ) ) ;
    }

    public function testSkinMainMatchesCsvSkinOnly() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->skinFields() , 'main' ) ;
        $this->assertSame( [ 'name' , 'main' ] , array_keys( $out ) ) ;
    }

    public function testUnknownSkinKeepsOnlyFieldsWithoutSkinRestriction() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->skinFields() , 'other' ) ;
        $this->assertSame( [ 'name' ] , array_keys( $out ) ) ;
    }

    // ---------------------------------------------------------------- prepareQueryFields : $in filtering

    private function inFields() :array
    {
        return [ 'a' => Filter::BOOL , 'b' => Filter::BOOL , 'c' => Filter::BOOL ] ;
    }

    public function testInArrayKeepsListedKeys() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->inFields() , null , null , [ 'a' , 'c' ] ) ;
        $this->assertSame( [ 'a' , 'c' ] , array_keys( $out ) ) ;
    }

    public function testInSingleStringKeyIsKept() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->inFields() , null , null , 'a' ) ;
        $this->assertSame( [ 'a' ] , array_keys( $out ) ) ;
    }

    /**
     * A comma-separated `$in` string is split (and trimmed) into individual keys,
     * keeping each matching field — as the docblock promises.
     */
    public function testInCommaSeparatedStringSelectsEachKey() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->inFields() , null , null , 'a,b' ) ;
        $this->assertSame( [ 'a' , 'b' ] , array_keys( $out ) ) ;
    }

    public function testInCommaSeparatedStringTrimsSurroundingSpaces() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->inFields() , null , null , ' a , c ' ) ;
        $this->assertSame( [ 'a' , 'c' ] , array_keys( $out ) ) ;
    }

    public function testInEmptyStringReturnsNull() :void
    {
        $this->assertNull( $this->stub()->prepareQueryFields( $this->inFields() , null , null , '' ) ) ;
    }

    public function testInEmptyArrayReturnsNull() :void
    {
        $this->assertNull( $this->stub()->prepareQueryFields( $this->inFields() , null , null , [] ) ) ;
    }

    public function testInWithNoMatchReturnsNull() :void
    {
        $this->assertNull( $this->stub()->prepareQueryFields( $this->inFields() , null , null , [ 'zzz' ] ) ) ;
    }

    // ---------------------------------------------------------------- prepareQueryFields : subfields

    public function testDocumentSubFieldsAreNormalizedRecursively() :void
    {
        $out = $this->stub()->prepareQueryFields
        ([
            'addr' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS => [ 'street' => Filter::DEFAULT , 'zip' => Filter::BOOL ] ,
            ],
        ]) ;

        $this->assertSame
        (
            [
                'addr' =>
                [
                    Field::FILTER => Filter::DOCUMENT ,
                    Field::FIELDS =>
                    [
                        'street' => [ Field::UNIQUE => 'street' ] ,
                        'zip'    => [ Field::FILTER => Filter::BOOL , Field::UNIQUE => 'zip' ] ,
                    ],
                ],
            ],
            $out ,
        ) ;
    }

    public function testMapSubFieldsPropagateJoinsAndEdges() :void
    {
        $out = $this->stub()->prepareQueryFields
        ([
            'm' =>
            [
                Field::FILTER => Filter::MAP ,
                Field::FIELDS => [ 'k' => Filter::DEFAULT ] ,
                Field::JOINS  => [ 'j1' => 'x' ] ,
                Field::EDGES  => [ 'e1' => 'y' ] ,
            ],
        ]) ;

        $this->assertSame
        (
            [
                'm' =>
                [
                    Field::FILTER => Filter::MAP ,
                    Field::FIELDS => [ 'k' => [ Field::UNIQUE => 'k' ] ] ,
                    Field::JOINS  => [ 'j1' => 'x' ] ,
                    Field::EDGES  => [ 'e1' => 'y' ] ,
                ],
            ],
            $out ,
        ) ;
    }

    public function testDocumentWithoutSubFieldsFallsBackToUniqueKey() :void
    {
        $out = $this->stub()->prepareQueryFields( [ 'addr' => [ Field::FILTER => Filter::DOCUMENT ] ] ) ;

        $this->assertSame
        (
            [ 'addr' => [ Field::FILTER => Filter::DOCUMENT , Field::UNIQUE => 'addr' ] ] ,
            $out ,
        ) ;
    }

    // ---------------------------------------------------------------- prepareQueryFields : unique keys (randomized)

    public function testEdgeFilterGeneratesUniqueKeyWithEdgeSuffix() :void
    {
        $out = $this->stub()->prepareQueryFields( [ 'permissions' => [ Field::FILTER => Filter::EDGES ] ] ) ;
        $this->assertMatchesRegularExpression( '/^permissions_e\d+$/' , $out[ 'permissions' ][ Field::UNIQUE ] ) ;
    }

    public function testJoinFilterGeneratesUniqueKeyWithJoinSuffix() :void
    {
        $out = $this->stub()->prepareQueryFields( [ 'rel' => [ Field::FILTER => Filter::JOINS ] ] ) ;
        $this->assertMatchesRegularExpression( '/^rel_j\d+$/' , $out[ 'rel' ][ Field::UNIQUE ] ) ;
    }

    public function testUniqueNameFilterGeneratesUniqueKeyWithUniqueSuffix() :void
    {
        $out = $this->stub()->prepareQueryFields( [ 'nm' => [ Field::FILTER => Filter::UNIQUE_NAME ] ] ) ;
        $this->assertMatchesRegularExpression( '/^nm_u\d+$/' , $out[ 'nm' ][ Field::UNIQUE ] ) ;
    }

    public function testParentKeyPrefixesTheGeneratedUniqueKey() :void
    {
        $out = $this->stub()->prepareQueryFields( [ 'permissions' => [ Field::FILTER => Filter::EDGES ] ] , null , 'parent' ) ;
        $this->assertMatchesRegularExpression( '/^parent_permissions_e\d+$/' , $out[ 'permissions' ][ Field::UNIQUE ] ) ;
    }

    // ---------------------------------------------------------------- returnFields : container-free branches

    public function testReturnFieldsDefaultProjectsAllWithDocReference() :void
    {
        $this->assertSame( 'RETURN doc' , $this->stub()->returnFields() ) ;
    }

    public function testReturnFieldsDefaultHonorsCustomDocReference() :void
    {
        $this->assertSame( 'RETURN x' , $this->stub()->returnFields( [ Arango::DOC_REF => 'x' ] ) ) ;
    }

    public function testReturnFieldsBuildsDocumentFromCommaSeparatedString() :void
    {
        $this->assertSame
        (
            'RETURN {name:doc.name, age:doc.age}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => 'name,age' ] ) ,
        ) ;
    }

    public function testReturnFieldsTrimsFieldNames() :void
    {
        $this->assertSame
        (
            'RETURN {name:doc.name, age:doc.age}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => '  name , age  ' ] ) ,
        ) ;
    }

    public function testReturnFieldsBuildsDocumentFromArray() :void
    {
        $this->assertSame
        (
            'RETURN {name:doc.name, age:doc.age}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => [ 'name' , 'age' ] ] ) ,
        ) ;
    }

    public function testReturnFieldsSingleField() :void
    {
        $this->assertSame( 'RETURN {name:doc.name}' , $this->stub()->returnFields( [ Arango::FIELDS => 'name' ] ) ) ;
    }

    /**
     * The explicit field-list branch honors the supplied docRef, like the
     * '*' and prepared-queryFields branches.
     */
    public function testReturnFieldsExplicitListHonorsCustomDocReference() :void
    {
        $this->assertSame
        (
            'RETURN {name:p.name}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => 'name' , Arango::DOC_REF => 'p' ] ) ,
        ) ;
    }

    public function testReturnFieldsExplicitListWithCustomDocReferenceMultipleFields() :void
    {
        $this->assertSame
        (
            'RETURN {name:p.name, age:p.age}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => 'name,age' , Arango::DOC_REF => 'p' ] ) ,
        ) ;
    }

    public function testReturnFieldsAsVariableEmitsLetWithDefaultName() :void
    {
        $variables = [] ;
        $this->assertSame
        (
            'LET result = {name:doc.name}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => 'name' ] , $variables , true ) ,
        ) ;
    }

    public function testReturnFieldsAsVariableHonorsCustomVarName() :void
    {
        $variables = [] ;
        $this->assertSame
        (
            'LET out = {name:doc.name}' ,
            $this->stub()->returnFields( [ Arango::FIELDS => 'name' , Arango::VAR_NAME => 'out' ] , $variables , true ) ,
        ) ;
    }

    public function testReturnFieldsLeavesVariablesUntouchedOnPureBranches() :void
    {
        $variables = [ 'pre' => 'existing' ] ;
        $this->stub()->returnFields( [ Arango::FIELDS => 'name' ] , $variables ) ;
        $this->assertSame( [ 'pre' => 'existing' ] , $variables ) ;
    }

    public function testReturnFieldsThrowsOnInvalidFieldsType() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'got int' ) ;
        $this->stub()->returnFields( [ Arango::FIELDS => 123 ] ) ;
    }
}
