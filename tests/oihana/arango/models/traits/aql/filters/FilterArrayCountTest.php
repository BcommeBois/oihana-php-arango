<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * An `alt` chain on a key declared as a list reads that key as a list.
 *
 * `COUNT()` and `LENGTH()` are the only two catalogue functions defined on strings
 * as well as arrays. Every other one — `FIRST`, `SUM`, `MIN`, `UNIQUE`,
 * `COUNT_DISTINCT` … — answers `null` when a document stores something that is not
 * a list under a list-declared key, which is the honest answer. Those two answer
 * the **character count** instead: `"backend"` reports seven tags.
 *
 * The comparison that matters is not "at least three tags", which merely returns
 * noise. It is `== 0` — *which records have no tags* — where the malformed records
 * were **left out**, and they are exactly the ones the question is looking for.
 *
 * 🔑 **Handing the chain a list-or-nothing key settles it without a list of function
 * names to maintain**: `IS_ARRAY(k) ? k : []` makes a non-array the empty list, so
 * the count is 0, while a real array passes through untouched and every function
 * answers exactly what it answered before.
 *
 * ⚠ **The obvious spelling, `doc.tags[*]`, does not work** — and that is pinned below
 * too, because it is the shape anyone would reach for first. A bracket expression
 * after `[*]` binds to every ELEMENT rather than to the list, so a function that is
 * itself a projection (`pluck`) reads "of each field of each item" and answers null.
 *
 * ⚠ Two frontiers are deliberately not crossed, and both are pinned below. They are
 * not caution: each was measured, and each would have broken something.
 */
