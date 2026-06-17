<?php

namespace tests\oihana\arango\commands\traits;

use oihana\arango\commands\traits\ArangoSearchAliasViewsTrait;
use oihana\arango\db\options\views\SearchAliasView;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * Bare host for {@see ArangoSearchAliasViewsTrait}.
 */
class ArangoSearchAliasViewsTraitHost
{
    use ArangoSearchAliasViewsTrait ;
}

/**
 * Unit coverage for {@see ArangoSearchAliasViewsTrait} — the search-alias view
 * registry holder and its `getSearchAliasViews()` normalizer (Lot G1a).
 */
#[CoversTrait( ArangoSearchAliasViewsTrait::class )]
class ArangoSearchAliasViewsTraitTest extends TestCase
{
    private function view( string $name ) :SearchAliasView
    {
        return new SearchAliasView( $name , [ 'customers' => 'inv' ] ) ;
    }

    public function testDefaultsToAnEmptyList() :void
    {
        $this->assertSame( [] , new ArangoSearchAliasViewsTraitHost()->getSearchAliasViews() ) ;
    }

    public function testKeepsADeclaredList() :void
    {
        $host = new ArangoSearchAliasViewsTraitHost() ;
        $host->searchAliasViews = [ $a = $this->view( 'a' ) , $b = $this->view( 'b' ) ] ;

        $this->assertSame( [ $a , $b ] , $host->getSearchAliasViews() ) ;
    }

    public function testNormalizesASingleViewToAList() :void
    {
        $host = new ArangoSearchAliasViewsTraitHost() ;
        $host->searchAliasViews = $single = $this->view( 'solo' ) ;

        $this->assertSame( [ $single ] , $host->getSearchAliasViews() ) ;
    }

    public function testDropsNonViewEntriesAndReindexes() :void
    {
        $host = new ArangoSearchAliasViewsTraitHost() ;
        $host->searchAliasViews = [ 'bogus' , $a = $this->view( 'a' ) , null ] ;

        $this->assertSame( [ $a ] , $host->getSearchAliasViews() ) ;
    }
}
