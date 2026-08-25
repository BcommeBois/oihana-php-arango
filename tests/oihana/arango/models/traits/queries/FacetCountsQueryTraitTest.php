<?php

namespace tests\oihana\arango\models\traits\queries;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\queries\FacetCountsQueryTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

/**
 * Host composing {@see ListQueryTrait} (filter/bind machinery) and
 * {@see FacetCountsQueryTrait} (the builder under test).
 */
class FacetCountsQueryTraitStub
{
    use ListQueryTrait , FacetCountsQueryTrait ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'articles' ;
        $this->facets =
        [
            'category'      => [ Facet::TYPE => Facet::FIELD ] ,
            'status'        => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => 'state' ] ,
            'keywords'      => [ Facet::TYPE => Facet::IN ] ,
            'ranking'       => [ Facet::TYPE => Facet::EDGE_AGGREGATE , AQL::EDGE => 'rankings' ] , // a threshold, not a dimension → skipped
            'currency'      => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].priceCurrency' ] , // object-array sub-field
            'currencyField' => [ Facet::TYPE => Facet::FIELD , Facet::PROPERTY => 'offers[*].priceCurrency' ] , // [*] overrides FIELD
            'tags'          => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'tags[*]' ] , // expansion marker, no sub-field
            'deep'          => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b[*].c' ] , // multi-level → one FOR per hop
            'deepMid'       => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b.c[*].d' ] , // intermediate path between hops
            'danger'        => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].x);y' ] , // dangerous sub-field → guarded

            // Facet::DISTINCT => true : count distinct ROOT documents per bucket
            // (COUNT_DISTINCT(doc._key)) instead of the unwound elements.
            'currencyDistinct' => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].priceCurrency' , Facet::DISTINCT => true ] , // [*] sub-field
            'keywordsDistinct' => [ Facet::TYPE => Facet::IN    , Facet::DISTINCT => true ] , // IN family unwind
            'deepDistinct'     => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'a[*].b[*].c' , Facet::DISTINCT => true ] , // multi-hop → distinct still on root _key
            'categoryDistinct' => [ Facet::TYPE => Facet::FIELD , Facet::DISTINCT => true ] , // scalar FIELD → flag is a no-op

            // Linked facets: the bucket is a field of the RELATED document,
            // named by Facet::VALUE (default _key).
            'location'         => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'organizations_places' ] , // default bucket: the vertex _key
            'locationName'     => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'organizations_places' , Facet::VALUE => 'name' ] ,
            'locationDistinct' => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'organizations_places' , Facet::VALUE => 'name' , Facet::DISTINCT => true ] ,
            'edgeMissing'      => [ Facet::TYPE => Facet::EDGE ] , // no edge collection declared → refused
            'edgeDanger'       => [ Facet::TYPE => Facet::EDGE , AQL::EDGE => 'organizations_places' , Facet::VALUE => 'x);y' ] , // dangerous bucket → refused

            'author'           => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , Facet::VALUE => 'name' ] ,
            'authorKey'        => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' ] , // default bucket: the joined _key
            'authorDistinct'   => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , Facet::VALUE => 'name' , Facet::DISTINCT => true ] ,
            'comments'         => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'comments' , AQL::KEY => 'articleId' , Facet::PROPERTY => '_key' , Facet::VALUE => 'lang' ] , // reverse one-to-many
            'tagsJoin'         => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'tags' , AQL::ARRAY => true , Facet::PROPERTY => 'tagIds' , Facet::VALUE => 'name' ] , // main side holds an array of keys
            'joinMissing'      => [ Facet::TYPE => Facet::JOIN , Facet::PROPERTY => 'authorId' ] , // no joined collection declared → refused
            'joinExpansion'    => [ Facet::TYPE => Facet::JOIN , AQL::COLLECTION => 'tags' , Facet::PROPERTY => 'tagIds[*]' ] , // a linked facet never unwinds → refused

            // Facet::LIMIT => n : keep only the n biggest buckets. Declared on
            // one facet of each family, since they all end on the same tail.
            'categoryTop'      => [ Facet::TYPE => Facet::FIELD , Facet::LIMIT => 10 ] ,
            'keywordsTop'      => [ Facet::TYPE => Facet::IN    , Facet::LIMIT => 5 ] ,
            'currencyTop'      => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].priceCurrency' , Facet::LIMIT => 3 ] ,
            'locationTop'      => [ Facet::TYPE => Facet::EDGE  , AQL::EDGE => 'organizations_places' , Facet::VALUE => 'name' , Facet::LIMIT => 2 ] ,
            'authorTop'        => [ Facet::TYPE => Facet::JOIN  , AQL::COLLECTION => 'authors' , Facet::PROPERTY => 'authorId' , Facet::VALUE => 'name' , Facet::LIMIT => 4 ] ,
            'sellerTop'        => [ Facet::TYPE => Facet::IN    , Facet::PROPERTY => 'offers[*].sellerId' , Facet::DISTINCT => true , Facet::LIMIT => 7 ] , // limit + distinct
            'limitZero'        => [ Facet::TYPE => Facet::FIELD , Facet::LIMIT => 0 ] ,      // would emit NO limit at all → refused
            'limitNegative'    => [ Facet::TYPE => Facet::FIELD , Facet::LIMIT => -3 ] ,     // idem
            'limitString'      => [ Facet::TYPE => Facet::FIELD , Facet::LIMIT => '10' ] ,   // not an integer → refused
        ] ;
    }
}

