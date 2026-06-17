<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\enums\Scope;
use oihana\exceptions\UnsupportedOperationException;

use oihana\exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use function oihana\arango\db\helpers\aqlFields;

final class AqlFieldsTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testFieldsWithFilterArray(): void
    {
        $fields = [ 'tags' => [ Field::FILTER => Filter::ARRAY ] ] ;

        $result = aqlFields( $fields ) ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(doc.tags) ? doc.tags : []' ,
            $result
        );

        $fields = [ 'tags' => [ Field::FILTER => Filter::ARRAY , Field::DEFAULT => AQL::NULL ] ] ;

        $result = aqlFields( $fields , 'edge') ;
        $this->assertEquals
        (
            'tags:IS_ARRAY(edge.tags) ? edge.tags : null' ,
            $result
        );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNullFieldsReturnsNull(): void
    {
        $this->assertNull( aqlFields( null ) );
    }

    /**
     * A field declaring Field::REQUIRES is dropped from the projection when
     * the request-scoped authorizer denies its subject.
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testFieldDroppedWhenAuthorizerDenies(): void
    {
        $fields = [ 'secret' => [ Field::REQUIRES => 'x:read' ] , 'name' => [] ];
        $init   = [ Arango::AUTHORIZER => fn() => false ];

        $this->assertSame( 'name:doc.name', aqlFields( $fields, 'doc', null, $init ) );
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersChainWrapsScalarProjection(): void
    {
        $result = aqlFields( [ 'name' => [ Field::ALTERS => [ 'trim' , 'lower' ] ] ] ) ;
        $this->assertSame( 'name:LOWER(TRIM(doc.name))' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersSingleFunctionWithRenamedField(): void
    {
        // Field::NAME points at the source attribute; the output key stays `slug`.
        $result = aqlFields( [ 'slug' => [ Field::NAME => 'title' , Field::ALTERS => 'lower' ] ] ) ;
        $this->assertSame( 'slug:LOWER(doc.title)' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersFunctionWithParams(): void
    {
        $result = aqlFields( [ 'code' => [ Field::ALTERS => [ 'substring' , 0 , 3 ] ] ] ) ;
        $this->assertSame( 'code:SUBSTRING(doc.code,0,3)' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersMixedChainOfPlainAndParameterizedFunctions(): void
    {
        // A chain may mix bare functions and function-with-params, applied left
        // to right (the last one wraps): TRIM(doc.x) → SUBSTRING(…,0,3) → LOWER(…).
        $result = aqlFields( [ 'code' => [ Field::ALTERS => [ 'trim' , [ 'substring' , 0 , 3 ] , 'lower' ] ] ] ) ;
        $this->assertSame( 'code:LOWER(SUBSTRING(TRIM(doc.code),0,3))' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersHonoursTheDocumentReference(): void
    {
        $result = aqlFields( [ 'name' => [ Field::ALTERS => 'upper' ] ] , 'edge' ) ;
        $this->assertSame( 'name:UPPER(edge.name)' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersIsIgnoredOnTypedFilter(): void
    {
        // Option A: alters apply only to the default scalar projection; a typed
        // conversion filter (BOOL) keeps its own shape, alters are not applied.
        $result = aqlFields( [ 'active' => [ Field::FILTER => Filter::BOOL , Field::ALTERS => 'lower' ] ] ) ;
        $this->assertSame( 'active:TO_BOOL(doc.active)' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAltersOnlyAffectsTheOptingFieldInAMix(): void
    {
        $result = aqlFields
        ([
            'name'  => [ Field::ALTERS => 'upper' ] ,
            'price' => [ Field::FILTER => Filter::NUMBER ] ,
            'city'  => [] ,
        ]) ;
        $this->assertSame( 'name:UPPER(doc.name), price:TO_NUMBER(doc.price), city:doc.city' , $result ) ;
    }

    // ========================================
    // Field::QUOTED — double-quote the projected key
    // ========================================

    /**
     * `Field::QUOTED` is designed to be paired with `Field::NAME`: the output
     * label is quoted while the value reads the real (aliased) attribute.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testQuotedKeyWithNameQuotesOnlyTheLabel(): void
    {
        $result = aqlFields( [ 'slug' => [ Field::FILTER => Filter::DEFAULT , Field::NAME => 'title' , Field::QUOTED => true ] ] ) ;
        $this->assertSame( '"slug":doc.title' , $result ) ;
    }

    /**
     * With `Field::ALTERS`, the value side uses the unquoted field reference, so
     * the quoted label combines cleanly with the alt chain.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testQuotedKeyWithAltersQuotesOnlyTheLabel(): void
    {
        $result = aqlFields( [ 'name' => [ Field::FILTER => Filter::DEFAULT , Field::ALTERS => 'lower' , Field::QUOTED => true ] ] ) ;
        $this->assertSame( '"name":LOWER(doc.name)' , $result ) ;
    }

    /**
     * `Field::QUOTED` without `Field::NAME`/`Field::ALTERS`: the output label is
     * the double-quoted key, and the attribute access uses BACKTICKS — a
     * special-character attribute is `doc.`my-key``, never `doc."my-key"`
     * (which is invalid AQL).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testQuotedKeyWithoutNameBacktickQuotesTheAttributeAccess(): void
    {
        $result = aqlFields( [ 'my-key' => [ Field::FILTER => Filter::DEFAULT , Field::QUOTED => true ] ] ) ;
        $this->assertSame( '"my-key":doc.`my-key`' , $result ) ;
    }

    /**
     * The backtick attribute access also applies under a typed filter
     * (here BOOL): `"flag":TO_BOOL(doc.`flag`)`.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testQuotedKeyUnderTypedFilterBacktickQuotesTheAttributeAccess(): void
    {
        $result = aqlFields( [ 'flag' => [ Field::FILTER => Filter::BOOL , Field::QUOTED => true ] ] ) ;
        $this->assertSame( '"flag":TO_BOOL(doc.`flag`)' , $result ) ;
    }

    /**
     * Defense-in-depth: an unsafe bare source attribute (would flow into
     * `doc.<attr>`) is rejected.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     */
    public function testInjectionInBareFieldKeyThrows(): void
    {
        $this->expectException( ValidationException::class ) ;
        aqlFields( [ 'name) RETURN doc //' => [] ] ) ;
    }

    /**
     * The `Field::NAME` alias (a bare source attribute) is validated too.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     */
    public function testInjectionInFieldNameAliasThrows(): void
    {
        $this->expectException( ValidationException::class ) ;
        aqlFields( [ 'slug' => [ Field::NAME => 'title) || 1==1' ] ] ) ;
    }

    // ========================================
    // Field::SCOPE — project from the traversal edge instead of the vertex
    // ========================================

    /**
     * Field::SCOPE => Scope::EDGE reads the field from the edge reference
     * (5th argument) instead of the vertex reference.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeEdgeProjectsFromTheEdgeReference(): void
    {
        $result = aqlFields
        (
            [ 'weight' => [ Field::FILTER => Filter::NUMBER , Field::SCOPE => Scope::EDGE ] ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame( 'weight:TO_NUMBER(e_1.weight)' , $result ) ;
    }

    /**
     * Scope::EDGE equals AQL::EDGE, so the two forms are interchangeable.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeEdgeAcceptsTheAqlEdgeConstant(): void
    {
        $this->assertSame( Scope::EDGE , AQL::EDGE ) ;

        $result = aqlFields
        (
            [ 'role' => [ Field::SCOPE => AQL::EDGE ] ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame( 'role:e_1.role' , $result ) ;
    }

    /**
     * A vertex field and an edge field with the same source attribute name
     * coexist in a single flat projection by giving the edge one a distinct
     * output label via Field::NAME.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeEdgeMixedWithVertexFieldsAndAlias(): void
    {
        $result = aqlFields
        (
            [
                'name'  => [] ,
                'since' => [ Field::FILTER => Filter::DATETIME , Field::NAME => 'created' , Field::SCOPE => Scope::EDGE ] ,
                'edgeName' => [ Field::NAME => 'name' , Field::SCOPE => Scope::EDGE ] ,
            ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame
        (
            'name:v_1.name, since:IS_DATESTRING(e_1.created) ? DATE_FORMAT(e_1.created,"%yyyy-%mm-%ddT%hh:%ii:%ssZ") : null, edgeName:e_1.name' ,
            $result
        ) ;
    }

    /**
     * An explicit Scope::VERTEX (or no scope) keeps the default vertex
     * projection — fully backward compatible.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeVertexIsTheDefault(): void
    {
        $explicit = aqlFields( [ 'name' => [ Field::SCOPE => Scope::VERTEX ] ] , 'v_1' , null , [] , 'e_1' ) ;
        $implicit = aqlFields( [ 'name' => [] ] , 'v_1' , null , [] , 'e_1' ) ;

        $this->assertSame( 'name:v_1.name' , $explicit ) ;
        $this->assertSame( 'name:v_1.name' , $implicit ) ;
    }

    /**
     * Field::SCOPE => edge outside an edge traversal (no edge reference)
     * is a definition error and throws.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeEdgeWithoutEdgeReferenceThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        aqlFields( [ 'weight' => [ Field::SCOPE => Scope::EDGE ] ] ) ;
    }

    /**
     * Field::SCOPE => edge on a structural/variable-backed filter (here EDGES)
     * has no effect, so it is rejected rather than silently ignored.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testScopeEdgeOnStructuralFilterThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        aqlFields
        (
            [ 'friends' => [ Field::FILTER => Filter::EDGES , Field::SCOPE => Scope::EDGE ] ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
    }

    /**
     * Filter::WRAP nests the projected reference under a named key — the
     * symmetric companion of Field::SCOPE. Inside an edge traversal it lets a
     * definition hoist the traversal vertex under a key (e.g. `subject`)
     * alongside the edge metadata (`role` via Scope::EDGE), instead of
     * flattening the vertex fields at the root.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWrapNestsTheVertexBesideEdgeMetadata(): void
    {
        $result = aqlFields
        (
            [
                'role'    => [ Field::SCOPE  => Scope::EDGE ] ,
                'subject' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::FIELDS =>
                    [
                        'id'        => [] ,
                        'givenName' => [] ,
                    ]
                ] ,
            ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame
        (
            'role:e_1.role, subject:{id:v_1.id, givenName:v_1.givenName}' ,
            $result
        ) ;
    }

    /**
     * Filter::WRAP with Field::SCOPE => Scope::EDGE wraps the edge reference
     * itself under the key.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWrapAgainstTheEdgeScope(): void
    {
        $result = aqlFields
        (
            [
                'link' =>
                [
                    Field::FILTER => Filter::WRAP ,
                    Field::SCOPE  => Scope::EDGE ,
                    Field::FIELDS => [ 'role' => [] ] ,
                ] ,
            ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame( 'link:{role:e_1.role}' , $result ) ;
    }

    /**
     * Filter::WRAP with Field::RAW => true embeds the whole reference as-is.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWrapRawEmbedsTheWholeVertex(): void
    {
        $result = aqlFields
        (
            [ 'subject' => [ Field::FILTER => Filter::WRAP , Field::RAW => true ] ] ,
            'v_1' , null , [] , 'e_1'
        ) ;
        $this->assertSame( 'subject:v_1' , $result ) ;
    }

    // ---------------------------------------------------------------- Field::WHEN (conditional projection)

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenDispatchesConditionalProjection(): void
    {
        $result = aqlFields( [ 'price' => [ Field::WHEN => [ 'visibility' , 'public' ] ] ] , 'doc' ) ;
        $this->assertSame( "price:doc.visibility == 'public' ? doc.price : null" , $result ) ;
    }

    /**
     * WHEN composes with ALTERS (then branch) and NAME (source alias); the condition
     * attribute is independent of the projected one.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenComposesWithAltersAndName(): void
    {
        $result = aqlFields
        ([
            'slug' =>
            [
                Field::NAME   => 'title' ,
                Field::WHEN   => [ 'published' , 'eq' , true ] ,
                Field::ALTERS => [ 'trim' , 'lower' ] ,
            ],
        ], 'doc' ) ;
        $this->assertSame( 'slug:doc.published == true ? LOWER(TRIM(doc.title)) : null' , $result ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenWithElseAttributeFallback(): void
    {
        $result = aqlFields
        ([
            'price' =>
            [
                Field::WHEN => [ 'visibility' , 'public' ] ,
                Field::ELSE => [ Field::PROPERTY => 'basePrice' ] ,
            ],
        ], 'doc' ) ;
        $this->assertSame( "price:doc.visibility == 'public' ? doc.price : doc.basePrice" , $result ) ;
    }

    /**
     * The condition reads from the same reference as the projected field.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenUsesTheProjectionReference(): void
    {
        $result = aqlFields( [ 'role' => [ Field::WHEN => [ 'active' , true ] ] ] , 'v_1' ) ;
        $this->assertSame( 'role:v_1.active == true ? v_1.role : null' , $result ) ;
    }

    /**
     * Field::WHEN on a typed/structural filter is a definition error.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWhenOnStructuralFilterThrows(): void
    {
        $this->expectException( UnsupportedOperationException::class ) ;
        $this->expectExceptionMessage( 'only valid on the default scalar projection' ) ;
        aqlFields( [ 'tags' => [ Field::FILTER => Filter::EDGES , Field::WHEN => [ 'a' , 'b' ] ] ] , 'doc' ) ;
    }
}
