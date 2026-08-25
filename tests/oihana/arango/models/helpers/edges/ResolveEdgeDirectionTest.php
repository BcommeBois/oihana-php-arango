<?php

namespace tests\oihana\arango\models\helpers\edges;

use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\Traversal;

use oihana\reflect\exceptions\ConstantException;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\edges\resolveEdgeDirection;

/**
 * Coverage for {@see resolveEdgeDirection()} — the single reading of
 * `AQL::DIRECTION` shared by the edge surfaces: the projection preamble
 * ({@see \oihana\arango\models\helpers\edges\resolveEdgeContext()}), the nested
 * relation walker ({@see \oihana\arango\models\helpers\extractNestedRelations()})
 * and the hierarchical filters.
 *
 * @package tests\oihana\arango\models\helpers\edges
 * @author  Marc Alcaraz
 */
final class ResolveEdgeDirectionTest extends TestCase
{
    public function testDefaultsToOutbound() :void
    {
        $this->assertSame( Traversal::OUTBOUND , resolveEdgeDirection( [] ) ) ;
    }

    public function testHonorsEachDeclaredKeyword() :void
    {
        $this->assertSame( Traversal::OUTBOUND , resolveEdgeDirection( [ AQL::DIRECTION => Traversal::OUTBOUND ] ) ) ;
        $this->assertSame( Traversal::INBOUND  , resolveEdgeDirection( [ AQL::DIRECTION => Traversal::INBOUND  ] ) ) ;
        $this->assertSame( Traversal::ANY      , resolveEdgeDirection( [ AQL::DIRECTION => Traversal::ANY      ] ) ) ;
    }

    /**
     * The whole point of the helper: `Traversal::get()` used to fold an
     * unrecognised value back on the default, so a mistyped `'OUTBOUD'` compiled
     * to `OUTBOUND` without a word — and on an inbound relation that silence is
     * an empty projection in `200`.
     */
    public function testRefusesAnUnknownKeyword() :void
    {
        $this->expectException( ConstantException::class ) ;

        resolveEdgeDirection( [ AQL::DIRECTION => 'OUTBOUD' ] ) ;
    }

    public function testRefusesALowercasedKeyword() :void
    {
        $this->expectException( ConstantException::class ) ;

        resolveEdgeDirection( [ AQL::DIRECTION => 'outbound' ] ) ;
    }

    /**
     * A declared `null` is not a declaration: it means "say nothing", and must
     * keep the default rather than be refused — the shape `?? null` leaves behind
     * in a host configuration.
     */
    public function testExplicitNullFallsBackOnTheDefault() :void
    {
        $this->assertSame( Traversal::OUTBOUND , resolveEdgeDirection( [ AQL::DIRECTION => null ] ) ) ;
    }
}
