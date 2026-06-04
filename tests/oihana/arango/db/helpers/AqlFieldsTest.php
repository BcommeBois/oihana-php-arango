<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\exceptions\UnsupportedOperationException;
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
}
