<?php

namespace tests\oihana\arango\models\helpers;

use RuntimeException;

use oihana\arango\exceptions\RequestValidationException;
use oihana\exceptions\ValidationException;

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
    public function testAnUnknownSegmentIsRefusedNamingIt() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "ghost" is not filterable.' ) ;

        parseFilterSegment( 'ghost' , [] ) ;
    }

    public function testAnUnknownSegmentInDepthNamesItsPath() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "ghost" is not filterable at "address.geo".' ) ;

        parseFilterSegment( 'ghost' , [] , parentPath : [ 'address' , 'geo' ] ) ;
    }

    public function testStringConfigBuildsALeafFilterPath() :void
    {
        $result = parseFilterSegment( 'name' , [ 'name' => 'string' ] ) ;

        $this->assertInstanceOf( FilterPath::class , $result ) ;
    }

    /**
     * From here on the key IS declared : what is wrong is wrong in the model, and the
     * exception says so — a plain ValidationException, never the request's `400` kind.
     */
    public function testANonArrayNonStringConfigIsTheModelsFault() :void
    {
        try
        {
            parseFilterSegment( 'x' , [ 'x' => 42 ] ) ;
            $this->fail( 'A misdeclared filter must not parse.' ) ;
        }
        catch ( ValidationException $exception )
        {
            $this->assertNotInstanceOf( RequestValidationException::class , $exception ) ;
            $this->assertSame( 'The filter "x" is misdeclared : expected a FilterType constant, a callable, or an array carrying AQL::TYPE, got int.' , $exception->getMessage() ) ;
        }
    }

    public function testAnArrayConfigWithoutTypeIsTheModelsFault() :void
    {
        try
        {
            parseFilterSegment( 'x' , [ 'x' => [ 'whatever' => 1 ] ] , parentPath : [ 'address' ] ) ;
            $this->fail( 'A misdeclared filter must not parse.' ) ;
        }
        catch ( ValidationException $exception )
        {
            $this->assertNotInstanceOf( RequestValidationException::class , $exception ) ;
            $this->assertSame( 'The filter "x" is misdeclared at "address" : its array definition carries no AQL::TYPE.' , $exception->getMessage() ) ;
        }
    }

    public function testAListNamedWithoutItsMarkerIsRefusedWithItsSpelling() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "rel" is a list : write it "rel[*]".' ) ;

        // type EDGES needs the array notation, but the segment has none
        parseFilterSegment( 'rel' , [ 'rel' => [ AQL::TYPE => Filter::EDGES ] ] ) ;
    }

    public function testAnObjectNamedWithTheMarkerIsRefusedWithItsSpelling() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'The filter key "doc" is not a list : write it "doc", without "[*]".' ) ;

        parseFilterSegment( 'doc[*]' , [ 'doc' => [ AQL::TYPE => Filter::DOCUMENT ] ] ) ;
    }

    public function testMissingEdgeRelationThrows() :void
    {
        $this->expectException( RuntimeException::class ) ;

        // type EDGE (no array notation needed) but no matching edges config
        parseFilterSegment( 'rel' , [ 'rel' => [ AQL::TYPE => Filter::EDGE ] ] , [] , [] ) ;
    }
}
