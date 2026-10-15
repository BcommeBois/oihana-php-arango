<?php

namespace tests\oihana\arango\models\helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\AQL;
use oihana\arango\enums\Filter;
use oihana\arango\models\enums\filters\FilterType;

use function oihana\arango\models\helpers\isDeclaredFilterPath;

/**
 * A dotted path is declared flat or as an object, and read the same either way.
 */
final class IsDeclaredFilterPathTest extends TestCase
{
    /**
     * @return array<string,array{0:array<array-key,mixed>,1:string,2:bool}>
     */
    public static function paths() :array
    {
        $flat   = [ 'seller.id' => FilterType::STRING , 'price' => FilterType::NUMBER ] ;
        $object = [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] , 'price' => FilterType::NUMBER ] ;
        $deep   = [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'address' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'city' => FilterType::STRING ] ] ] ] ] ;
        $mixed  = [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'address.city' => FilterType::STRING ] ] ] ;
        $free   = [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [] ] ] ;

        return
        [
            'a plain key'                        => [ $flat   , 'price'               , true  ] ,
            'a flat path'                        => [ $flat   , 'seller.id'           , true  ] ,
            'an object path'                     => [ $object , 'seller.id'           , true  ] ,
            'two objects deep'                   => [ $deep   , 'seller.address.city' , true  ] ,
            'an object holding a flat path'      => [ $mixed  , 'seller.address.city' , true  ] ,
            'an object declared free'            => [ $free   , 'seller.anything'     , true  ] ,
            'an undeclared key'                  => [ $flat   , 'name'                , false ] ,
            'an undeclared sub-field, flat'      => [ $flat   , 'seller.name'         , false ] ,
            'an undeclared sub-field, object'    => [ $object , 'seller.name'         , false ] ,
            'an undeclared object'               => [ $object , 'buyer.id'            , false ] ,
            'a path under a scalar'              => [ $object , 'price.value'         , false ] ,
            'the object alone, flat declaration' => [ $flat   , 'seller'              , false ] ,
            'nothing declared'                   => [ []      , 'seller.id'           , false ] ,
        ] ;
    }

    #[DataProvider( 'paths' )]
    public function testAPathIsDeclaredFlatOrAsAnObject( array $declared , string $path , bool $expected ) :void
    {
        $this->assertSame( $expected , isDeclaredFilterPath( $declared , $path ) ) ;
    }
}
