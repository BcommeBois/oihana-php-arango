<?php

namespace tests\oihana\arango\integration;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that a sub-field of an element of a list answers the same documents
 * whichever way it is declared and whichever way it is asked.
 *
 * Declared flat (`'seller.id'`) or as an object (`'seller' => [ DOCUMENT , [ 'id' ] ]`),
 * asked directly (`offers[*].seller.id`) or through a `match` (`offers[*]` with
 * `{"seller.id":…}`) : four cells, one answer. Two of the four answered `400` before.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class SubFieldDeclarationIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_sub_field_declaration_it' ;

    private const string COLLECTION = 'products' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $products = $db->collection( self::COLLECTION ) ;
        $products->create() ;

        $products->insert( [ '_key' => 'p1' , 'offers' => [ [ 'seller' => [ 'id' => 'org-42' ] , 'price' => 10 ] , [ 'seller' => [ 'id' => 'org-7' ] , 'price' => 12 ] ] ] ) ;
        $products->insert( [ '_key' => 'p2' , 'offers' => [ [ 'seller' => [ 'id' => 'org-7' ] , 'price' => 9 ] ] ] ) ;
        $products->insert( [ '_key' => 'p3' , 'offers' => [ [ 'price' => 5 ] ] ] ) ;
        $products->insert( [ '_key' => 'p4' ] ) ;
    }

    /**
     * Every declaration crossed with both spellings.
     *
     * @return array<string,array{0:array<array-key,mixed>,1:array<string,mixed>}>
     */
    public static function cells() :array
    {
        $flat   = [ 'seller.id' => FilterType::STRING ] ;
        $object = [ 'seller' => [ AQL::TYPE => Filter::DOCUMENT , AQL::FILTERS => [ 'id' => FilterType::STRING ] ] ] ;
        $direct = [ 'key' => 'offers[*].seller.id' , 'val' => 'org-7' ] ;
        $match  = [ 'key' => 'offers[*]' , 'match' => [ 'seller.id' => 'org-7' ] ] ;

        return
        [
            'direct, declared flat'       => [ $flat   , $direct ] ,
            'direct, declared as object'  => [ $object , $direct ] ,
            'a match, declared flat'      => [ $flat   , $match  ] ,
            'a match, declared as object' => [ $object , $match  ] ,
        ] ;
    }

    /**
     * @throws ArangoException
     */
    #[DataProvider( 'cells' )]
    public function testEveryCellAnswersTheSameProducts( array $declared , array $filter ) :void
    {
        $keys = array_map( static fn( mixed $row ) :string => (string) ( is_object( $row ) ? $row->_key : $row[ '_key' ] ) , $this->model( $declared )->list( [ Arango::FILTER => $filter ] ) ) ;
        sort( $keys ) ;

        $this->assertSame( [ 'p1' , 'p2' ] , $keys ) ;
    }

    /**
     * @param array<array-key,mixed> $declared The sub-filters of `offers`.
     */
    private function model( array $declared ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ,
            AQL::COLLECTION  => self::COLLECTION ,
            AQL::LAZY        => false ,
            AQL::FILTERS     =>
            [
                'offers' => [ AQL::TYPE => Filter::ARRAY_EXPANSION , AQL::FILTERS => $declared ] ,
            ] ,
        ]);
    }
}
