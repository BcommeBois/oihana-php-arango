<?php

namespace tests\oihana\arango\controllers\traits;

use oihana\arango\controllers\traits\CountByDimensionTrait;
use oihana\arango\models\Documents;

use ReflectionClass;

use PHPUnit\Framework\TestCase;

/**
 * Host exposing the protected {@see CountByDimensionTrait::countByDimension()}.
 */
class CountByDimensionHost
{
    use CountByDimensionTrait ;

    public function call( Documents $model , string $dimension , array $init = [] ) :array
    {
        return $this->countByDimension( $model , $dimension , $init ) ;
    }
}

/**
 * A real {@see Documents} subclass whose {@see FakeCountModel::facetCount()}
 * returns a canned map, so {@see CountByDimensionTrait::countByDimension()} is
 * tested in isolation. `Documents` cannot be doubled by PHPUnit's generator, so
 * this hand-written fake (instantiated without its heavy constructor) stands in.
 */
class FakeCountModel extends Documents
{
    public array $canned = [] ;

    public function facetCount( string $dimension , array $init = [] ) :array
    {
        return $this->canned ;
    }
}

/**
 * Unit coverage for {@see CountByDimensionTrait}: shapes a model's flat facet
 * map into a `{ total, counts }` payload, the total being the sum of the counts.
 */
class CountByDimensionTraitTest extends TestCase
{
    private function host() :CountByDimensionHost
    {
        return new CountByDimensionHost() ;
    }

    private function model( array $facetCount ) :Documents
    {
        $model = new ReflectionClass( FakeCountModel::class )->newInstanceWithoutConstructor() ;
        $model->canned = $facetCount ;

        return $model ;
    }

    public function testShapesTotalAndCounts() :void
    {
        $model = $this->model( [ 'products' => 12 , 'customers' => 3 ] ) ;

        $this->assertSame(
            [ 'total' => 15 , 'counts' => [ 'products' => 12 , 'customers' => 3 ] ] ,
            $this->host()->call( $model , 'additionalType' )
        ) ;
    }

    public function testEmptyMapYieldsZeroTotal() :void
    {
        $model = $this->model( [] ) ;

        $this->assertSame(
            [ 'total' => 0 , 'counts' => [] ] ,
            $this->host()->call( $model , 'additionalType' )
        ) ;
    }
}
