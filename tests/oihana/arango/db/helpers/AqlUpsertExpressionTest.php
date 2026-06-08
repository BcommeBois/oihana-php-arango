<?php

namespace tests\oihana\arango\db\helpers;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlUpsertExpression;

final class AqlUpsertExpressionTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testSearchExpression(): void
    {
        $this->assertSame
        (
            "UPSERT {foo:'bar'}",
            aqlUpsertExpression( [ AQL::SEARCH => [ [ 'foo' , 'bar' ] ] ] )
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testFilterExpression(): void
    {
        $this->assertSame
        (
            'UPSERT FILTER foo && bar',
            aqlUpsertExpression( [ AQL::FILTER => [ [ 'foo' , 'bar' ] ] ] )
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testThrowsWhenNeitherFilterNorSearch(): void
    {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Either FILTER or SEARCH option is required.' );
        aqlUpsertExpression( [] );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testThrowsWhenBothFilterAndSearch(): void
    {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'FILTER and SEARCH cannot be defined at the same time.' );
        aqlUpsertExpression
        ([
            AQL::FILTER => [ [ 'foo' , 'bar' ] ] ,
            AQL::SEARCH => [ [ 'foo' , 'bar' ] ] ,
        ]);
    }
}
