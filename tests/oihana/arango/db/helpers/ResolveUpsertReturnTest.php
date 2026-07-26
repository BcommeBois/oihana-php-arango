<?php

namespace tests\oihana\arango\db\helpers;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Clause;
use oihana\arango\db\enums\UpsertType;

use PHPUnit\Framework\TestCase;

use function oihana\arango\db\helpers\resolveUpsertReturn;

/**
 * Coverage for {@see resolveUpsertReturn()} — the `RETURN` expression shared by
 * {@see \oihana\arango\db\operations\aqlUpsert()} and
 * {@see \oihana\arango\db\operations\aqlRepsert()}, and the expansion of the
 * {@see Clause::WITH_STATUS} shorthand.
 *
 * The two write types are pinned side by side here; before the extraction each
 * was asserted at a different level (the operation for `update`, the query trait
 * for `replace`), so nothing showed that they differ by that word alone.
 *
 * @package tests\oihana\arango\db\helpers
 * @author  Marc Alcaraz
 */
final class ResolveUpsertReturnTest extends TestCase
{
    public function testDefaultsToNew() :void
    {
        $this->assertSame( Clause::NEW , resolveUpsertReturn( [] , UpsertType::REPLACE ) ) ;
        $this->assertSame( Clause::NEW , resolveUpsertReturn( [] , UpsertType::UPDATE  ) ) ;
    }

    public function testWithStatusReportsTheReplaceWriteHalf() :void
    {
        $this->assertSame
        (
            "{ doc: NEW , type: OLD ? 'replace' : 'insert' }" ,
            resolveUpsertReturn( [ AQL::RETURN => Clause::WITH_STATUS ] , UpsertType::REPLACE )
        ) ;
    }

    public function testWithStatusReportsTheUpdateWriteHalf() :void
    {
        $this->assertSame
        (
            "{ doc: NEW , type: OLD ? 'update' : 'insert' }" ,
            resolveUpsertReturn( [ AQL::RETURN => Clause::WITH_STATUS ] , UpsertType::UPDATE )
        ) ;
    }

    /**
     * The insert half never varies: only the caller's write type moves.
     */
    public function testTheInsertHalfIsAlwaysInsert() :void
    {
        foreach ( [ UpsertType::REPLACE , UpsertType::UPDATE ] as $writeType )
        {
            $this->assertStringEndsWith
            (
                ": 'insert' }" ,
                resolveUpsertReturn( [ AQL::RETURN => Clause::WITH_STATUS ] , $writeType )
            ) ;
        }
    }

    /**
     * Anything else is the caller's own expression and travels untouched.
     */
    public function testAnyOtherExpressionIsPassedThrough() :void
    {
        $this->assertSame( Clause::OLD , resolveUpsertReturn( [ AQL::RETURN => Clause::OLD ] , UpsertType::UPDATE ) ) ;

        $this->assertSame
        (
            '{ key: NEW._key }' ,
            resolveUpsertReturn( [ AQL::RETURN => '{ key: NEW._key }' ] , UpsertType::REPLACE )
        ) ;
    }

    /**
     * `AQL::RETURN` is not typed, so a non-string expression reaches the caller
     * as it was given — the shorthand match is strict.
     */
    public function testNonStringExpressionIsNotCoerced() :void
    {
        $expression = [ 'NEW' , 'OLD' ] ;

        $this->assertSame( $expression , resolveUpsertReturn( [ AQL::RETURN => $expression ] , UpsertType::UPDATE ) ) ;
    }
}
