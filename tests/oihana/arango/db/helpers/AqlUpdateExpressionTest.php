<?php

namespace tests\oihana\arango\db\helpers;

use InvalidArgumentException;

use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\helpers\aqlUpdateExpression;

final class AqlUpdateExpressionTest extends TestCase
{
    /**
     * @throws UnsupportedOperationException
     */
    public function testUpdateExpression(): void
    {
        $this->assertSame
        (
            "UPDATE {foo:'bar'}",
            aqlUpdateExpression( [ AQL::UPDATE => [ [ 'foo' , 'bar' ] ] ] )
        );
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testThrowsWhenUpdateMissing(): void
    {
        $this->expectException( InvalidArgumentException::class );
        $this->expectExceptionMessage( 'UPDATE option is required' );
        aqlUpdateExpression( [] );
    }
}
