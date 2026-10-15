<?php

namespace tests\oihana\arango\models\traits\aql\filters;

use DI\Container;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

/**
 * 🚨 The two spellings of a filter on a sub-field of an element of a list read the
 * same declaration.
 *
 * The direct spelling, `{"key":"offers[*].seller.id","val":…}`, read only a sub-field
 * declared as an object ; the `match` spelling, `{"key":"offers[*]","match":{"seller.id":…}}`,
 * read only one declared flat. No declaration served both : whichever a model chose,
 * one spelling answered `400` to a key it did declare.
 */
final class FilterSubFieldDeclarationTest extends TestCase
{
    /**
     * The two declarations of `offers[*].seller.id`.
     *
     * @return array<string,array{0:array<array-key,mixed>}>
     */
    public static function declarations() :array
    {
        return
        [
            'declared flat'      => [ [ 'seller.id' => FilterType::STRING ] ] ,
            'declared as object' => [ [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] ] ] ,
        ] ;
    }

    /**
     * The four spellings of the same question, beside the condition each compiles to.
     *
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function spellings() :array
    {
        return
        [
            'direct'             => [ [ 'key' => 'offers[*].seller.id' , 'val' => 'org-42' ] , '/^@\w+ IN doc\.offers\[\*\]\.seller\.id$/' ] ,
            'a match'            => [ [ 'key' => 'offers[*]' , 'match' => [ 'seller.id' => 'org-42' ] ] , '/^@\w+ IN doc\.offers\[\*\]\.seller\.id$/' ] ,
            'a match on two'     => [ [ 'key' => 'offers[*]' , 'match' => [ 'seller.id' => 'org-42' , 'price' => 10 ] ] , '/CURRENT\.seller\.id == @\w+ && CURRENT\.price == @\w+/' ] ,
            'a match, all form'  => [ [ 'key' => 'offers[*]' , 'match' => [ 'all' => [ [ 'key' => 'seller.id' , 'op' => 'ne' , 'val' => 'org-42' ] ] ] ] , '/CURRENT\.seller\.id != @\w+/' ] ,
        ] ;
    }

    /**
     * Every declaration crossed with every spelling.
     *
     * @return array<string,array{0:array<array-key,mixed>,1:array<string,mixed>,2:string}>
     */
    public static function crossed() :array
    {
        $cases = [] ;

        foreach ( self::declarations() as $declaration => [ $declared ] )
        {
            foreach ( self::spellings() as $spelling => [ $filter , $pattern ] )
            {
                $cases[ "$spelling, $declaration" ] = [ $declared , $filter , $pattern ] ;
            }
        }

        return $cases ;
    }

    /**
     * The same paths, on a sub-field neither declaration names.
     *
     * @return array<string,array{0:array<array-key,mixed>,1:array<string,mixed>}>
     */
    public static function undeclared() :array
    {
        $cases = [] ;

        foreach ( self::declarations() as $declaration => [ $declared ] )
        {
            $cases[ "direct, $declaration" ]            = [ $declared , [ 'key' => 'offers[*].seller.name' , 'val' => 'x' ] ] ;
            $cases[ "a match, $declaration" ]           = [ $declared , [ 'key' => 'offers[*]' , 'match' => [ 'seller.name' => 'x' ] ] ] ;
            $cases[ "a match, all form, $declaration" ] = [ $declared , [ 'key' => 'offers[*]' , 'match' => [ 'all' => [ [ 'key' => 'seller.name' , 'val' => 'x' ] ] ] ] ] ;
            $cases[ "an undeclared object, $declaration" ] = [ $declared , [ 'key' => 'offers[*].buyer.id' , 'val' => 'x' ] ] ;
        }

        return $cases ;
    }

    #[DataProvider( 'crossed' )]
    public function testEverySpellingReadsEveryDeclaration( array $declared , array $filter , string $pattern ) :void
    {
        $binds = [] ;

        $this->assertMatchesRegularExpression( $pattern , $this->model( $declared )->prepareFilter( $filter , $binds ) ) ;
    }

    #[DataProvider( 'undeclared' )]
    public function testASubFieldNeitherDeclarationNamesIsRefused( array $declared , array $filter ) :void
    {
        $binds = [] ;

        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'is not filterable' ) ;

        $this->model( $declared )->prepareFilter( $filter , $binds ) ;
    }

    /**
     * @param array<array-key,mixed> $declared The sub-filters of `offers`, beside its price.
     */
    private function model( array $declared ) :Documents
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            AQL::COLLECTION => 'products' ,
            AQL::LAZY       => false ,
            AQL::FILTERS    =>
            [
                'offers' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS => [ ...$declared , 'price' => FilterType::NUMBER ] ,
                ] ,
            ] ,
        ]) ;
    }
}
