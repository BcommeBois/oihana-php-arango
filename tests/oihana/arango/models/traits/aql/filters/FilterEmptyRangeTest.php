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
 * A range with no bound expresses no constraint, and no constraint is `null`.
 *
 * The case is an unfilled "from … to …" widget, and the caller did nothing wrong by
 * sending it. It used to answer three different wrong things depending on how the
 * front-end serialised the empty fields:
 *
 * - keys omitted → the **empty string**, which is not `null`: it travelled through
 *   the composition as though it were a condition and reached the server as
 *   `FILTER  RETURN` — a syntax error on its own or inside an `OR`;
 * - `{"min":null,"max":null}` → a comparison **against null**, which selects the
 *   records holding no value at all. "Prices between nothing and nothing" answered
 *   "records without a price", plausibly, in `200`;
 * - a date with neither bound → `>= NOW && <= NOW`, a clause that can never match.
 *
 * 🔑 **Inside an `AND` the behaviour was already right** — the empty condition was
 * dropped and the page worked. That is what settled the design: refusing with a
 * `400` would have broken a form that works today, for a caller who is not at fault.
 * The other two contexts were aligned on the one that was already correct.
 */
class FilterEmptyRangeTest extends TestCase
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
            AQL::COLLECTION => 'products' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'name'    => FilterType::STRING ,
                'price'   => FilterType::NUMBER ,
                'created' => FilterType::DATE   ,
                'geo'     => FilterType::GEO    ,
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
    private function compile( array $init ) :?string
    {
        $binds = [] ;
        return $this->model->prepareFilter( $init , $binds ) ;
    }

    // ========================================
    // NO BOUND AT ALL
    // ========================================

    /**
     * Every surface answers `null`, and none answers the empty string.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNoBoundYieldsNoClauseOnEverySurface(): void
    {
        $cases =
        [
            'string' => [ 'key' => 'name'    , 'op' => 'between' ] ,
            'number' => [ 'key' => 'price'   , 'op' => 'between' ] ,
            'date'   => [ 'key' => 'created' , 'op' => 'between' ] ,
            'geo'    => [ 'key' => 'geo'     , 'op' => 'distance' , 'val' => [ 'latitude' => 48.85 , 'longitude' => 2.35 ] ] ,
        ];

        foreach ( $cases as $label => $init )
        {
            $result = $this->compile( $init ) ;

            $this->assertNull( $result , $label ) ;
            $this->assertNotSame( '' , $result , $label ) ;
        }
    }

    /**
     * 🚨 An explicitly null bound is not a bound.
     *
     * `{"min":null,"max":null}` is what an unfilled widget serialises, and it used to
     * count as two bounds — compiling a comparison against null, which selects the
     * records holding no value. Omitting the keys and sending them null must mean the
     * same thing, and now do.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnExplicitlyNullBoundIsNotABound(): void
    {
        $this->assertNull( $this->compile( [ 'key' => 'price' , 'op' => 'between' , 'min' => null , 'max' => null ] ) ) ;

        // The two spellings of "nothing filled in" agree.
        $this->assertSame
        (
            $this->compile( [ 'key' => 'price' , 'op' => 'between' ] ) ,
            $this->compile( [ 'key' => 'price' , 'op' => 'between' , 'min' => null , 'max' => null ] )
        ) ;
    }

    /**
     * Half filled in is still a constraint, and a null on the other side does not
     * turn it into one.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testOneRealBoundStillConstrains(): void
    {
        $this->assertStringStartsWith
        (
            'doc.price >=' ,
            (string) $this->compile( [ 'key' => 'price' , 'op' => 'between' , 'min' => 10 ] )
        ) ;

        // min given, max explicitly null → still the one-sided range, not a null test.
        $withNullMax = (string) $this->compile( [ 'key' => 'price' , 'op' => 'between' , 'min' => 10 , 'max' => null ] ) ;

        $this->assertStringStartsWith( 'doc.price >=' , $withNullMax ) ;
        $this->assertStringNotContainsString( '<=' , $withNullMax ) ;
    }

    // ========================================
    // THE DATE DEFAULT — kept where it is useful
    // ========================================

    /**
     * ⚠ Dates fill the missing side with "now", which is what makes `min` alone mean
     * "from X until now". That default is preserved — it only stops applying when
     * there is no real bound at all, where it used to compile `>= NOW && <= NOW`: a
     * clause that can never match anything.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheDateDefaultSurvivesWhereItMeansSomething(): void
    {
        $result = (string) $this->compile( [ 'key' => 'created' , 'op' => 'between' , 'min' => '2026-01-01' ] ) ;

        $this->assertStringContainsString( 'doc.created >=' , $result ) ;
        $this->assertStringContainsString( 'DATE_NOW()' , $result ) ;

        // With no bound at all, there is nothing for "until now" to complete.
        $this->assertNull( $this->compile( [ 'key' => 'created' , 'op' => 'between' ] ) ) ;
    }

    // ========================================
    // COMPOSITION — the context that was already right, pinned
    // ========================================

    /**
     * The `AND` behaved correctly all along: the empty range is dropped and the rest of
     * the filter stands. Pinned because it is the reference the other two contexts were
     * aligned on — and because a `400` would have broken it.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnEmptyRangeIsDroppedFromAnAndAndTheRestStands(): void
    {
        $result = (string) $this->compile
        ([
            [ 'key' => 'price' , 'op' => 'between' ] ,
            [ 'key' => 'name'  , 'val' => 'x' ] ,
        ]) ;

        $this->assertStringContainsString( 'doc.name ==' , $result ) ;
        $this->assertStringNotContainsString( 'doc.price' , $result ) ;
    }

    /**
     * And the `OR`, which used to answer the empty string and therefore a syntax error,
     * now keeps the branch that says something.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnEmptyRangeIsDroppedFromAnOrToo(): void
    {
        $result = (string) $this->compile
        ([
            'or' ,
            [ 'key' => 'price' , 'op' => 'between' ] ,
            [ 'key' => 'name'  , 'val' => 'x' ] ,
        ]) ;

        $this->assertStringContainsString( 'doc.name ==' , $result ) ;
        $this->assertStringNotContainsString( 'doc.price' , $result ) ;
        $this->assertNotSame( '' , $result ) ;
    }

    /**
     * The empty range alone is the case that used to reach the server as
     * `FILTER  RETURN`. It is now `null`, which the reading layer knows to mean "no
     * condition" — the same thing the `AND` above has always done with it.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnEmptyRangeOnItsOwnIsNoConditionAtAll(): void
    {
        $this->assertNull( $this->compile( [ 'key' => 'price' , 'op' => 'between' ] ) ) ;
    }
}