class FilterArrayCountTest extends TestCase
{
    private Documents $model;
    private array $binds;

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    protected function setUp(): void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $this->model = new Documents( $container ,
        [
            AQL::COLLECTION => 'tickets' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'tags'  => FilterType::ARRAY  ,
                'name'  => FilterType::STRING ,
                'resolution' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS => [ 'steps' => FilterType::ARRAY ] ,
                ] ,
            ]
        ]);

        $this->binds = [] ;
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function compile( array $init ) :string
    {
        return (string) $this->model->prepareFilter( $init , $this->binds ) ;
    }

    // ========================================
    // THE FIX
    // ========================================

    /**
     * The two functions that used to answer a character count now count elements.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testCountAndLengthReadTheKeyAsAList(): void
    {
        $this->assertStringStartsWith
        (
            'COUNT((IS_ARRAY(doc.tags) ? doc.tags : []))' ,
            $this->compile( [ 'key' => 'tags' , 'op' => 'ge' , 'val' => 3 , 'alt' => 'count' ] )
        ) ;

        $this->binds = [] ;

        $this->assertStringStartsWith
        (
            'LENGTH((IS_ARRAY(doc.tags) ? doc.tags : []))' ,
            $this->compile( [ 'key' => 'tags' , 'op' => 'eq' , 'val' => 0 , 'alt' => 'length' ] )
        ) ;
    }

    /**
     * The same on a list declared inside an object, which reaches the array filter
     * through the hierarchical walk rather than the flat lookup.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testANestedListIsReadAsAListToo(): void
    {
        $this->assertStringStartsWith
        (
            'COUNT((IS_ARRAY(doc.resolution.steps) ? doc.resolution.steps : []))' ,
            $this->compile( [ 'key' => 'resolution.steps' , 'op' => 'ge' , 'val' => 2 , 'alt' => 'count' ] )
        ) ;
    }

    // ========================================
    // FRONTIER 1 — the `at` index
    // ========================================

    /**
     * ⚠ The index form keeps the bare attribute.
     *
     * It carries a bracket of its own, and stacking a guard in front of it would meet
     * the same fate as `doc.tags[*][0]` — a projection of `[0]` out of every element,
     * so `['x','y']` answers `[null,null]`. It needs no help anyway: `doc.tags[0]`
     * already answers `null` on a string, like the rest of the catalogue.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheIndexFormIsLeftAlone(): void
    {
        $result = $this->compile( [ 'key' => 'tags' , 'at' => 0 , 'val' => 'X' , 'alt' => 'upper' ] ) ;

        $this->assertStringStartsWith( 'UPPER(doc.tags[0])' , $result ) ;
        $this->assertStringNotContainsString( '[*]' , $result ) ;
    }

    // ========================================
    // FRONTIER 2 — the comparison itself
    // ========================================

    /**
     * ⚠ Only the key the chain wraps is guarded — never the compared key.
     *
     * Widening it would turn `doc.tags == []` from "the stored list is empty" into
     * "there is no list here either", and carry `ALL` and `AT LEAST` with it: measured
     * on seven documents, four selected became seven. That is another change, and not
     * this one.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAComparisonWithoutAnAltIsLeftAlone(): void
    {
        $this->assertSame
        (
            'doc.tags' ,
            explode( ' ' , $this->compile( [ 'key' => 'tags' , 'val' => [ 'a' , 'b' ] ] ) )[ 0 ]
        ) ;

        $this->binds = [] ;

        // The quantified form moved to the question-mark operator in its own batch, but
        // the point here is unchanged: the key it reads is the bare attribute, not the
        // list-or-nothing guard, because no `alt` chain wraps it.
        $this->assertStringStartsWith
        (
            'doc.tags[? ALL FILTER CURRENT' ,
            $this->compile( [ 'key' => 'tags' , 'val' => 'x' , 'quant' => 'all' ] )
        ) ;
    }

    /**
     * ⚠ And an `alt` that only wraps the **value** leaves the key alone too — the
     * chain never touches it, so there is nothing to read as a list.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAValueSideAltLeavesTheKeyAlone(): void
    {
        $result = $this->compile( [ 'key' => 'tags' , 'val' => 'X' , 'alt' => [ 'val' => 'upper' ] ] ) ;

        $this->assertStringStartsWith( 'doc.tags ==' , $result ) ;
        $this->assertStringNotContainsString( 'doc.tags[*]' , $result ) ;
    }


    /**
     * 🚨 The regression the live suite caught, pinned here so it cannot come back.
     *
     * `pluck` compiles to an inline projection, `k[* RETURN CURRENT.<field>]`. Written
     * against the expansion — `doc.items[*][* RETURN CURRENT.price]` — the second
     * bracket binds to every ELEMENT rather than to the list, so it reads "the price of
     * each field of each item" and `AVERAGE()` answers `null` where the plain form
     * answers 100. That is why the guard is a ternary and not a `[*]`: it carries no
     * bracket, so anything may be wrapped around it.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAProjectionFunctionCanStillBeWrappedAroundTheKey(): void
    {
        $result = $this->compile
        (
            [ 'key' => 'tags' , 'op' => 'ge' , 'val' => 100 , 'alt' => [ [ 'pluck' , 'price' ] , 'avg' ] ]
        ) ;

        $this->assertStringStartsWith
        (
            'AVERAGE((IS_ARRAY(doc.tags) ? doc.tags : [])[* RETURN CURRENT.price])' ,
            $result
        ) ;

        // The shape that would have broken it: a bracket stacked on a bracket.
        $this->assertStringNotContainsString( '[*][' , $result ) ;
    }

    // ========================================
    // WHAT MUST NOT MOVE
    // ========================================

    /**
     * A `count` on a **string** key still counts characters, which is what it means
     * there and what the catalogue documents. The fix is conditioned on the declared
     * type, not on the function name — this is the case a blanket change would have
     * broken.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testCountOnAStringKeyStillCountsCharacters(): void
    {
        $result = $this->compile( [ 'key' => 'name' , 'op' => 'ge' , 'val' => 4 , 'alt' => 'count' ] ) ;

        $this->assertStringStartsWith( 'COUNT(doc.name)' , $result ) ;
        $this->assertStringNotContainsString( '[*]' , $result ) ;
    }

    /**
     * The functions that were already honest keep their shape — they now read `[*]`
     * of the same array, which is that same array, so nothing they answer moves.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheHonestFunctionsAreCarriedAlongUnchanged(): void
    {
        foreach ( [ 'first' => 'FIRST' , 'sum' => 'SUM' , 'min' => 'MIN' , 'sorted' => 'SORTED' ] as $alt => $fn )
        {
            $binds = [] ;

            $result = (string) $this->model->prepareFilter
            (
                [ 'key' => 'tags' , 'val' => 'x' , 'alt' => $alt ] ,
                $binds
            ) ;

            $this->assertStringStartsWith( $fn . '((IS_ARRAY(doc.tags) ? doc.tags : []))' , $result ) ;
        }
    }
}
