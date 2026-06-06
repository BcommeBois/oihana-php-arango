<?php

namespace tests\oihana\arango\models\helpers;

use RuntimeException;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\utils\FilterPath;

use PHPUnit\Framework\TestCase;

use function oihana\arango\models\helpers\parseFilterSegment;

/**
 * Characterization coverage for {@see parseFilterSegment()} — resolves a single
 * filter path segment against the filter configuration, returning a
 * {@see FilterPath} or null (not allowed / malformed) and throwing when a
 * declared edge/join relation is missing.
 *
 * @package tests\oihana\arango\models\helpers
 * @author  Marc Alcaraz
 */
final class ParseFilterSegmentTest extends TestCase
{
    public function testUnknownSegmentReturnsNull() :void
    {
        $this->assertNull( parseFilterSegment( 'ghost' , [] ) ) ;
    }

    public function testStringConfigBuildsALeafFilterPath() :void
    {
        $result = parseFilterSegment( 'name' , [ 'name' => 'string' ] ) ;

        $this->assertInstanceOf( FilterPath::class , $result ) ;
    }

    public function testNonArrayNonStringConfigReturnsNull() :void
    {
        // config is an int → neither string/callable nor array → null
        $this->assertNull( parseFilterSegment( 'x' , [ 'x' => 42 ] ) ) ;
    }

    public function testArrayConfigWithoutTypeReturnsNull() :void
    {
        $this->assertNull( parseFilterSegment( 'x' , [ 'x' => [ 'whatever' => 1 ] ] ) ) ;
    }

    public function testArrayNotationMismatchReturnsNull() :void
    {
        // type EDGES needs the array notation, but the segment has none → mismatch
        $this->assertNull( parseFilterSegment( 'rel' , [ 'rel' => [ AQL::TYPE => Filter::EDGES ] ] ) ) ;
    }

    public function testMissingEdgeRelationThrows() :void
    {
        $this->expectException( RuntimeException::class ) ;

        // type EDGE (no array notation needed) but no matching edges config
        parseFilterSegment( 'rel' , [ 'rel' => [ AQL::TYPE => Filter::EDGE ] ] , [] , [] ) ;
    }
}