/**
 * Unit coverage for {@see FacetCountsQueryTrait::buildFacetCountsQuery()} — the
 * multi-`LET` facet-counts query, one counting sub-query per whitelisted facet.
 */
class FacetCountsQueryTraitTest extends TestCase
{
    private function stub() :FacetCountsQueryTraitStub
    {
        return new FacetCountsQueryTraitStub() ;
    }

    public function testEmptyWhenNoDimensions() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [] , $binds ) ) ;
    }

    public function testUnknownDimensionIsIgnored() :void
    {
        $binds = [] ;
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'nope' ] , $binds ) ) ;
    }

    public function testUnsupportedFacetTypeIsSkipped() :void
    {
        $binds = [] ;
        // An aggregate facet compares a threshold, it does not name a dimension —
        // there is no bucket to count, so it stays skipped.
        $this->assertSame( '' , $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'ranking' ] , $binds ) ) ;
    }

    public function testFieldFacet() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {category}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testFieldFacetWithPropertyOverride() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET status = (FOR doc IN @@collection COLLECT value = doc.state WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {status}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'status' ] , $binds ) ,
        ) ;
    }

    public function testInFacetUnwindsArray() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET keywords = (FOR doc IN @@collection FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {keywords}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'keywords' ] , $binds ) ,
        ) ;
    }

    public function testMultipleDimensionsSkipUnsupported() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'LET keywords = (FOR doc IN @@collection FOR item IN doc.keywords COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'RETURN {category, keywords}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category,ranking,keywords' ] , $binds ) ,
        ) ;
    }

    public function testCountsShareTheListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection FILTER doc.active==1 COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {category}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category' ] , $binds ) ,
        ) ;
    }

    public function testArraySubFieldUnwindsAndProjects() :void
    {
        $binds = [] ;
        $this->assertSame
        (
            'LET currency = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currency}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testArrayExpansionMarkerOverridesFieldType() :void
    {
        $binds = [] ;
        // Declared FIELD, but the `[*]` marker forces the unwind (D1).
        $this->assertSame
        (
            'LET currencyField = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currencyField}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyField' ] , $binds ) ,
        ) ;
    }

    public function testArrayExpansionWithoutSubFieldProjectsItem() :void
    {
        $binds = [] ;
        // `tags[*]` (no sub-field) projects the element itself (D4).
        $this->assertSame
        (
            'LET tags = (FOR doc IN @@collection FOR item IN doc.tags COLLECT value = item WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {tags}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'tags' ] , $binds ) ,
        ) ;
    }

    public function testArraySubFieldInheritsListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        $this->assertSame
        (
            'LET currency = (FOR doc IN @@collection FILTER doc.active==1 FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {currency}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currency' ] , $binds ) ,
        ) ;
    }

    public function testMultiLevelExpansionUnwindsNestedArrays() :void
    {
        $binds = [] ;
        // `a[*].b[*].c` is a two-hop expansion → one FOR per hop, counted per leaf.
        $this->assertSame
        (
            'LET deep = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b COLLECT value = item2.c WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {deep}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deep' ] , $binds ) ,
        ) ;
    }

    public function testIntermediatePathBetweenExpansions() :void
    {
        $binds = [] ;
        // `a[*].b.c[*].d` → the path between two hops descends within the element.
        $this->assertSame
        (
            'LET deepMid = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b.c COLLECT value = item2.d WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {deepMid}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deepMid' ] , $binds ) ,
        ) ;
    }

    public function testDangerousSubFieldIsGuarded() :void
    {
        // The sub-field is config-trusted but still guarded by assertAttributeName.
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'danger' ] , $binds ) ;
    }

    public function testDistinctArraySubFieldCountsRootDocuments() :void
    {
        $binds = [] ;
        // Facet::DISTINCT => true on a `[*]` sub-field: the tail aggregates
        // COUNT_DISTINCT(doc._key) instead of WITH COUNT — a document repeating
        // the same priceCurrency across several offers is counted once.
        $this->assertSame
        (
            'LET currencyDistinct = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {currencyDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyDistinct' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testDistinctInFamilyCountsRootDocuments() :void
    {
        $binds = [] ;
        // The IN/LIST family unwinds too, so it over-counts the same way — the
        // flag applies there as well.
        $this->assertSame
        (
            'LET keywordsDistinct = (FOR doc IN @@collection FOR item IN doc.keywordsDistinct COLLECT value = item AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {keywordsDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'keywordsDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctMultiHopAlwaysTargetsRootKey() :void
    {
        $binds = [] ;
        // Whatever the `[*]` hop depth, the distinct always targets the ROOT
        // document key (doc._key), never the intermediate item references.
        $this->assertSame
        (
            'LET deepDistinct = (FOR doc IN @@collection FOR item IN doc.a FOR item2 IN item.b COLLECT value = item2.c AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {deepDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'deepDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctIsNoOpOnScalarFieldFacet() :void
    {
        $binds = [] ;
        // A scalar FIELD already emits one row per document, so DISTINCT cannot
        // over-count: the flag is ignored and the default WITH COUNT tail stays.
        $this->assertSame
        (
            'LET categoryDistinct = (FOR doc IN @@collection COLLECT value = doc.categoryDistinct WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {categoryDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'categoryDistinct' ] , $binds ) ,
        ) ;
    }

    public function testDistinctInheritsListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        // The distinct sub-query shares the same conjunctive filter as the list.
        $this->assertSame
        (
            'LET currencyDistinct = (FOR doc IN @@collection FILTER doc.active==1 FOR item IN doc.offers COLLECT value = item.priceCurrency AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {currencyDistinct}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'currencyDistinct' ] , $binds ) ,
        ) ;
    }

    public function testEdgeFacetCountsLinkedVerticesByKey() :void
    {
        $binds = [] ;
        // A linked dimension counts the vertices the facet already filters on:
        // the traversal is the source, and the bucket defaults to the vertex _key.
        $this->assertSame
        (
            'LET location = (FOR doc IN @@collection FOR doc_location IN INBOUND doc organizations_places COLLECT value = doc_location._key WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {location}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'location' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testEdgeFacetBucketsOnTheDeclaredVertexField() :void
    {
        $binds = [] ;
        // Facet::VALUE names the vertex field feeding `value`, so the bucket is
        // self-sufficient (a label, not a key the UI must resolve again).
        $this->assertSame
        (
            'LET locationName = (FOR doc IN @@collection FOR doc_locationName IN INBOUND doc organizations_places COLLECT value = doc_locationName.name WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {locationName}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'locationName' ] , $binds ) ,
        ) ;
    }

    public function testEdgeFacetInheritsListFilter() :void
    {
        $stub = $this->stub() ;
        $stub->conditions = [ 'doc.active==1' ] ;

        $binds = [] ;
        // The traversal hangs off the shared filtered scope, so the buckets tally
        // over exactly the displayed set.
        $this->assertSame
        (
            'LET location = (FOR doc IN @@collection FILTER doc.active==1 FOR doc_location IN INBOUND doc organizations_places COLLECT value = doc_location._key WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {location}' ,
            $stub->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'location' ] , $binds ) ,
        ) ;
    }

    public function testEdgeFacetDistinctCountsRootDocuments() :void
    {
        $binds = [] ;
        // Two parallel edges to the same vertex unwind into two rows, so a
        // traversal over-counts exactly like an array — DISTINCT applies.
        $this->assertSame
        (
            'LET locationDistinct = (FOR doc IN @@collection FOR doc_locationDistinct IN INBOUND doc organizations_places COLLECT value = doc_locationDistinct.name AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {locationDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'locationDistinct' ] , $binds ) ,
        ) ;
    }

    public function testJoinFacetCountsJoinedDocuments() :void
    {
        $binds = [] ;
        // The key-join opens the joined collection and narrows it with the join
        // predicate — the same anchor the filtering facet uses.
        $this->assertSame
        (
            'LET author = (FOR doc IN @@collection FOR doc_author IN authors FILTER doc_author._key == doc.authorId COLLECT value = doc_author.name WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {author}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'author' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds ) ;
    }

    public function testJoinFacetDefaultsToTheJoinedKey() :void
    {
        $binds = [] ;
        // No Facet::VALUE: the bucket is the joined document's _key.
        $this->assertSame
        (
            'LET authorKey = (FOR doc IN @@collection FOR doc_authorKey IN authors FILTER doc_authorKey._key == doc.authorId COLLECT value = doc_authorKey._key WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {authorKey}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'authorKey' ] , $binds ) ,
        ) ;
    }

    public function testJoinFacetOnAnArrayOfKeysUsesMembership() :void
    {
        $binds = [] ;
        // AQL::ARRAY: the main side holds an array of keys, so the join predicate
        // becomes a membership test — inherited from resolveFacetJoin().
        $this->assertSame
        (
            'LET tagsJoin = (FOR doc IN @@collection FOR doc_tagsJoin IN tags FILTER doc_tagsJoin._key IN doc.tagIds COLLECT value = doc_tagsJoin.name WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {tagsJoin}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'tagsJoin' ] , $binds ) ,
        ) ;
    }

    public function testJoinFacetOnAReverseOneToMany() :void
    {
        $binds = [] ;
        // The joined side carries the foreign key (AQL::KEY): the documents
        // referencing this one are counted, bucketed on one of their fields.
        $this->assertSame
        (
            'LET comments = (FOR doc IN @@collection FOR doc_comments IN comments FILTER doc_comments.articleId == doc._key COLLECT value = doc_comments.lang WITH COUNT INTO count SORT count DESC RETURN {value, count}) RETURN {comments}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'comments' ] , $binds ) ,
        ) ;
    }

    public function testJoinFacetDistinctCountsRootDocuments() :void
    {
        $binds = [] ;
        // Several joined documents per main document over-count the bucket the
        // same way an unwound array does.
        $this->assertSame
        (
            'LET authorDistinct = (FOR doc IN @@collection FOR doc_authorDistinct IN authors FILTER doc_authorDistinct._key == doc.authorId COLLECT value = doc_authorDistinct.name AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC RETURN {value, count}) RETURN {authorDistinct}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'authorDistinct' ] , $binds ) ,
        ) ;
    }

    public function testLinkedFacetsCombineWithDocumentSideDimensions() :void
    {
        $binds = [] ;
        // One LET per dimension, whatever the source: a document field, an array,
        // a traversal and a join sit side by side in the same query.
        $this->assertSame
        (
            'LET category = (FOR doc IN @@collection COLLECT value = doc.category WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'LET location = (FOR doc IN @@collection FOR doc_location IN INBOUND doc organizations_places COLLECT value = doc_location._key WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'LET author = (FOR doc IN @@collection FOR doc_author IN authors FILTER doc_author._key == doc.authorId COLLECT value = doc_author.name WITH COUNT INTO count SORT count DESC RETURN {value, count}) '
            . 'RETURN {category, location, author}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category,location,author' ] , $binds ) ,
        ) ;
    }

    public function testMissingEdgeCollectionIsRefused() :void
    {
        // A declaration with no edge collection would compile to a truncated
        // `FOR … IN INBOUND doc` — refused instead of emitted.
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'edgeMissing' ] , $binds ) ;
    }

    public function testMissingJoinedCollectionIsRefused() :void
    {
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'joinMissing' ] , $binds ) ;
    }

    public function testDangerousBucketFieldIsGuarded() :void
    {
        // Facet::VALUE is interpolated into the COLLECT, so it is guarded like
        // any other declared attribute name.
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'edgeDanger' ] , $binds ) ;
    }

    public function testLimitKeepsTheBiggestBucketsOnAScalarField() :void
    {
        $binds = [] ;
        // The LIMIT closes the tail AFTER the sort, so what survives is the n
        // biggest buckets — not an arbitrary n of them.
        $this->assertSame
        (
            'LET categoryTop = (FOR doc IN @@collection COLLECT value = doc.categoryTop WITH COUNT INTO count SORT count DESC LIMIT 10 RETURN {value, count}) RETURN {categoryTop}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'categoryTop' ] , $binds ) ,
        ) ;
        $this->assertSame( [ '@collection' => 'articles' ] , $binds , 'The limit is written in clear, not bound: the dimensions share one bind map.' ) ;
    }

    public function testLimitAppliesToEveryFacetFamily() :void
    {
        // One tail, every type: the unwound array, the `[*]` sub-field, the edge
        // traversal and the key-join all honour the same declaration.
        foreach (
        [
            'keywordsTop' => 'LET keywordsTop = (FOR doc IN @@collection FOR item IN doc.keywordsTop COLLECT value = item WITH COUNT INTO count SORT count DESC LIMIT 5 RETURN {value, count}) RETURN {keywordsTop}' ,
            'currencyTop' => 'LET currencyTop = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.priceCurrency WITH COUNT INTO count SORT count DESC LIMIT 3 RETURN {value, count}) RETURN {currencyTop}' ,
            'locationTop' => 'LET locationTop = (FOR doc IN @@collection FOR doc_locationTop IN INBOUND doc organizations_places COLLECT value = doc_locationTop.name WITH COUNT INTO count SORT count DESC LIMIT 2 RETURN {value, count}) RETURN {locationTop}' ,
            'authorTop'   => 'LET authorTop = (FOR doc IN @@collection FOR doc_authorTop IN authors FILTER doc_authorTop._key == doc.authorId COLLECT value = doc_authorTop.name WITH COUNT INTO count SORT count DESC LIMIT 4 RETURN {value, count}) RETURN {authorTop}' ,
        ] as $dimension => $expected )
        {
            $binds = [] ;
            $this->assertSame( $expected , $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => $dimension ] , $binds ) , $dimension ) ;
        }
    }

    public function testLimitCombinesWithDistinct() :void
    {
        $binds = [] ;
        // The two options are independent: the count is per document, and the
        // LIMIT still keeps the biggest of those per-document buckets.
        $this->assertSame
        (
            'LET sellerTop = (FOR doc IN @@collection FOR item IN doc.offers COLLECT value = item.sellerId AGGREGATE count = COUNT_DISTINCT(doc._key) SORT count DESC LIMIT 7 RETURN {value, count}) RETURN {sellerTop}' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'sellerTop' ] , $binds ) ,
        ) ;
    }

    public function testAbsentLimitEmitsNoLimitClause() :void
    {
        $binds = [] ;
        // The default is unchanged, byte for byte: no LIMIT, all the buckets.
        $this->assertStringNotContainsString
        (
            'LIMIT' ,
            $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'category,keywords,location,author' ] , $binds ) ,
        ) ;
    }

    /**
     * A limit that cannot be honoured is refused rather than ignored: aqlLimit()
     * answers an empty string to 0 or less, so such a declaration would emit no
     * LIMIT at all and silently return EVERY bucket — the opposite of what it asks.
     */
    public function testUnhonourableLimitIsRefused() :void
    {
        foreach ( [ 'limitZero' , 'limitNegative' , 'limitString' ] as $dimension )
        {
            $binds = [] ;
            try
            {
                $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => $dimension ] , $binds ) ;
                $this->fail( 'A limit of "' . $dimension . '" must be refused, not ignored.' ) ;
            }
            catch ( ValidationException $exception )
            {
                $this->assertStringContainsString( $dimension , $exception->getMessage() , 'The refusal must name the faulty facet.' ) ;
            }
        }
    }

    public function testLinkedFacetNeverUnwindsTheMainDocument() :void
    {
        // On a linked facet, Facet::PROPERTY is the join's main side, not a path
        // to unwind: an `[*]` marker there is a mis-declaration, refused rather
        // than silently counting the raw keys instead of the joined documents.
        $this->expectException( ValidationException::class ) ;
        $binds = [] ;
        $this->stub()->buildFacetCountsQuery( [ Arango::FACET_COUNTS => 'joinExpansion' ] , $binds ) ;
    }
}
