<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\db\binds\AqlBindReference;
use oihana\arango\models\traits\aql\FieldsTrait;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function oihana\arango\db\binds\aqlBindRef;

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

    /**
     * Without a requested skin, the sub-fields of a DOCUMENT are normalized
     * recursively and ALL kept — the deep skin filtering (a `Field::SKINS`
     * marker on a nested sub-field) only applies when a skin is requested ;
     * see the « nested skin filtering » section below.
     */
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

    /**
     * A Field::WHERE declared on a Filter::MAP field must survive the skin
     * normalization : normalizeFieldDefinition() rebuilds each definition from a
     * closed whitelist, and any key it omits is silently dropped before reaching
     * aqlFieldMap(). Field::WHERE is read only by the MAP renderer (it inserts the
     * FILTER restricting the projected elements), so if the whitelist forgets it,
     * the FILTER vanishes — and a Field::WHERE that references a runtime bind
     * (aqlBindRef) leaves that bind declared-but-unused, which ArangoDB rejects.
     * This locks the key in place through the full prepareQueryFields() chain,
     * including when a skin is requested.
     */
    public function testMapWhereSurvivesSkinNormalization() :void
    {
        $where = [ 'region' , 'in' , aqlBindRef( 'allowed' ) ] ;

        $out = $this->stub()->prepareQueryFields
        (
            [
                'items' =>
                [
                    Field::FILTER => Filter::MAP ,
                    Field::SKINS  => [ 'full' ] ,
                    Field::WHERE  => $where ,
                    Field::FIELDS => [ 'region' => Filter::DEFAULT ] ,
                ],
            ],
            'full'
        ) ;

        $this->assertArrayHasKey( 'items' , $out ) ;
        $this->assertArrayHasKey( Field::WHERE , $out[ 'items' ] ) ;
        $this->assertSame( $where , $out[ 'items' ][ Field::WHERE ] ) ;
        $this->assertInstanceOf( AqlBindReference::class , $out[ 'items' ][ Field::WHERE ][ 2 ] ) ;
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

    // ---------------------------------------------------------------- prepareQueryFields : nested skin filtering

    /**
     * A MAP whose sub-fields mix an unmarked entry (visible everywhere) and a
     * `Field::SKINS`-restricted one.
     */
    private function nestedSkinMapFields() :array
    {
        return
        [
            'offers' =>
            [
                Field::FILTER => Filter::MAP ,
                Field::FIELDS =>
                [
                    'price'              => Filter::DEFAULT ,
                    'priceSpecification' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                ] ,
            ] ,
        ] ;
    }

    public function testMapSubFieldSkinsKeepTheMatchingSkin() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->nestedSkinMapFields() , 'full' ) ;
        $this->assertSame( [ 'price' , 'priceSpecification' ] , array_keys( $out[ 'offers' ][ Field::FIELDS ] ) ) ;
    }

    public function testMapSubFieldSkinsRemoveTheNonMatchingSkin() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->nestedSkinMapFields() , 'main' ) ;
        $this->assertSame( [ 'price' ] , array_keys( $out[ 'offers' ][ Field::FIELDS ] ) ) ;
    }

    public function testMapSubFieldsAllPassWhenNoSkinIsRequested() :void
    {
        $out = $this->stub()->prepareQueryFields( $this->nestedSkinMapFields() ) ;
        $this->assertSame( [ 'price' , 'priceSpecification' ] , array_keys( $out[ 'offers' ][ Field::FIELDS ] ) ) ;
    }

    public function testDocumentSubFieldSkinsAreHonored() :void
    {
        $fields =
        [
            'addr' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS =>
                [
                    'street' => Filter::DEFAULT ,
                    'zip'    => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                ] ,
            ] ,
        ] ;

        $full = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
        $this->assertSame( [ 'street' , 'zip' ] , array_keys( $full[ 'addr' ][ Field::FIELDS ] ) ) ;

        $main = $this->stub()->prepareQueryFields( $fields , 'main' ) ;
        $this->assertSame( [ 'street' ] , array_keys( $main[ 'addr' ][ Field::FIELDS ] ) ) ;
    }

    public function testWrapSubFieldSkinsAreHonored() :void
    {
        $fields =
        [
            'subject' =>
            [
                Field::FILTER => Filter::WRAP ,
                Field::FIELDS =>
                [
                    'id'     => Filter::DEFAULT ,
                    'secret' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                ] ,
            ] ,
        ] ;

        $full = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
        $this->assertSame( [ 'id' , 'secret' ] , array_keys( $full[ 'subject' ][ Field::FIELDS ] ) ) ;

        $main = $this->stub()->prepareQueryFields( $fields , 'main' ) ;
        $this->assertSame( [ 'id' ] , array_keys( $main[ 'subject' ][ Field::FIELDS ] ) ) ;
    }

    /**
     * The motivating use case : a two-level MAP (a price grid inside an offers
     * array) whose innermost sub-field only appears in a dedicated skin. The
     * skin filter must apply at EVERY depth, not only on the first level.
     */
    public function testNestedSkinsApplyAtEveryDepth() :void
    {
        $fields =
        [
            'offers' =>
            [
                Field::FILTER => Filter::MAP ,
                Field::FIELDS =>
                [
                    'offers' =>
                    [
                        Field::FILTER => Filter::MAP ,
                        Field::FIELDS =>
                        [
                            'price'              => Filter::DEFAULT ,
                            'priceSpecification' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'offers.full' , 'full' ] ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] ;

        $full = $this->stub()->prepareQueryFields( $fields , 'offers.full' ) ;
        $this->assertSame
        (
            [ 'price' , 'priceSpecification' ] ,
            array_keys( $full[ 'offers' ][ Field::FIELDS ][ 'offers' ][ Field::FIELDS ] ) ,
        ) ;

        $main = $this->stub()->prepareQueryFields( $fields , 'main' ) ;
        $this->assertSame
        (
            [ 'price' ] ,
            array_keys( $main[ 'offers' ][ Field::FIELDS ][ 'offers' ][ Field::FIELDS ] ) ,
        ) ;
    }

    /**
     * When the skin removes EVERY declared sub-field of a structural parent
     * (MAP / DOCUMENT / WRAP), the parent itself is dropped from the projection
     * — key absent, like a field whose own Field::SKINS does not match. This
     * avoids leaking the raw sub-document (DOCUMENT fallback) or breaking the
     * query (WRAP without fields throws).
     */
    public function testParentIsDroppedWhenTheSkinRemovesAllItsSubFields() :void
    {
        foreach ( [ Filter::MAP , Filter::DOCUMENT , Filter::WRAP ] as $filter )
        {
            $out = $this->stub()->prepareQueryFields
            ([
                'name'    => Filter::DEFAULT ,
                'pricing' =>
                [
                    Field::FILTER => $filter ,
                    Field::FIELDS =>
                    [
                        'internalCost' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                    ] ,
                ] ,
            ] , 'main' ) ;

            $this->assertSame( [ 'name' ] , array_keys( $out ) , 'filter: ' . $filter ) ;
        }
    }

    public function testPrepareQueryFieldsReturnsNullWhenEveryFieldIsDropped() :void
    {
        $out = $this->stub()->prepareQueryFields
        ([
            'pricing' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS =>
                [
                    'internalCost' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                ] ,
            ] ,
        ] , 'main' ) ;

        $this->assertNull( $out ) ;
    }

    /**
     * The parent-drop rule cascades : an inner DOCUMENT emptied by the skin
     * disappears from its enclosing MAP, whose remaining sub-fields survive.
     */
    public function testNestedParentDropCascadesInsideAnEnclosingMap() :void
    {
        $out = $this->stub()->prepareQueryFields
        ([
            'offers' =>
            [
                Field::FILTER => Filter::MAP ,
                Field::FIELDS =>
                [
                    'label'   => Filter::DEFAULT ,
                    'details' =>
                    [
                        Field::FILTER => Filter::DOCUMENT ,
                        Field::FIELDS =>
                        [
                            'secret' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'full' ] ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ] , 'main' ) ;

        $this->assertSame( [ 'label' ] , array_keys( $out[ 'offers' ][ Field::FIELDS ] ) ) ;
    }

    /**
     * Field::SKINS (view) and Field::REQUIRES (security) cohabit on the same
     * nested sub-field : when the skin matches, the normalized definition keeps
     * the REQUIRES marker so the permission gating still applies downstream
     * (aqlFields / buildVariables) ; when the skin does not match, the field is
     * removed before any permission check.
     */
    public function testNestedSkinsCohabitWithRequiresOnTheSameSubField() :void
    {
        $fields =
        [
            'addr' =>
            [
                Field::FILTER => Filter::DOCUMENT ,
                Field::FIELDS =>
                [
                    'street' => Filter::DEFAULT ,
                    'zip'    =>
                    [
                        Field::FILTER   => Filter::DEFAULT ,
                        Field::SKINS    => [ 'full' ] ,
                        Field::REQUIRES => 'addr.zip:read' ,
                    ] ,
                ] ,
            ] ,
        ] ;

        $full = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
        $this->assertSame( 'addr.zip:read' , $full[ 'addr' ][ Field::FIELDS ][ 'zip' ][ Field::REQUIRES ] ) ;

        $main = $this->stub()->prepareQueryFields( $fields , 'main' ) ;
        $this->assertArrayNotHasKey( 'zip' , $main[ 'addr' ][ Field::FIELDS ] ) ;
    }

    // ---------------------------------------------------------------- skinFields : root per-skin projections

    private function stubWithSkinFields() :FieldsTraitStub
    {
        $stub = $this->stub() ;
        $stub->fields     = [ 'legacy' => Filter::DEFAULT ] ;
        $stub->skinFields =
        [
            'default' => [ 'name' => Filter::DEFAULT ] ,
            'full'    => [ 'name' => Filter::DEFAULT , 'secret' => Filter::DEFAULT ] ,
            'empty'   => [] ,
        ] ;
        return $stub ;
    }

    public function testInitializeSkinFieldsSetsRegistryAndReturnsSelf() :void
    {
        $stub = $this->stub() ;
        $registry = [ 'full' => [ 'name' => Filter::DEFAULT ] ] ;

        $result = $stub->initializeSkinFields( [ AQL::SKIN_FIELDS => $registry ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertSame( $registry , $stub->skinFields ) ;
    }

    public function testInitializeSkinFieldsWithEmptyInitKeepsExisting() :void
    {
        $stub = $this->stub() ;
        $stub->initializeSkinFields( [ AQL::SKIN_FIELDS => [ 'full' => [] ] ] ) ;
        $stub->initializeSkinFields( [] ) ;

        $this->assertSame( [ 'full' => [] ] , $stub->skinFields ) ;
    }

    public function testRootSkinFieldsPickTheBucketOfTheRequestedSkin() :void
    {
        $stub = $this->stubWithSkinFields() ;

        $this->assertSame( [ 'name' ] , array_keys( $stub->prepareQueryFields( null , 'default' ) ) ) ;
        $this->assertSame( [ 'name' , 'secret' ] , array_keys( $stub->prepareQueryFields( null , 'full' ) ) ) ;
    }

    public function testRootSkinFieldsFallBackOnLegacyFieldsForUnknownSkin() :void
    {
        // no '*' bucket declared → the resolution falls back on $this->fields
        $out = $this->stubWithSkinFields()->prepareQueryFields( null , 'other' ) ;
        $this->assertSame( [ 'legacy' ] , array_keys( $out ) ) ;
    }

    public function testRootSkinFieldsWildcardBucketCatchesUnknownAndNullSkins() :void
    {
        $stub = $this->stubWithSkinFields() ;
        $stub->skinFields[ '*' ] = [ 'fallback' => Filter::DEFAULT ] ;

        $this->assertSame( [ 'fallback' ] , array_keys( $stub->prepareQueryFields( null , 'other' ) ) ) ;
        $this->assertSame( [ 'fallback' ] , array_keys( $stub->prepareQueryFields( null ) ) ) ;
    }

    public function testRootSkinFieldsEmptyRegistryKeepsLegacyBehavior() :void
    {
        $stub = $this->stub() ;
        $stub->fields = [ 'legacy' => Filter::DEFAULT ] ;

        $this->assertSame( [ 'legacy' ] , array_keys( $stub->prepareQueryFields( null , 'full' ) ) ) ;
    }

    public function testRootSkinFieldsAreBypassedByExplicitFields() :void
    {
        $out = $this->stubWithSkinFields()->prepareQueryFields( [ 'custom' => Filter::DEFAULT ] , 'full' ) ;
        $this->assertSame( [ 'custom' ] , array_keys( $out ) ) ;
    }

    public function testRootSkinFieldsEmptyBucketYieldsNull() :void
    {
        // an empty bucket reads « no projection for this skin » → whole-document behavior upstream
        $this->assertNull( $this->stubWithSkinFields()->prepareQueryFields( null , 'empty' ) ) ;
    }

    public function testInAppliesInsideTheResolvedBucket() :void
    {
        $out = $this->stubWithSkinFields()->prepareQueryFields( null , 'full' , null , 'secret' ) ;
        $this->assertSame( [ 'secret' ] , array_keys( $out ) ) ;
    }

    public function testFieldSkinsMarkersInsideABucketAreFiltered() :void
    {
        $stub = $this->stub() ;
        $stub->skinFields =
        [
            'full' =>
            [
                'name'   => Filter::DEFAULT ,
                'hidden' => [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'other' ] ] ,
            ] ,
        ] ;

        // the bucket is picked by the skin, then the Field::SKINS markers filter INSIDE it
        $out = $stub->prepareQueryFields( null , 'full' ) ;
        $this->assertSame( [ 'name' ] , array_keys( $out ) ) ;
    }

    // ---------------------------------------------------------------- skinFields : structural sub-fields

    private function structuralSkinFields( string $filter ) :array
    {
        return
        [
            'name'    => Filter::DEFAULT ,
            'pricing' =>
            [
                Field::FILTER    => $filter ,
                AQL::SKIN_FIELDS =>
                [
                    'default' => [ 'price' => Filter::DEFAULT ] ,
                    'full'    => [ 'price' => Filter::DEFAULT , 'cost' => Filter::DEFAULT ] ,
                ] ,
            ] ,
        ] ;
    }

    public function testStructuralSkinFieldsPickTheBucketPerSkin() :void
    {
        foreach ( [ Filter::MAP , Filter::DOCUMENT , Filter::WRAP ] as $filter )
        {
            $fields = $this->structuralSkinFields( $filter ) ;

            $default = $this->stub()->prepareQueryFields( $fields , 'default' ) ;
            $this->assertSame( [ 'price' ] , array_keys( $default[ 'pricing' ][ Field::FIELDS ] ) , 'filter: ' . $filter ) ;

            $full = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
            $this->assertSame( [ 'price' , 'cost' ] , array_keys( $full[ 'pricing' ][ Field::FIELDS ] ) , 'filter: ' . $filter ) ;
        }
    }

    /**
     * A declared table that resolves to nothing for the requested skin (no
     * bucket, no '*', no Field::FIELDS fallback) drops the field itself — the
     * declaration reads « nothing is planned for this skin ». Never a raw
     * sub-document fallback (DOCUMENT), never an exception (WRAP).
     */
    public function testStructuralSkinFieldsUnresolvedDropTheField() :void
    {
        foreach ( [ Filter::MAP , Filter::DOCUMENT , Filter::WRAP ] as $filter )
        {
            $out = $this->stub()->prepareQueryFields( $this->structuralSkinFields( $filter ) , 'other' ) ;
            $this->assertSame( [ 'name' ] , array_keys( $out ) , 'filter: ' . $filter ) ;
        }
    }

    public function testStructuralSkinFieldsEmptyBucketDropsTheField() :void
    {
        $fields = $this->structuralSkinFields( Filter::DOCUMENT ) ;
        $fields[ 'pricing' ][ AQL::SKIN_FIELDS ][ 'default' ] = [] ; // explicitly « nothing for this skin »

        $out = $this->stub()->prepareQueryFields( $fields , 'default' ) ;
        $this->assertSame( [ 'name' ] , array_keys( $out ) ) ;
    }

    public function testStructuralSkinFieldsFallBackOnFieldFieldsForUnknownSkin() :void
    {
        $fields = $this->structuralSkinFields( Filter::MAP ) ;
        $fields[ 'pricing' ][ Field::FIELDS ] = [ 'label' => Filter::DEFAULT ] ; // legacy fallback beside the table

        $out = $this->stub()->prepareQueryFields( $fields , 'other' ) ;
        $this->assertSame( [ 'label' ] , array_keys( $out[ 'pricing' ][ Field::FIELDS ] ) ) ;
    }

    public function testStructuralSkinFieldsComposeWithNestedFieldSkins() :void
    {
        $fields = $this->structuralSkinFields( Filter::MAP ) ;
        $fields[ 'pricing' ][ AQL::SKIN_FIELDS ][ 'full' ][ 'internal' ] =
            [ Field::FILTER => Filter::DEFAULT , Field::SKINS => [ 'admin' ] ] ;

        // the 'full' bucket is picked, then the nested Field::SKINS marker filters inside it
        $out = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
        $this->assertSame( [ 'price' , 'cost' ] , array_keys( $out[ 'pricing' ][ Field::FIELDS ] ) ) ;
    }

    public function testStructuralSkinFieldsComposeWithFieldSkinsOnTheFieldItself() :void
    {
        $fields = $this->structuralSkinFields( Filter::MAP ) ;
        $fields[ 'pricing' ][ Field::SKINS ] = [ 'full' ] ; // the field itself is full-only

        // skin 'default' : the field is removed by its own marker before any bucket resolution
        $out = $this->stub()->prepareQueryFields( $fields , 'default' ) ;
        $this->assertSame( [ 'name' ] , array_keys( $out ) ) ;

        // skin 'full' : the marker passes, then the 'full' bucket is picked
        $full = $this->stub()->prepareQueryFields( $fields , 'full' ) ;
        $this->assertSame( [ 'price' , 'cost' ] , array_keys( $full[ 'pricing' ][ Field::FIELDS ] ) ) ;
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
