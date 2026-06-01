<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\aql\BindTrait;
use oihana\arango\models\traits\aql\FacetTrait;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Minimal PSR logger spy capturing the levels of the records it receives.
 */
class FacetSpyLogger extends AbstractLogger
{
    public array $levels = [] ;

    public function log( $level , \Stringable|string $message , array $context = [] ) :void
    {
        $this->levels[] = (string) $level ;
    }
}

/**
 * Bare host exposing {@see FacetTrait} (and the {@see BindTrait} it relies on
 * for `$this->bind()`) for isolated testing. Public proxies forward to the
 * protected facet builders while preserving the `&$binds` reference.
 */
class FacetTraitStub
{
    use FacetTrait ;
    use BindTrait ;

    public mixed    $logger     = null ;
    public ?string  $collection = 'mycol' ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ; // deterministic: bind names are always explicit, so the id is unused
    }

    public function __toString() :string
    {
        return '[stub]' ;
    }

    public function callPrepareFacets( ?array $init , ?array &$binds = null , string $docRef = AQL::DOC , string $op = Logic::AND ) :?string
    {
        return $this->prepareFacets( $init , $binds , $docRef , $op ) ;
    }

    public function callField( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetField( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callListField( string $key , mixed $value , array &$binds , array $facet , string $doc , bool $sortable = false ) :string
    {
        return $this->prepareFacetListField( $key , $value , $binds , $facet , $doc , $sortable ) ;
    }

    public function callListFieldSorted( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetListFieldSorted( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callEdge( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdge( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callEdgeComplex( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetEdgeComplex( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callArrayComplex( string $key , mixed $value , array &$binds ) :string
    {
        return $this->prepareFacetArrayComplex( $key , $value , $binds ) ;
    }

    public function callList( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetList( $key , $value , $binds , $facet , $doc ) ;
    }

    public function callThesaurus( string $key , mixed $value , array &$binds , array $facet , string $doc ) :string
    {
        return $this->prepareFacetThesaurus( $key , $value , $binds , $facet , $doc ) ;
    }
}

/**
 * Characterization coverage for {@see FacetTrait} (initializeFacets + the
 * prepareFacets dispatcher) and the seven HasFacet* builders it composes.
 *
 * Bind names are always passed explicitly by the builders, so aqlBind() never
 * touches the query id — the emitted AQL and `$binds` side effects are fully
 * deterministic and asserted as exact strings.
 */
class FacetTraitTest extends TestCase
{
    private function stub() :FacetTraitStub
    {
        return new FacetTraitStub() ;
    }

    // ---------------------------------------------------------------- initializeFacets

    public function testInitializeFacetsSetsAndReturnsSelf() :void
    {
        $stub = $this->stub() ;
        $result = $stub->initializeFacets( [ 'facets' => [ 'x' => [ Facet::TYPE => Facet::FIELD ] ] ] ) ;

        $this->assertSame( $stub , $result ) ;
        $this->assertSame( [ 'x' => [ Facet::TYPE => Facet::FIELD ] ] , $stub->facets ) ;
    }

    public function testInitializeFacetsWithEmptyArrayKeepsExisting() :void
    {
        $stub = $this->stub() ;
        $stub->initializeFacets( [ 'facets' => [ 'x' => 1 ] ] ) ;
        $stub->initializeFacets( [] ) ;

        $this->assertSame( [ 'x' => 1 ] , $stub->facets ) ;
    }

    // ---------------------------------------------------------------- prepareFacets : empty / null

    public function testPrepareFacetsReturnsEmptyWhenNoFacetsKey() :void
    {
        $this->assertSame( '' , $this->stub()->callPrepareFacets( [] ) ) ;
    }

    public function testPrepareFacetsReturnsEmptyWhenFacetsNotArray() :void
    {
        $this->assertSame( '' , $this->stub()->callPrepareFacets( [ Arango::FACETS => 'nope' ] ) ) ;
    }

    public function testPrepareFacetsReturnsEmptyWhenModelFacetsNotArray() :void
    {
        $stub = $this->stub() ;
        $stub->facets = null ;
        $this->assertSame( '' , $stub->callPrepareFacets( [ Arango::FACETS => [ 'a' => 'x' ] ] ) ) ;
    }

    public function testPrepareFacetsReturnsNullWhenNoPredicateProduced() :void
    {
        // The request key is not declared in $this->facets, so it is skipped and
        // predicates([]) collapses to null (distinct from the Char::EMPTY cases above).
        $stub = $this->stub() ;
        $stub->facets = [ 'known' => [ Facet::TYPE => Facet::FIELD ] ] ;
        $this->assertNull( $stub->callPrepareFacets( [ Arango::FACETS => [ 'unknown' => 'x' ] ] ) ) ;
    }

    // ---------------------------------------------------------------- prepareFacets : dispatch / joining

    public function testPrepareFacetsDispatchesFieldType() :void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'withStatus' => [ Facet::TYPE => Facet::FIELD ] ] ;

        $binds = [] ;
        $aql   = $stub->callPrepareFacets( [ Arango::FACETS => [ 'withStatus' => 'draft' ] ] , $binds ) ;

        $this->assertSame( '(doc.withStatus =~ @withStatus_0)' , $aql ) ;
        $this->assertSame( [ 'withStatus_0' => 'draft' ] , $binds ) ;
    }

    public function testPrepareFacetsUnknownTypeFallsBackToFieldBuilder() :void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'w' => [ Facet::TYPE => 'weird_unhandled_type' ] ] ;

        $binds = [] ;
        $this->assertSame
        (
            '(doc.w =~ @w_0)' ,
            $stub->callPrepareFacets( [ Arango::FACETS => [ 'w' => 'draft' ] ] , $binds ) ,
        ) ;
    }

    public function testPrepareFacetsJoinsMultipleWithAnd() :void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'a' => [ Facet::TYPE => Facet::FIELD ] , 'b' => [ Facet::TYPE => Facet::FIELD ] ] ;

        $binds = [] ;
        $this->assertSame
        (
            '(doc.a =~ @a_0) && (doc.b =~ @b_0)' ,
            $stub->callPrepareFacets( [ Arango::FACETS => [ 'a' => 'x' , 'b' => 'y' ] ] , $binds , AQL::DOC , Logic::AND ) ,
        ) ;
    }

    public function testPrepareFacetsJoinsMultipleWithOr() :void
    {
        $stub = $this->stub() ;
        $stub->facets = [ 'a' => [ Facet::TYPE => Facet::FIELD ] , 'b' => [ Facet::TYPE => Facet::FIELD ] ] ;

        $binds = [] ;
        $this->assertSame
        (
            '(doc.a =~ @a_0) || (doc.b =~ @b_0)' ,
            $stub->callPrepareFacets( [ Arango::FACETS => [ 'a' => 'x' , 'b' => 'y' ] ] , $binds , AQL::DOC , Logic::OR ) ,
        ) ;
    }

    public function testPrepareFacetsCatchesBuilderExceptionAndLogsWarning() :void
    {
        // An invalid bind variable name (the facet key contains a space) makes
        // aqlBind throw BindException, which prepareFacets catches and logs.
        $stub = $this->stub() ;
        $stub->logger = new FacetSpyLogger() ;
        $stub->facets = [ 'bad key' => [ Facet::TYPE => Facet::FIELD ] ] ;

        $binds  = [] ;
        $result = $stub->callPrepareFacets( [ Arango::FACETS => [ 'bad key' => 'x' ] ] , $binds ) ;

        $this->assertNull( $result ) ;
        $this->assertSame( [ 'warning' ] , $stub->logger->levels ) ;
    }

    // ---------------------------------------------------------------- HasFacetField

    public function testFieldSingleValue() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus =~ @withStatus_0)' ,
            $this->stub()->callField( 'withStatus' , 'draft' , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'withStatus_0' => 'draft' ] , $binds ) ;
    }

    public function testFieldCommaSeparatedValuesAreOred() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus =~ @withStatus_0 || doc.withStatus =~ @withStatus_1)' ,
            $this->stub()->callField( 'withStatus' , 'draft,review' , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'withStatus_0' => 'draft' , 'withStatus_1' => 'review' ] , $binds ) ;
    }

    public function testFieldNegativeValueUsesNotMatch() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus !~ @withStatus_0)' ,
            $this->stub()->callField( 'withStatus' , '-draft' , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testFieldPropertyOverrideTargetsConfiguredProperty() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc._key =~ @id_0)' ,
            $this->stub()->callField( 'id' , '25' , $binds , [ Facet::PROPERTY => '_key' ] , AQL::DOC ) ,
        ) ;
    }

    // ---------------------------------------------------------------- HasFacetListField

    public function testListFieldBuildsAnyInExpression() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords' ,
            $this->stub()->callListField( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'keywords_0' => 'k1' , 'keywords_1' => 'k2' ] , $binds ) ;
    }

    public function testListFieldSortedAppendsSortPosition() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords SORT POSITION([@keywords_0,@keywords_1],doc.keywords,true)' ,
            $this->stub()->callListFieldSorted( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    // ---------------------------------------------------------------- HasFacetEdge

    public function testEdgeBuildsInboundTraversalLength() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location RETURN doc_location._key) > 0' ,
            $this->stub()->callEdge( 'location' , 1234 , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'location' => '1234' ] , $binds ) ;
    }

    // ---------------------------------------------------------------- HasFacetEdgeComplex

    public function testEdgeComplexBuildsFilterPerSubKey() :void
    {
        // NOTE: docRef uses AQL::DOC_REF ('docRef') instead of AQL::DOC_PREFIX
        // ('doc_'), so the loop variable is 'docRefnumbers' (no separator).
        // Frozen as current behavior; flagged to the maintainer.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR docRefnumbers IN INBOUND doc live_numbers FILTER docRefnumbers.value == @numbers_value RETURN docRefnumbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '459' ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value' => '459' ] , $binds ) ;
    }

    // ---------------------------------------------------------------- HasFacetArrayComplex

    public function testArrayComplexMultipleValuesAreOred() :void
    {
        // NOTE: the FOR..IN source is `Prop::RESULT . $key` => 'resultworkshops'
        // (no separator between 'result' and the key). Frozen as current
        // behavior; flagged to the maintainer.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_workshops IN resultworkshops FILTER doc_workshops.breeding_alternateName == @workshops_breeding_alternateName0 || doc_workshops.breeding_alternateName == @workshops_breeding_alternateName1 RETURN doc_workshops._key) > 0' ,
            $this->stub()->callArrayComplex( 'workshops' , [ 'breeding_alternateName' => [ 'pig' , 'cattle' ] ] , $binds ) ,
        ) ;
        $this->assertSame
        (
            [ 'workshops_breeding_alternateName0' => 'pig' , 'workshops_breeding_alternateName1' => 'cattle' ] ,
            $binds ,
        ) ;
    }

    public function testArrayComplexScalarValueUsesEquality() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_workshops IN resultworkshops FILTER doc_workshops.breeding_alternateName == @workshops_breeding_alternateName RETURN doc_workshops._key) > 0' ,
            $this->stub()->callArrayComplex( 'workshops' , [ 'breeding_alternateName' => 'pig' ] , $binds ) ,
        ) ;
    }

    public function testArrayComplexScalarNegativeStringUsesNotEqual() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN resultw FILTER doc_w.p != @w_p RETURN doc_w._key) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => '-pig' ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p' => 'pig' ] , $binds ) ;
    }

    public function testArrayComplexScalarNegativeIntUsesAbsoluteAndNotEqual() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN resultw FILTER doc_w.p != @w_p RETURN doc_w._key) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => -5 ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p' => 5 ] , $binds ) ;
    }

    public function testArrayComplexArrayWithAnyNegativeStringFlipsWholeGroupToAnd() :void
    {
        // Once a negative term is seen, the whole sub-group switches to AND and
        // every term (even the non-prefixed one) is compared with !=. Frozen as-is.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN resultw FILTER doc_w.p != @w_p0 && doc_w.p != @w_p1 RETURN doc_w._key) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => [ '-pig' , 'cattle' ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p0' => 'pig' , 'w_p1' => 'cattle' ] , $binds ) ;
    }

    public function testArrayComplexArrayWithNegativeIntUsesAbsoluteValues() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN resultw FILTER doc_w.p != @w_p0 && doc_w.p != @w_p1 RETURN doc_w._key) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => [ -5 , 10 ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p0' => 5 , 'w_p1' => 10 ] , $binds ) ;
    }

    // ---------------------------------------------------------------- HasFacetList

    public function testListLengthVariantBuildsCountComparison() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_length IN mycol FILTER doc._id IN doc_length.keywords LIMIT 1 RETURN 1) == 3' ,
            $this->stub()->callList( 'keywords' , [ 'length' => 3 ] , $binds , [ Facet::PROPERTY => 'keywords' ] , AQL::DOC ) ,
        ) ;
    }

    public function testListStringValueDelegatesToListField() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords' ,
            $this->stub()->callList( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testListReturnsEmptyForUnsupportedValueShape() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callList( 'k' , 5 , $binds , [] , AQL::DOC ) ) ;
        $this->assertSame( '' , $this->stub()->callList( 'k' , [ 'foo' => 1 ] , $binds , [] , AQL::DOC ) ) ;
    }

    // ---------------------------------------------------------------- HasFacetThesaurus

    public function testThesaurusBuildsContainsTraversalWithPositiveAndNegativeTerms() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_subjects IN INBOUND doc has_subject FILTER (CONTAINS(doc_subjects._key,@subjects0) || CONTAINS(doc_subjects.name,@subjects0) || CONTAINS(doc_subjects.alternateName,@subjects0)) && (!CONTAINS(doc_subjects._key,@subjects1) && !CONTAINS(doc_subjects.name,@subjects1) && !CONTAINS(doc_subjects.alternateName,@subjects1)) RETURN doc_subjects._key) > 0' ,
            $this->stub()->callThesaurus( 'subjects' , 'art,-music' , $binds , [ AQL::EDGE => 'has_subject' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'subjects0' => 'art' , 'subjects1' => 'music' ] , $binds ) ;
    }
}
