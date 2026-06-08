<?php

namespace tests\oihana\arango\models\traits\aql\facets;

use PHPUnit\Framework\TestCase;

use oihana\arango\models\enums\filters\FilterParam;
use oihana\arango\models\traits\aql\facets\HasFacetAggregateConditions;

/**
 * Coverage for the empty-threshold short-circuit of
 * {@see HasFacetAggregateConditions::prepareAggregateConditions()}: a null or
 * empty value produces no AQL fragment.
 */
final class HasFacetAggregateConditionsTest extends TestCase
{
    /**
     * A bare null threshold yields an empty fragment (no aggregate condition).
     *
     * @throws \oihana\exceptions\BindException
     * @throws \oihana\exceptions\ValidationException
     */
    public function testNullValueReturnsEmptyString(): void
    {
        $binds  = [] ;
        $result = $this->host()->call( null , [] , 'edge' , null , 'doc' , 'rating' , $binds ) ;
        $this->assertSame( '' , $result ) ;
    }

    /**
     * A request object whose `val` is null resolves to the same short-circuit.
     *
     * @throws \oihana\exceptions\BindException
     * @throws \oihana\exceptions\ValidationException
     */
    public function testRequestObjectWithNullValReturnsEmptyString(): void
    {
        $binds  = [] ;
        $result = $this->host()->call( [ FilterParam::VAL => null ] , [] , 'edge' , null , 'doc' , 'rating' , $binds ) ;
        $this->assertSame( '' , $result ) ;
    }

    /**
     * A minimal host exposing the protected trait method.
     */
    private function host(): object
    {
        return new class
        {
            use HasFacetAggregateConditions ;

            public function call( mixed $value , array $facet , string $for , ?string $prefix , string $docRef , string $key , array &$binds ): string
            {
                return $this->prepareAggregateConditions( $value , $facet , $for , $prefix , $docRef , $key , $binds ) ;
            }
        } ;
    }
}
