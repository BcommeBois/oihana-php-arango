<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\ValidationException;

use PHPUnit\Framework\TestCase;

use ReflectionException;

use function oihana\arango\db\helpers\expandArrayPath;

/**
 * Coverage for {@see expandArrayPath()} — the `[*]` unwinding shared by the
 * bounds and facet-count sub-queries: one `FOR` hop per marker, then the
 * reference of the projected leaf.
 *
 * The behaviour pinned here is the one both builders relied on before the
 * extraction, so the emitted hops are expected byte-for-byte. Two of these
 * branches — the dotted intermediate container and the empty leaf — were only
 * exercised through the facet-count side; they now hold for both consumers.
 *
 * @package tests\oihana\arango\db\helpers
 * @author  Marc Alcaraz
 */
final class ExpandArrayPathTest extends TestCase
{
    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testSingleHopProjectsTheLeafOnTheItem() :void
    {
        [ $fors , $value ] = expandArrayPath( 'offers[*].price' , AQL::DOC ) ;

        $this->assertSame( [ 'FOR item IN doc.offers' ] , $fors  ) ;
        $this->assertSame( 'item.price'                 , $value ) ;
    }

    /**
     * Each marker opens its own hop, relative to the previous item — and the
     * numbering starts at the bare name: there is no `item1`.
     *
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testEveryMarkerOpensItsOwnHop() :void
    {
        [ $fors , $value ] = expandArrayPath( 'offers[*].tiers[*].amount' , AQL::DOC ) ;

        $this->assertSame
        (
            [
                'FOR item IN doc.offers'  ,
                'FOR item2 IN item.tiers' ,
            ] ,
            $fors
        ) ;

        $this->assertSame( 'item2.amount' , $value ) ;
    }

    /**
     * A dotted intermediate container is walked as a whole: only the markers
     * open hops, the dots stay inside the attribute path.
     *
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testDottedIntermediateContainerStaysOneHop() :void
    {
        [ $fors , $value ] = expandArrayPath( 'a[*].b.c[*].d' , AQL::DOC ) ;

        $this->assertSame
        (
            [
                'FOR item IN doc.a'      ,
                'FOR item2 IN item.b.c'  ,
            ] ,
            $fors
        ) ;

        $this->assertSame( 'item2.d' , $value ) ;
    }

    /**
     * A path ending on the marker has no leaf: the element itself is projected.
     *
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testTrailingMarkerProjectsTheElementItself() :void
    {
        [ $fors , $value ] = expandArrayPath( 'tags[*]' , AQL::DOC ) ;

        $this->assertSame( [ 'FOR item IN doc.tags' ] , $fors  ) ;
        $this->assertSame( 'item'                     , $value ) ;
    }

    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testPathWithoutMarkerOpensNoHopAtAll() :void
    {
        [ $fors , $value ] = expandArrayPath( 'width' , AQL::DOC ) ;

        $this->assertSame( []         , $fors  ) ;
        $this->assertSame( 'doc.width' , $value ) ;
    }

    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testItemReferenceIsConfigurable() :void
    {
        [ $fors , $value ] = expandArrayPath( 'a[*].b[*].c' , AQL::DOC , 'node' ) ;

        $this->assertSame
        (
            [
                'FOR node IN doc.a'   ,
                'FOR node2 IN node.b' ,
            ] ,
            $fors
        ) ;

        $this->assertSame( 'node2.c' , $value ) ;
    }

    /**
     * The first hop starts from whatever reference the caller is standing on.
     *
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testFirstHopStartsFromTheGivenDocumentReference() :void
    {
        [ $fors , $value ] = expandArrayPath( 'offers[*].price' , 'vertex' ) ;

        $this->assertSame( [ 'FOR item IN vertex.offers' ] , $fors  ) ;
        $this->assertSame( 'item.price'                    , $value ) ;
    }

    /**
     * A doubled marker leaves an empty intermediate container — rejected, like
     * any other invalid attribute name, rather than emitted as `FOR item2 IN item.`.
     *
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testDoubledMarkerIsRejected() :void
    {
        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessageIsOrContains( 'Invalid AQL attribute name' ) ;

        expandArrayPath( 'a[*][*]' , AQL::DOC ) ;
    }

    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testUnsafeContainerIsRejected() :void
    {
        $this->expectException( ValidationException::class ) ;

        expandArrayPath( 'a b[*].price' , AQL::DOC ) ;
    }

    /**
     * @throws ReflectionException
     * @throws ValidationException
     */
    public function testUnsafeLeafIsRejected() :void
    {
        $this->expectException( ValidationException::class ) ;

        expandArrayPath( 'offers[*].price OR 1==1' , AQL::DOC ) ;
    }
}
