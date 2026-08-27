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
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;
use oihana\enums\Boolean;
use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

/**
 * A `geo` field filters by distance at every depth.
 *
 * The flat lookup listed six filter types, the hierarchical walk listed five, and
 * `geo` was the missing one — so a model keeping its coordinates in a sub-record
 * (`address.geo` rather than `geo`) could not filter by distance at all: the clause
 * was dropped and "places within 5 km" answered the whole collection, in `200`.
 *
 * 🔑 **Written as pairs**, the root spelling beside the nested one, because that is
 * where this grammar's defects live: the same key routed by two roads, agreeing or
 * not. A full root × depth matrix over the filter types and operators found exactly
 * one disagreement, and this was it.
 */
class FilterNestedGeoTest extends TestCase
{
    private Container $container;
    private array $binds;

    private const array POINT = [ 'latitude' => 48.8566 , 'longitude' => 2.3522 ] ;

    protected function setUp(): void
    {
        $this->container = new Container() ;
        $this->container->set( LoggerInterface::class , new NullLogger() ) ;
        $this->binds = [] ;
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    private function model( array $fields = [] ): Documents
    {
        return new Documents( $this->container ,
        [
            AQL::COLLECTION => 'places' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'geo'     => FilterType::GEO ,
                'address' =>
                [
                    AQL::TYPE    => Filter::DOCUMENT ,
                    AQL::FILTERS =>
                    [
                        'geo'   => FilterType::GEO ,
                        'inner' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'geo' => FilterType::GEO ] ] ,
                    ] ,
                ] ,
            ] ,
            ...( $fields === [] ? [] : [ AQL::FIELDS => $fields ] ) ,
        ]);
    }

    /**
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    private function compile( array $init , ?Documents $model = null ) :?string
    {
        $binds = [] ;
        return ( $model ?? $this->model() )->prepareFilter( $init , $binds ) ;
    }

    // ========================================
    // THE PAIRS
    // ========================================

    /**
     * The same distance question, at both depths, names the coordinates through the
     * reference the object walk reached.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testADistanceFilterCompilesAtBothDepths(): void
    {
        $root   = $this->compile( [ 'key' => 'geo'         , 'op' => 'distance' , 'val' => self::POINT , 'max' => 5000 ] ) ;
        $nested = $this->compile( [ 'key' => 'address.geo' , 'op' => 'distance' , 'val' => self::POINT , 'max' => 5000 ] ) ;

        $this->assertStringContainsString( 'DISTANCE(doc.geo.latitude,doc.geo.longitude,' , (string) $root ) ;
        $this->assertStringContainsString( 'DISTANCE(doc.address.geo.latitude,doc.address.geo.longitude,' , (string) $nested ) ;

        // Same shape, the reference apart — which is the only thing depth may change.
        $shape = fn( ?string $aql ) => preg_replace( [ '/@\w+/' , '/doc(\.\w+)*\.geo/' ] , [ '@v' , 'GEO' ] , (string) $aql ) ;

        $this->assertSame( $shape( $root ) , $shape( $nested ) ) ;
    }

    /**
     * Two levels down, to say the reference is carried rather than guessed at one hop.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testItReachesThroughTwoObjects(): void
    {
        $this->assertStringContainsString
        (
            'DISTANCE(doc.address.inner.geo.latitude,doc.address.inner.geo.longitude,' ,
            (string) $this->compile( [ 'key' => 'address.inner.geo' , 'op' => 'distance' , 'val' => self::POINT , 'max' => 5000 ] )
        ) ;
    }

    /**
     * The annulus — `min` and `max` together — compiles at both depths too, so the
     * whole operator travelled, not just its simplest form.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheAnnulusCompilesAtBothDepths(): void
    {
        foreach ( [ 'geo' , 'address.geo' ] as $key )
        {
            $result = (string) $this->compile( [ 'key' => $key , 'op' => 'distance' , 'val' => self::POINT , 'min' => 1000 , 'max' => 5000 ] ) ;

            $this->assertStringContainsString( '>=' , $result , $key ) ;
            $this->assertStringContainsString( '<=' , $result , $key ) ;
        }
    }

    // ========================================
    // PERMISSION — already correct, pinned so it stays that way
    // ========================================

    /**
     * A locked `geo` neutralises to `false` at both depths.
     *
     * ⚠ This was already true before the arm existed, and it is why the defect was a
     * dropped filter rather than a permission hole: the gate runs **before** the
     * handler lookup, so a refused path never reached the missing branch. Pinned here
     * because the arm now sits behind that gate and must stay there.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testALockedGeoNeutralisesToFalseAtBothDepths(): void
    {
        $model = $this->model
        ([
            'geo'     => [ Field::REQUIRES => 'geo:read' ] ,
            'address' => [ Field::FIELDS => [ 'geo' => [ Field::REQUIRES => 'geo:read' ] ] ] ,
        ]) ;

        foreach ( [ 'geo' , 'address.geo' ] as $key )
        {
            $result = $this->compile
            (
                [
                    Arango::FILTER     => [ 'key' => $key , 'op' => 'distance' , 'val' => self::POINT , 'max' => 5000 ] ,
                    Arango::AUTHORIZER => fn() => false ,
                ] ,
                $model
            ) ;

            $this->assertSame( Boolean::FALSE , $result , $key ) ;
        }
    }

    // ========================================
    // "NO CLAUSE" IS null, AT BOTH DEPTHS
    // ========================================

    /**
     * An operator this filter cannot honour, and a value that is not a point, both
     * answer `null` — the shape the composition layer drops — rather than the empty
     * string, which travels on as though it were a condition.
     *
     * Asserted at both depths so the two cannot drift: the nested seat inherits
     * whatever the flat one returns.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testNoClauseIsNullAtBothDepths(): void
    {
        foreach ( [ 'geo' , 'address.geo' ] as $key )
        {
            $this->assertNull( $this->compile( [ 'key' => $key , 'op' => 'eq' , 'val' => self::POINT ] ) , $key ) ;
            $this->assertNull( $this->compile( [ 'key' => $key , 'op' => 'distance' , 'val' => 'not a point' ] ) , $key ) ;
        }
    }

    /**
     * ⚠ The frontier: a valid point with **no radius** still answers the empty string,
     * at both depths, because it comes from `buildBetweenClauses()` — shared with the
     * `between` operator, and therefore one arbitration for two callers. Left to be
     * decided once, with both in view.
     *
     * @throws BindException
     * @throws ReflectionException
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAMissingRadiusStillYieldsTheSharedEmptyString(): void
    {
        foreach ( [ 'geo' , 'address.geo' ] as $key )
        {
            $this->assertSame( '' , $this->compile( [ 'key' => $key , 'op' => 'distance' , 'val' => self::POINT ] ) , $key ) ;
        }
    }
}
