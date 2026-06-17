<?php

namespace tests\oihana\arango\db\options\views ;

use oihana\arango\clients\view\enums\ViewField ;
use oihana\arango\db\options\views\SearchAliasView ;

use PHPUnit\Framework\Attributes\CoversClass ;
use PHPUnit\Framework\TestCase ;

/**
 * Unit coverage for {@see SearchAliasView} — the search-alias view definition
 * value object and its `getIndexes()` normalizer.
 */
#[CoversClass( SearchAliasView::class )]
final class SearchAliasViewTest extends TestCase
{
    public function testStoresNameIndexesAndOptions() : void
    {
        $view = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' ] , [ 'opt' => 1 ] ) ;

        $this->assertSame( 'global_search'        , $view->name ) ;
        $this->assertSame( [ 'customers' => 'inv' ] , $view->indexes ) ;
        $this->assertSame( [ 'opt' => 1 ]          , $view->options ) ;
    }

    public function testDefaultsToEmptyIndexesAndOptions() : void
    {
        $view = new SearchAliasView( 'v' ) ;

        $this->assertSame( [] , $view->getIndexes() ) ;
        $this->assertSame( [] , $view->options ) ;
    }

    public function testNormalisesConvenienceMapForm() : void
    {
        $view = new SearchAliasView( 'global_search' , [ 'customers' => 'inv' , 'products' => 'inv2' ] ) ;

        $this->assertSame
        (
            [
                [ ViewField::COLLECTION => 'customers' , ViewField::INDEX => 'inv'  ] ,
                [ ViewField::COLLECTION => 'products'  , ViewField::INDEX => 'inv2' ] ,
            ] ,
            $view->getIndexes()
        ) ;
    }

    public function testNormalisesExplicitListForm() : void
    {
        $view = new SearchAliasView( 'global_search' ,
        [
            [ ViewField::COLLECTION => 'customers' , ViewField::INDEX => 'inv' ] ,
            [ ViewField::COLLECTION => 'products'  , ViewField::INDEX => 'inv' ] ,
        ] ) ;

        $this->assertSame
        (
            [
                [ ViewField::COLLECTION => 'customers' , ViewField::INDEX => 'inv' ] ,
                [ ViewField::COLLECTION => 'products'  , ViewField::INDEX => 'inv' ] ,
            ] ,
            $view->getIndexes()
        ) ;
    }

    public function testDropsMalformedEntries() : void
    {
        $view = new SearchAliasView( 'global_search' ,
        [
            'customers' => 'inv' ,                                            // ok (map)
            [ ViewField::COLLECTION => 'products' ] ,                         // missing index → dropped
            [ ViewField::INDEX => 'inv' ] ,                                   // missing collection → dropped
            'bogus' ,                                                         // scalar entry → dropped
            [ ViewField::COLLECTION => 'sellers' , ViewField::INDEX => 'i' ] , // ok (list)
        ] ) ;

        $this->assertSame
        (
            [
                [ ViewField::COLLECTION => 'customers' , ViewField::INDEX => 'inv' ] ,
                [ ViewField::COLLECTION => 'sellers'   , ViewField::INDEX => 'i'   ] ,
            ] ,
            $view->getIndexes()
        ) ;
    }
}
