<?php

namespace tests\oihana\arango\models\traits\aql;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\models\enums\Facet;
use oihana\exceptions\ValidationException;

/**
 * Unit coverage for the aggregate facets {@see Facet::EDGE_AGGREGATE} and
 * {@see Facet::JOIN_AGGREGATE}: they aggregate a numeric field over the related
 * documents (an inbound edge traversal or a key-join) and compare the result to
 * a threshold — `AGG(FOR … [FILTER join] RETURN related.field) <op> @threshold`.
 *
 * The value is `{agg, field, op, val}`, each piece overridable per request and
 * falling back to the definition (`Facet::AGG`, `AQL::FIELDS`, `Facet::OP`).
 */
class FacetAggregateTest extends TestCase
{
    private FacetTraitStub $stub;
    private array $binds;

    protected function setUp(): void
    {
        $this->stub  = new FacetTraitStub() ;
        $this->binds = [] ;
    }

    // ========================================
    // JOIN_AGGREGATE
    // ========================================

    public function testJoinAggregateAverageOverJoinedField(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , AQL::FIELDS => 'score' ] ;
        $value  = [ 'agg' => 'avg' , 'field' => 'score' , 'op' => 'ge' , 'val' => 4 ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0'
            . ' && AVERAGE(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.score) >= @comments_0)' ,
            $result
        ) ;
        $this->assertSame( 4 , $this->binds[ 'comments_0' ] ) ;
    }

    public function testJoinAggregateCountIsFieldless(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' ] ;
        $value  = [ 'agg' => 'count' , 'val' => 3 ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0'
            . ' && COUNT(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) >= @comments_0)' ,
            $result
        ) ;
    }

    public function testJoinAggregateScalarValueIsThresholdWithDefaults(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , 5 , $this->binds , $facet , AQL::DOC ) ;

        // default aggregator = count, default op = ge
        $this->assertSame
        (
            '(LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0'
            . ' && COUNT(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) >= @comments_0)' ,
            $result
        ) ;
        $this->assertSame( 5 , $this->binds[ 'comments_0' ] ) ;
    }

    public function testJoinAggregateArrayOfKeysUsesInJoin(): void
    {
        $facet  = [ AQL::COLLECTION => 'tags' , AQL::KEY => '_key' , Facet::PROPERTY => 'tagIds' , AQL::ARRAY => true , AQL::FIELDS => 'weight' ] ;
        $value  = [ 'agg' => 'sum' , 'field' => 'weight' , 'val' => 10 ] ;
        $result = $this->stub->callJoinAggregate( 'tags' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_tags IN tags FILTER doc_tags._key IN doc.tagIds RETURN 1) > 0'
            . ' && SUM(FOR doc_tags IN tags FILTER doc_tags._key IN doc.tagIds RETURN doc_tags.weight) >= @tags_0)' ,
            $result
        ) ;
    }

    public function testJoinAggregateDefinitionDefaultsAreUsed(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , Facet::AGG => 'avg' , AQL::FIELDS => 'score' , Facet::OP => 'ge' ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , [ 'val' => 4 ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0'
            . ' && AVERAGE(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.score) >= @comments_0)' ,
            $result
        ) ;
    }

    public function testJoinAggregateUrlOverridesDefinitionDefaults(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , Facet::AGG => 'avg' , AQL::FIELDS => [ 'score' , 'rank' ] , Facet::OP => 'ge' ] ;
        $value  = [ 'agg' => 'min' , 'field' => 'rank' , 'op' => 'lt' , 'val' => 2 ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN 1) > 0'
            . ' && MIN(FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key RETURN doc_comments.rank) < @comments_0)' ,
            $result
        ) ;
    }

    // ========================================
    // EDGE_AGGREGATE
    // ========================================

    public function testEdgeAggregateAverageOverInboundVertices(): void
    {
        $facet  = [ AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ;
        $value  = [ 'agg' => 'avg' , 'field' => 'revenue' , 'op' => 'ge' , 'val' => 1000000 ] ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) > 0'
            . ' && AVERAGE(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) >= @balanceSheets_0)' ,
            $result
        ) ;
        $this->assertSame( 1000000 , $this->binds[ 'balanceSheets_0' ] ) ;
    }

    public function testEdgeAggregateCountIsFieldlessAndHasNoFilter(): void
    {
        $facet  = [ AQL::EDGE => 'balance_edges' ] ;
        $value  = [ 'agg' => 'count' , 'op' => 'ge' , 'val' => 3 ] ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame
        (
            '(LENGTH(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) > 0'
            . ' && COUNT(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) >= @balanceSheets_0)' ,
            $result
        ) ;
        $this->assertStringNotContainsString( 'FILTER' , $result ) ;
    }

    public function testEdgeAggregateSumAndMaxAndLessThan(): void
    {
        $facet = [ AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ;

        $sum = $this->stub->callEdgeAggregate( 'balanceSheets' , [ 'agg' => 'sum' , 'field' => 'revenue' , 'val' => 5000000 ] , $this->binds , $facet , AQL::DOC ) ;
        $this->assertStringContainsString( 'SUM(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) >= @' , $sum ) ;
        $this->assertStringStartsWith( '(LENGTH(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN 1) > 0 &&' , $sum ) ;

        $this->binds = [] ;
        $max = $this->stub->callEdgeAggregate( 'balanceSheets' , [ 'agg' => 'max' , 'field' => 'revenue' , 'op' => 'lt' , 'val' => 2000000 ] , $this->binds , $facet , AQL::DOC ) ;
        $this->assertStringContainsString( 'MAX(FOR doc_balanceSheets IN INBOUND doc balance_edges RETURN doc_balanceSheets.revenue) < @' , $max ) ;
    }

    // ========================================
    // Common behaviour & guards
    // ========================================

    public function testNumericStringThresholdIsCastToNumber(): void
    {
        $facet  = [ AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ;
        $value  = [ 'agg' => 'avg' , 'field' => 'revenue' , 'val' => '1000000' ] ;
        $this->stub->callEdgeAggregate( 'balanceSheets' , $value , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame( 1000000 , $this->binds[ 'balanceSheets_0' ] ) ; // int, not the "1000000" string
    }

    public function testMissingValReturnsEmpty(): void
    {
        $facet  = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , AQL::FIELDS => 'score' ] ;
        $result = $this->stub->callJoinAggregate( 'comments' , [ 'agg' => 'avg' , 'field' => 'score' ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame( '' , $result ) ;
        $this->assertSame( [] , $this->binds ) ;
    }

    public function testUnknownAggregatorThrows(): void
    {
        $facet = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' ] ;

        $this->expectException( ValidationException::class ) ;
        $this->stub->callJoinAggregate( 'comments' , [ 'agg' => 'bogus' , 'val' => 1 ] , $this->binds , $facet , AQL::DOC ) ;
    }

    public function testMaliciousFieldIsRejected(): void
    {
        // A malicious field declared in the config still hits the injection guard
        // (defence in depth on the trusted path). A malicious URL field outside the
        // whitelist is neutralised earlier — see testUrlFieldOutsideWhitelistIsNeutralised.
        $facet = [ AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , AQL::FIELDS => 'score) || true || (' ] ;

        $this->expectException( ValidationException::class ) ;
        $this->stub->callJoinAggregate( 'comments' , [ 'agg' => 'avg' , 'val' => 1 ] , $this->binds , $facet , AQL::DOC ) ;
    }

    public function testUrlFieldOutsideWhitelistIsNeutralised(): void
    {
        // Levier 1 (fail-closed): the URL asks for a field the facet does not declare
        // as aggregatable → the facet is neutralised to `false` (no aggregate oracle).
        $facet  = [ AQL::EDGE => 'balance_edges' , AQL::FIELDS => 'revenue' ] ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , [ 'agg' => 'max' , 'field' => 'salary' , 'val' => 1 ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame( 'false' , $result ) ;
        $this->assertSame( [] , $this->binds ) ;
    }

    public function testUrlFieldRejectedWhenNoWhitelistDeclared(): void
    {
        // Fail-closed default: no AQL::FIELDS declared → the URL cannot pick a field.
        $facet  = [ AQL::EDGE => 'balance_edges' ] ;
        $result = $this->stub->callEdgeAggregate( 'balanceSheets' , [ 'agg' => 'max' , 'field' => 'revenue' , 'val' => 1 ] , $this->binds , $facet , AQL::DOC ) ;

        $this->assertSame( 'false' , $result ) ;
    }

    public function testNonCountAggregatorWithoutFieldIsRejected(): void
    {
        $facet = [ AQL::EDGE => 'balance_edges' ] ; // no AQL::FIELDS default, no field in URL

        $this->expectException( ValidationException::class ) ;
        $this->stub->callEdgeAggregate( 'balanceSheets' , [ 'agg' => 'avg' , 'val' => 1 ] , $this->binds , $facet , AQL::DOC ) ;
    }
}
