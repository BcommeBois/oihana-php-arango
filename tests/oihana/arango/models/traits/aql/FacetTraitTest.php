<?php

namespace tests\oihana\arango\models\traits\aql;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Logic;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\enums\filters\FilterParam;
use oihana\exceptions\ValidationException;

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

    public function testPrepareFacetsSwallowsBuilderExceptionWithoutLogger() :void
    {
        // Same failure, but no logger wired: the nullsafe call must not fatal —
        // the facet is simply skipped.
        $stub = $this->stub() ;
        $stub->logger = null ;
        $stub->facets = [ 'bad key' => [ Facet::TYPE => Facet::FIELD ] ] ;

        $binds = [] ;
        $this->assertNull( $stub->callPrepareFacets( [ Arango::FACETS => [ 'bad key' => 'x' ] ] , $binds ) ) ;
    }

    public function testPrepareFacetsWithoutTypeFallsBackToFieldBuilder() :void
    {
        // A facet definition missing Facet::TYPE resolves to null (no notice) and
        // routes to the default FIELD builder.
        $stub = $this->stub() ;
        $stub->facets = [ 'withStatus' => [] ] ;

        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus =~ @withStatus_0)' ,
            $stub->callPrepareFacets( [ Arango::FACETS => [ 'withStatus' => 'draft' ] ] , $binds ) ,
        ) ;
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

    public function testFieldOpEqUsesStrictEquality() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus == @withStatus_0)' ,
            $this->stub()->callField( 'withStatus' , [ FilterParam::OP => 'eq' , FilterParam::VAL => 'draft' ] , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'withStatus_0' => 'draft' ] , $binds ) ;
    }

    public function testFieldOpGeOnNumericValue() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.price >= @price_0)' ,
            $this->stub()->callField( 'price' , [ FilterParam::OP => 'ge' , FilterParam::VAL => 100 ] , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'price_0' => 100 ] , $binds ) ;
    }

    public function testFieldOpLikeUsesLikeOperator() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.name LIKE @name_0)' ,
            $this->stub()->callField( 'name' , [ FilterParam::OP => 'like' , FilterParam::VAL => 'jo%' ] , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testFieldOpFromFacetDefinition() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc._key == @id_0)' ,
            $this->stub()->callField( 'id' , '25' , $binds , [ Facet::PROPERTY => '_key' , Facet::OP => 'eq' ] , AQL::DOC ) ,
        ) ;
    }

    public function testFieldNegationIsGenericPerOperator() :void
    {
        // op=eq + leading '-' => the negative counterpart (ne), group ANDed.
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus != @withStatus_0)' ,
            $this->stub()->callField( 'withStatus' , [ FilterParam::OP => 'eq' , FilterParam::VAL => '-draft' ] , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'withStatus_0' => 'draft' ] , $binds ) ;
    }

    public function testFieldNegativeNumberKeptWhenOperatorHasNoNegation() :void
    {
        // A numeric scalar keeps its type (no comma split) and `ge` has no
        // negative counterpart, so the negative number is preserved as-is.
        $binds = [] ;
        $this->assertSame
        (
            '(doc.temperature >= @temperature_0)' ,
            $this->stub()->callField( 'temperature' , [ FilterParam::OP => 'ge' , FilterParam::VAL => -5 ] , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'temperature_0' => -5 ] , $binds ) ;
    }

    public function testFieldUnknownOpFallsBackToMatch() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(doc.withStatus =~ @withStatus_0)' ,
            $this->stub()->callField( 'withStatus' , [ FilterParam::OP => 'bogus' , FilterParam::VAL => 'draft' ] , $binds , [] , AQL::DOC ) ,
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
            'LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location_0 RETURN doc_location._key) > 0' ,
            $this->stub()->callEdge( 'location' , 1234 , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'location_0' => '1234' ] , $binds ) ;
    }

    public function testEdgeMultipleValuesAreOredInOneTraversal() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location_0 || doc_location._key == @location_1 RETURN doc_location._key) > 0' ,
            $this->stub()->callEdge( 'location' , '1234,5678' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'location_0' => '1234' , 'location_1' => '5678' ] , $binds ) ;
    }

    public function testEdgeNegativeValueExcludesViaZeroLength() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location_0 RETURN doc_location._key) == 0' ,
            $this->stub()->callEdge( 'location' , '-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'location_0' => '1234' ] , $binds ) ;
    }

    public function testEdgeMixedPositiveAndNegativeAreAndedAndParenthesized() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            '(LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location_0 RETURN doc_location._key) > 0 && LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location._key == @location_1 RETURN doc_location._key) == 0)' ,
            $this->stub()->callEdge( 'location' , '5678,-1234' , $binds , [ AQL::EDGE => 'orgs_places' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'location_0' => '5678' , 'location_1' => '1234' ] , $binds ) ;
    }

    public function testEdgeCustomFieldsTargetsConfiguredVertexProperty() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_location IN INBOUND doc orgs_places FILTER doc_location.name == @location_0 RETURN doc_location._key) > 0' ,
            $this->stub()->callEdge( 'location' , 'paris' , $binds , [ AQL::EDGE => 'orgs_places' , AQL::FIELDS => 'name' ] , AQL::DOC ) ,
        ) ;
    }

    public function testEdgeOpLikeOnSingleField() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_subjects IN INBOUND doc has_subject FILTER doc_subjects.name LIKE @subjects_0 RETURN doc_subjects._key) > 0' ,
            $this->stub()->callEdge( 'subjects' , 'art' , $binds , [ AQL::EDGE => 'has_subject' , AQL::FIELDS => 'name' , Facet::OP => 'like' ] , AQL::DOC ) ,
        ) ;
    }

    public function testEdgeMultiFieldOrIsTheThesaurusReplacement() :void
    {
        // the former THESAURUS: search a term across several vertex fields (OR), with `like`
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_subjects IN INBOUND doc has_subject FILTER (doc_subjects._key LIKE @subjects_0 || doc_subjects.name LIKE @subjects_0 || doc_subjects.alternateName LIKE @subjects_0) RETURN doc_subjects._key) > 0' ,
            $this->stub()->callEdge( 'subjects' , 'art' , $binds , [ AQL::EDGE => 'has_subject' , AQL::FIELDS => '_key,name,alternateName' , Facet::OP => 'like' ] , AQL::DOC ) ,
        ) ;
    }

    public function testEdgeMultiFieldMultiValue() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_subjects IN INBOUND doc has_subject FILTER (doc_subjects._key == @subjects_0 || doc_subjects.name == @subjects_0) || (doc_subjects._key == @subjects_1 || doc_subjects.name == @subjects_1) RETURN doc_subjects._key) > 0' ,
            $this->stub()->callEdge( 'subjects' , 'art,music' , $binds , [ AQL::EDGE => 'has_subject' , AQL::FIELDS => '_key,name' ] , AQL::DOC ) ,
        ) ;
    }

    // ---------------------------------------------------------------- HasFacetEdgeComplex

    public function testEdgeComplexBuildsFilterPerSubKey() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_numbers IN INBOUND doc live_numbers FILTER doc_numbers.value == @numbers_value RETURN doc_numbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '459' ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value' => '459' ] , $binds ) ;
    }

    public function testEdgeComplexMultipleFieldsAreAndedOnSameVertex() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_numbers IN INBOUND doc live_numbers FILTER doc_numbers.value == @numbers_value && doc_numbers.kind == @numbers_kind RETURN doc_numbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '459' , 'kind' => 'ear' ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value' => '459' , 'numbers_kind' => 'ear' ] , $binds ) ;
    }

    public function testEdgeComplexArrayFieldValuesAreOred() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_numbers IN INBOUND doc live_numbers FILTER (doc_numbers.value == @numbers_value0 || doc_numbers.value == @numbers_value1) RETURN doc_numbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => [ '459' , '460' ] ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value0' => '459' , 'numbers_value1' => '460' ] , $binds ) ;
    }

    public function testEdgeComplexScalarNegationUsesNotEqualInline() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_numbers IN INBOUND doc live_numbers FILTER doc_numbers.value != @numbers_value && doc_numbers.kind == @numbers_kind RETURN doc_numbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => '-459' , 'kind' => 'ear' ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value' => '459' , 'numbers_kind' => 'ear' ] , $binds ) ;
    }

    public function testEdgeComplexArrayWithNegativeFlipsFieldGroupToAnd() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_numbers IN INBOUND doc live_numbers FILTER (doc_numbers.value == @numbers_value0 && doc_numbers.value != @numbers_value1) RETURN doc_numbers._key) > 0' ,
            $this->stub()->callEdgeComplex( 'numbers' , [ 'value' => [ '459' , '-460' ] ] , $binds , [ AQL::EDGE => 'live_numbers' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'numbers_value0' => '459' , 'numbers_value1' => '460' ] , $binds ) ;
    }

    // ---------------------------------------------------------------- HasFacetJoinComplex

    public function testJoinComplexReverseOneToManyMatch() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key && doc_comments.status == @comments_status RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'comments' , [ 'status' => 'approved' ] , $binds , [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'comments_status' => 'approved' ] , $binds ) ;
    }

    public function testJoinComplexDefaultsKeyAndProperty() :void
    {
        // KEY defaults to _key, PROPERTY defaults to the facet key (one-to-one).
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_place IN places FILTER doc_place._key == doc.place && doc_place.name == @place_name RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'place' , [ 'name' => 'Paris' ] , $binds , [ AQL::COLLECTION => 'places' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinComplexMultipleFieldsAreAnded() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key && doc_comments.status == @comments_status && doc_comments.score == @comments_score RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'comments' , [ 'status' => 'approved' , 'score' => '5' ] , $binds , [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinComplexArrayValuesAreOred() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_comments IN comments FILTER doc_comments._key == doc.comments && (doc_comments.status == @comments_status0 || doc_comments.status == @comments_status1) RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'comments' , [ 'status' => [ 'approved' , 'featured' ] ] , $binds , [ AQL::COLLECTION => 'comments' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinComplexNegationUsesNotEqualInline() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_comments IN comments FILTER doc_comments._key == doc.comments && doc_comments.status != @comments_status RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'comments' , [ 'status' => '-spam' ] , $binds , [ AQL::COLLECTION => 'comments' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinComplexArrayVariantUsesIn() :void
    {
        // AQL::ARRAY => true joins on `doc_tags._key IN doc.tagIds`.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_tags IN tags FILTER doc_tags._key IN doc.tagIds && doc_tags.label == @tags_label RETURN 1) > 0' ,
            $this->stub()->callJoinComplex( 'tags' , [ 'label' => 'php' ] , $binds , [ AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinComplexRejectsInjectionInSubField() :void
    {
        $binds = [] ;
        $this->expectException( ValidationException::class ) ;
        $this->stub()->callJoinComplex( 'comments' , [ 'a || 1==1' => 'x' ] , $binds , [ AQL::COLLECTION => 'comments' ] , AQL::DOC ) ;
    }

    // ---------------------------------------------------------------- AQL injection guards (complex sub-fields)

    public function testEdgeComplexRejectsInjectionInSubField() :void
    {
        $binds = [] ;
        $this->expectException( ValidationException::class ) ;
        $this->stub()->callEdgeComplex( 'numbers' , [ 'value == 1 || 1' => 'x' ] , $binds , [ AQL::EDGE => 'e' ] , AQL::DOC ) ;
    }

    public function testArrayComplexRejectsInjectionInSubField() :void
    {
        $binds = [] ;
        $this->expectException( ValidationException::class ) ;
        $this->stub()->callArrayComplex( 'workshops' , [ 'a)||LENGTH(FOR s IN secrets RETURN 1)>0||(b' => 'pig' ] , $binds ) ;
    }

    public function testPrepareFacetsSkipsAndLogsOnInjectionAttempt() :void
    {
        // Routed through the dispatcher, a malicious sub-field is swallowed: the
        // facet is dropped and a warning is logged (no fragment reaches the AQL).
        $stub = $this->stub() ;
        $stub->logger = new FacetSpyLogger() ;
        $stub->facets = [ 'numbers' => [ Facet::TYPE => Facet::EDGE_COMPLEX , AQL::EDGE => 'e' ] ] ;

        $binds  = [] ;
        $result = $stub->callPrepareFacets( [ Arango::FACETS => [ 'numbers' => [ 'a || 1==1' => 'x' ] ] ] , $binds ) ;

        $this->assertNull( $result ) ;
        $this->assertSame( [ 'warning' ] , $stub->logger->levels ) ;
        $this->assertSame( [] , $binds ) ;
    }

    // ---------------------------------------------------------------- HasFacetArrayComplex

    public function testArrayComplexMultipleValuesAreOred() :void
    {
        // The FOR..IN source iterates the embedded array property of the current
        // document (`doc.workshops`) — in scope at FILTER time — and returns 1 per
        // matching element (LENGTH(...) > 0 = existential). Validated live.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_workshops IN doc.workshops FILTER doc_workshops.breeding_alternateName == @workshops_breeding_alternateName0 || doc_workshops.breeding_alternateName == @workshops_breeding_alternateName1 RETURN 1) > 0' ,
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
            'LENGTH(FOR doc_workshops IN doc.workshops FILTER doc_workshops.breeding_alternateName == @workshops_breeding_alternateName RETURN 1) > 0' ,
            $this->stub()->callArrayComplex( 'workshops' , [ 'breeding_alternateName' => 'pig' ] , $binds ) ,
        ) ;
    }

    public function testArrayComplexScalarNegativeStringUsesNotEqual() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN doc.w FILTER doc_w.p != @w_p RETURN 1) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => '-pig' ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p' => 'pig' ] , $binds ) ;
    }

    public function testArrayComplexScalarNegativeIntUsesAbsoluteAndNotEqual() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN doc.w FILTER doc_w.p != @w_p RETURN 1) > 0' ,
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
            'LENGTH(FOR doc_w IN doc.w FILTER doc_w.p != @w_p0 && doc_w.p != @w_p1 RETURN 1) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => [ '-pig' , 'cattle' ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p0' => 'pig' , 'w_p1' => 'cattle' ] , $binds ) ;
    }

    public function testArrayComplexArrayWithNegativeIntUsesAbsoluteValues() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN doc.w FILTER doc_w.p != @w_p0 && doc_w.p != @w_p1 RETURN 1) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'p' => [ -5 , 10 ] ] , $binds ) ,
        ) ;
        $this->assertSame( [ 'w_p0' => 5 , 'w_p1' => 10 ] , $binds ) ;
    }

    public function testArrayComplexHonorsCustomDocReference() :void
    {
        // The base array is iterated off the supplied docRef, so a non-'doc'
        // reference (e.g. a joined alias) flows through to the FOR..IN source.
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_w IN p.w FILTER doc_w.q == @w_q RETURN 1) > 0' ,
            $this->stub()->callArrayComplex( 'w' , [ 'q' => 'x' ] , $binds , 'p' ) ,
        ) ;
    }

    // ---------------------------------------------------------------- HasFacetIn (membership primitive)

    public function testInDefaultIsAnyIn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'keywords_0' => 'k1' , 'keywords_1' => 'k2' ] , $binds ) ;
    }

    public function testInArrayValueFormIsAccepted() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , [ 'k1' , 'k2' ] , $binds , [] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'keywords_0' => 'k1' , 'keywords_1' => 'k2' ] , $binds ) ;
    }

    public function testInOpObjectSelectsAllIn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ALL IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , [ FilterParam::OP => 'all.in' , FilterParam::VAL => 'k1,k2' ] , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testInOpObjectSelectsNoneInWithArrayVal() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0]) NONE IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , [ FilterParam::OP => 'none.in' , FilterParam::VAL => [ 'k1' ] ] , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testInConfiguredOpFromFacetDefinition() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ALL IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , 'k1,k2' , $binds , [ Facet::OP => 'all.in' ] , AQL::DOC ) ,
        ) ;
    }

    public function testInSortableAppendsSortPosition() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords SORT POSITION([@keywords_0,@keywords_1],doc.keywords,true)' ,
            $this->stub()->callIn( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC , true ) ,
        ) ;
    }

    public function testInPropertyAliasDecouplesUrlKeyFromDocumentProperty() :void
    {
        // The URL facet key (`id`) stays in the bind name while the document
        // property is taken from Facet::PROPERTY (`_key`). No magic id->_key
        // mapping: the alias is fully explicit.
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@id_0]) ANY IN doc._key' ,
            $this->stub()->callIn( 'id' , '25' , $binds , [ Facet::PROPERTY => '_key' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'id_0' => '25' ] , $binds ) ;
    }

    public function testInIdWithoutPropertyTargetsDocId() :void
    {
        // Without an explicit Facet::PROPERTY, `id` now targets doc.id (the magic
        // id->_key special case was removed in favour of the PROPERTY alias).
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@id_0]) ANY IN doc.id' ,
            $this->stub()->callIn( 'id' , '25' , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testInUnknownOpFallsBackToAnyIn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0]) ANY IN doc.keywords' ,
            $this->stub()->callIn( 'keywords' , [ FilterParam::OP => 'bogus' , FilterParam::VAL => 'k1' ] , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    // ---------------------------------------------------------------- HasFacetList / HasFacetListField (aliases of HasFacetIn)

    public function testListStringValueDelegatesToAnyIn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ANY IN doc.keywords' ,
            $this->stub()->callList( 'keywords' , 'k1,k2' , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testListSupportsOpObject() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'TO_ARRAY([@keywords_0,@keywords_1]) ALL IN doc.keywords' ,
            $this->stub()->callList( 'keywords' , [ FilterParam::OP => 'all.in' , FilterParam::VAL => 'k1,k2' ] , $binds , [] , AQL::DOC ) ,
        ) ;
    }

    public function testListReturnsEmptyForUnsupportedValueShape() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->callList( 'k' , 5 , $binds , [] , AQL::DOC ) ) ;
        // an associative object without a `val` key (e.g. the removed {length:N}) is ignored
        $this->assertSame( '' , $this->stub()->callList( 'k' , [ 'length' => 3 ] , $binds , [] , AQL::DOC ) ) ;
    }

    // ---------------------------------------------------------------- HasFacetJoin (simple key-join)

    public function testJoinSingleFieldMatch() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && doc_author.name == @author_0 RETURN 1) > 0' ,
            $this->stub()->callJoin( 'author' , 'alice' , $binds , [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'author_0' => 'alice' ] , $binds ) ;
    }

    public function testJoinMultipleValuesAreOredWithJoinPrefix() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && (doc_author.name == @author_0 || doc_author.name == @author_1) RETURN 1) > 0' ,
            $this->stub()->callJoin( 'author' , 'alice,bob' , $binds , [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinNegativeExcludesViaZeroLength() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && doc_author.name == @author_0 RETURN 1) == 0' ,
            $this->stub()->callJoin( 'author' , '-spammer' , $binds , [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name' ] , AQL::DOC ) ,
        ) ;
        $this->assertSame( [ 'author_0' => 'spammer' ] , $binds ) ;
    }

    public function testJoinMultiFieldOrWithLike() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_author IN authors FILTER doc_author._key == doc.authorId && (doc_author.name LIKE @author_0 || doc_author.alternateName LIKE @author_0) RETURN 1) > 0' ,
            $this->stub()->callJoin( 'author' , 'al' , $binds , [ AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , AQL::FIELDS => 'name,alternateName' , Facet::OP => 'like' ] , AQL::DOC ) ,
        ) ;
    }

    public function testJoinArrayVariantUsesIn() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LENGTH(FOR doc_tags IN tags FILTER doc_tags._key IN doc.tagIds && doc_tags.label == @tags_0 RETURN 1) > 0' ,
            $this->stub()->callJoin( 'tags' , 'php' , $binds , [ AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' , AQL::FIELDS => 'label' ] , AQL::DOC ) ,
        ) ;
    }
}
