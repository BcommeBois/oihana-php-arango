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
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * Every `@placeholder` an `alt` chain declares must have a bind to fill it.
 *
 * This is not a claim about the AQL *text* — the text was always right. It is a
 * claim about the pair `(query, binds)` leaving the builder together, which is
 * the only thing a server can be given. A parameter declared and left unbound is
 * refused whole: `no value specified for declared bind parameter`, a `500` for a
 * caller who did nothing wrong.
 *
 * 🔑 **Why the existing suite could not see it.** The array filter's coverage was
 * cut in two halves that miss each other: the one test sending a *parameter* goes
 * through the `[*]` expansion branch, which was sound, and the tests going through
 * the plain-key branch, which was not, all used parameter-less functions
 * (`upper`, `count`). The crossing — a parameter on the plain-key branch — existed
 * nowhere. The date filter had the same hole. So the assertion here is deliberately
 * made on the invariant rather than on a shape: **every placeholder is accounted
 * for**, whatever the chain happens to emit.
 */
class FilterAltBindsTest extends TestCase
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
                'tags'    => FilterType::ARRAY  ,
                'created' => FilterType::DATE   ,
                'name'    => FilterType::STRING ,
            ]
        ]);

        $this->binds = [] ;
    }

    /**
     * The invariant: no placeholder in the emitted AQL is left without a bind.
     */
    private function assertEveryPlaceholderIsBound( string $aql , array $binds ) :void
    {
        preg_match_all( '/@(\w+)/' , $aql , $matches ) ;

        $declared = array_unique( $matches[ 1 ] ) ;
        $orphans  = array_values( array_diff( $declared , array_keys( $binds ) ) ) ;

        $this->assertNotEmpty( $declared , 'the case must actually declare a parameter, or it proves nothing' ) ;

        $this->assertSame( [] , $orphans , sprintf
        (
            'the query declares %s that no bind fills — ArangoDB refuses it whole. AQL: %s' ,
            implode( ', ' , array_map( fn( $o ) => '@' . $o , $orphans ) ) ,
            $aql
        ) ) ;
    }

    // ========================================
    // SEAT 1 — the array filter's plain-key branch
    // ========================================

    /**
     * A parameterised `alt` on a `FilterType::ARRAY` key written WITHOUT `[*]`.
     *
     * The `[*]` matters: with it the chain goes through the inline expansion, which
     * was always sound. Without it, it goes through `prepareFilterArrayKey()`, which
     * used to hand `alterFilterKey()` an undefined `$binds` — accepted, because the
     * parameter is by reference, and silently discarded.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAParameterisedAltOnAnArrayKeyKeepsItsBind(): void
    {
        $result = $this->model->prepareFilter
        (
            [ 'key' => 'tags' , 'val' => 1 , 'alt' => [ [ 'push' , 'zz' ] ] ] ,
            $this->binds
        ) ;

        $this->assertEveryPlaceholderIsBound( $result , $this->binds ) ;
        $this->assertContains( 'zz' , $this->binds ) ;
    }

    /**
     * The same branch is reached by the `at` index form, and by `quant` — all three
     * call sites of `prepareFilterArrayKey()`, so none of them can regress alone.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheOtherArrayBranchesKeepTheirBindsToo(): void
    {
        $result = $this->model->prepareFilter
        (
            [ 'key' => 'tags' , 'at' => 0 , 'val' => 'x' , 'alt' => [ [ 'ltrim' , '-' ] ] ] ,
            $this->binds
        ) ;

        $this->assertEveryPlaceholderIsBound( $result , $this->binds ) ;

        $binds = [] ;

        $result = $this->model->prepareFilter
        (
            [ 'key' => 'tags' , 'val' => 'x' , 'quant' => 'all' , 'alt' => [ [ 'ltrim' , '-' ] ] ] ,
            $binds
        ) ;

        $this->assertEveryPlaceholderIsBound( $result , $binds ) ;
    }

    // ========================================
    // SEAT 2 — the date filter's key side
    // ========================================

    /**
     * The second seat, found by the guard below rather than by a report: the date
     * filter called `prepareFilterKey()` with two arguments, so the third — the binds
     * map — defaulted to `null` and the key-side chain bound into nothing.
     *
     * `dateSubtract` is the case that shows it: its unit (`'day'`) is bound, while
     * the parameter-less extractors every existing test used (`dateYear`, `dateDay`)
     * had nothing to lose.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAParameterisedAltOnADateKeyKeepsItsBind(): void
    {
        $result = $this->model->prepareFilter
        (
            [ 'key' => 'created' , 'val' => '2026-01-01' , 'alt' => [ [ 'dateSubtract' , 30 , 'day' ] ] ] ,
            $this->binds
        ) ;

        $this->assertEveryPlaceholderIsBound( $result , $this->binds ) ;
        $this->assertContains( 'day' , $this->binds ) ;
    }

    /**
     * The string filter was always sound — pinned so the two seats above cannot be
     * "fixed" by changing something shared that breaks this one instead.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheStringFilterKeepsItsBindAsItAlwaysDid(): void
    {
        $result = $this->model->prepareFilter
        (
            [ 'key' => 'name' , 'val' => 'x' , 'alt' => [ [ 'split' , '-' ] ] ] ,
            $this->binds
        ) ;

        $this->assertEveryPlaceholderIsBound( $result , $this->binds ) ;
    }

    // ========================================
    // THE GUARD
    // ========================================

    /**
     * 🚨 A reading point that does not thread its binds is refused, loudly.
     *
     * `alterExpression()` already refuses a request chain arriving with **no binder**.
     * That check cannot fire here, because a binder is always supplied — it just
     * happened to be built over nothing. This is the missing half: a binder needs
     * somewhere to write, and a `null` binds map is not somewhere.
     *
     * It is what turned the second seat up: the date filter had been silently losing
     * key-side parameters, and adding this guard made its tests fail on the spot.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnAltChainWithNowhereToBindIsRefused(): void
    {
        $binds = null ;

        $this->expectException( ValidationException::class ) ;
        $this->expectExceptionMessage( 'binder over no binds map' ) ;

        $this->model->prepareFilter( [ 'key' => 'name' , 'val' => 'x' , 'alt' => 'lower' ] , $binds ) ;
    }

    /**
     * ⚠ And it stays out of the way of everything else: a filter carrying no `alt`
     * at all still works with no binds map, which is what a caller building a
     * condition it will bind later relies on.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAFilterWithoutAnAltIsUnaffectedByTheGuard(): void
    {
        $binds = null ;

        $result = (string) $this->model->prepareFilter( [ 'key' => 'name' , 'val' => 'x' ] , $binds ) ;

        $this->assertSame( 'doc.name == @' , preg_replace( '/@\w+/' , '@' , $result ) ) ;
    }
}
