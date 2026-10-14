<?php

namespace tests\oihana\arango\commands\traits;

use InvalidArgumentException;

use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\PreserveFilter;
use oihana\arango\commands\traits\ArangoPreservesTrait;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bare host for {@see ArangoPreservesTrait}, exposing its protected reader.
 */
class ArangoPreservesTraitHost
{
    use ArangoPreservesTrait ;

    public function filters( array $only = [] ) :array
    {
        return $this->preserveFilters( $only ) ;
    }
}

/**
 * Unit coverage for {@see ArangoPreservesTrait} : the reading and the validation
 * of the `[arango.preserves]` config section, before anything is written.
 */
#[CoversTrait(ArangoPreservesTrait::class)]
class ArangoPreservesTraitTest extends TestCase
{
    private const string RULE = '{"key":"_key","op":"nsw","val":"src:"}' ;

    private function host( mixed $preserves ) :ArangoPreservesTraitHost
    {
        return new ArangoPreservesTraitHost()->initializeArangoPreserves( [ ArangoCommandParam::PRESERVES => $preserves ] ) ;
    }

    private function rule( array $overrides = [] ) :array
    {
        return
        [
            ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] ,
            ArangoCommandParam::PRESERVE_FILTER  => self::RULE ,
            ...$overrides ,
        ] ;
    }

    // ---------------------------------------------------------------- the section

    public function testWithoutASectionNothingCanBePreserved() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'No [arango.preserves] section is declared' ) ;

        new ArangoPreservesTraitHost()->filters() ;
    }

    public function testASectionThatIsNotATableIsIgnored() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'No [arango.preserves] section is declared' ) ;

        $this->host( 'articles' )->filters() ;
    }

    public function testEveryDeclaredRuleIsCompiledSortedByName() :void
    {
        $filters = $this->host( [ 'tags' => $this->rule() , 'articles' => $this->rule() ] )->filters() ;

        $this->assertSame( [ 'articles' , 'tags' ] , array_keys( $filters ) ) ;
        $this->assertContainsOnlyInstancesOf( PreserveFilter::class , $filters ) ;
        $this->assertSame( 'tags__preserved' , $filters[ 'tags' ]->backup ) ;
    }

    public function testTheNamedCollectionsOnly() :void
    {
        $filters = $this->host( [ 'tags' => $this->rule() , 'articles' => $this->rule() ] )->filters( [ 'tags' ] ) ;

        $this->assertSame( [ 'tags' ] , array_keys( $filters ) ) ;
    }

    public function testANamedCollectionWithoutARuleIsRefused() :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessage( 'No [arango.preserves] rule for : authors, tags.' ) ;

        $this->host( [ 'articles' => $this->rule() ] )->filters( [ 'authors' , 'articles' , 'tags' ] ) ;
    }

    // ---------------------------------------------------------------- the rule

    /**
     * Every rule the reader refuses, and the words its refusal must carry.
     */
    public static function refusedRules() :array
    {
        $rule = fn( array $overrides ) => [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] , ArangoCommandParam::PRESERVE_FILTER => self::RULE , ...$overrides ] ;

        return
        [
            'a backup'                => [ 'articles__preserved' , $rule( [] ) , 'already a backup' ] ,
            'not a table'             => [ 'articles' , 'nsw src:' , 'must be a table' ] ,
            'no filters'              => [ 'articles' , [ ArangoCommandParam::PRESERVE_FILTER => self::RULE ] , "declares no 'filters'" ] ,
            'empty filters'           => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTERS => [] ] ) , "declares no 'filters'" ] ,
            'a relation in filters'   => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTERS => [ 'author' => [ 'type' => 'edge' ] ] ] ) , "declares 'author' as a table" ] ,
            'an unknown type'         => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => 'text' ] ] ) , "declares '_key' with an unknown type" ] ,
            'a virtual type'          => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::VIRTUAL ] ] ) , "declares '_key' with an unknown type" ] ,
            'no filter'               => [ 'articles' , [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] ] , "has no readable 'filter'" ] ,
            'a filter that is no JSON' => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTER => '_key nsw src:' ] ) , "has no readable 'filter'" ] ,
            'an empty filter'         => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTER => '{}' ] ) , "has no readable 'filter'" ] ,
            'a filter given as table' => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTER => [ 'key' => '_key' , 'val' => 'x' ] ] ) , "has no readable 'filter'" ] ,
            'an undeclared key'       => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_FILTER => '{"key":"name","val":"x"}' ] ) , 'is not filterable' ] ,
            'a maxPercent of zero'    => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => 0     ] ) , "has a 'maxPercent' that is not a whole percent from 1 to 100" ] ,
            'a negative maxPercent'   => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => -1    ] ) , "has a 'maxPercent' that is not a whole percent from 1 to 100" ] ,
            'a maxPercent over 100'   => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => 101   ] ) , "has a 'maxPercent' that is not a whole percent from 1 to 100" ] ,
            'a decimal maxPercent'    => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => 0.5   ] ) , "has a 'maxPercent' that is not a whole percent from 1 to 100" ] ,
            'a maxPercent as a string' => [ 'articles' , $rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => '50' ] ) , "has a 'maxPercent' that is not a whole percent from 1 to 100" ] ,
        ] ;
    }

    #[DataProvider('refusedRules')]
    public function testARuleThatCannotBeReadIsRefusedAndNamesItsCollection( string $collection , mixed $definition , string $words ) :void
    {
        $this->expectException( InvalidArgumentException::class ) ;
        $this->expectExceptionMessageMatches( '/' . preg_quote( $collection , '/' ) . '.*' . preg_quote( $words , '/' ) . '/' ) ;

        $this->host( [ $collection => $definition ] )->filters() ;
    }

    public function testMaxPercentIsFiftyByDefaultAndReadFromTheRule() :void
    {
        $filters = $this->host
        ([
            'articles' => $this->rule() ,
            'tags'     => $this->rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => 1   ] ) ,
            'authors'  => $this->rule( [ ArangoCommandParam::PRESERVE_MAX_PERCENT => 100 ] ) ,
        ])->filters() ;

        $this->assertSame( 50  , $filters[ 'articles' ]->maxPercent ) ;
        $this->assertSame( 1   , $filters[ 'tags'     ]->maxPercent ) ;
        $this->assertSame( 100 , $filters[ 'authors'  ]->maxPercent ) ;
    }

    public function testEveryTypeButVirtualIsAccepted() :void
    {
        foreach( FilterType::getConstantValues() as $type )
        {
            if( $type === FilterType::VIRTUAL )
            {
                continue ;
            }

            $filters = $this->host( [ 'articles' => $this->rule( [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING , 'other' => $type ] ] ) ] )->filters() ;

            $this->assertArrayHasKey( 'articles' , $filters , $type ) ;
        }
    }
}
