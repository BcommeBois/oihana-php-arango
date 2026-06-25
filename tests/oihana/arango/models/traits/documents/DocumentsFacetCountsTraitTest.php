<?php

namespace tests\oihana\arango\models\traits\documents;

use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Facet;
use oihana\arango\models\traits\documents\DocumentsFacetCountsTrait;
use oihana\arango\models\traits\queries\ListQueryTrait;

use Closure;
use org\schema\helpers\SchemaResolver;

use PHPUnit\Framework\TestCase;

/**
 * Host for {@see DocumentsFacetCountsTrait::facetCounts()}. It composes
 * {@see ListQueryTrait} for the filter/bind machinery and stubs the DB access
 * ({@see getFirstResult()}) so the result-shaping logic is tested in isolation.
 */
class DocumentsFacetCountsHost
{
    use ListQueryTrait , DocumentsFacetCountsTrait ;

    public mixed $canned = null ;

    public function __construct()
    {
        $this->initializeQueryID( 'q' ) ;
        $this->collection = 'articles' ;
        $this->facets     = [ 'category' => [ Facet::TYPE => Facet::FIELD ] ] ;
    }

    public function getFirstResult
    (
        string                             $query    ,
        array                              $bindVars = [] ,
        array                              $options  = [] ,
        bool                               $raw      = false ,
        null|SchemaResolver|Closure|string $schema   = null ,
        array                              $context  = [] ,
    )
    : mixed
    {
        return $this->canned ;
    }
}

/**
 * Unit coverage for {@see DocumentsFacetCountsTrait::facetCounts()}.
 */
class DocumentsFacetCountsTraitTest extends TestCase
{
    private function host() :DocumentsFacetCountsHost
    {
        return new DocumentsFacetCountsHost() ;
    }

    public function testReturnsEmptyWhenNothingCountable() :void
    {
        // No Arango::FACET_COUNTS → empty query → no DB call → [].
        $this->assertSame( [] , $this->host()->facetCounts( [] ) ) ;
    }

    public function testObjectResultIsCastToArray() :void
    {
        $host = $this->host() ;
        $host->canned = (object) [ 'category' => [ [ 'value' => 'A' , 'count' => 3 ] ] ] ;

        $this->assertSame(
            [ 'category' => [ [ 'value' => 'A' , 'count' => 3 ] ] ] ,
            $host->facetCounts( [ Arango::FACET_COUNTS => 'category' ] )
        ) ;
    }

    public function testArrayResultIsReturnedAsIs() :void
    {
        $host = $this->host() ;
        $host->canned = [ 'category' => [ [ 'value' => 'B' , 'count' => 2 ] ] ] ;

        $this->assertSame(
            [ 'category' => [ [ 'value' => 'B' , 'count' => 2 ] ] ] ,
            $host->facetCounts( [ Arango::FACET_COUNTS => 'category' ] )
        ) ;
    }

    public function testNonStructuredResultBecomesEmptyArray() :void
    {
        $host = $this->host() ;
        $host->canned = null ; // e.g. empty cursor

        $this->assertSame( [] , $host->facetCounts( [ Arango::FACET_COUNTS => 'category' ] ) ) ;
    }
}
