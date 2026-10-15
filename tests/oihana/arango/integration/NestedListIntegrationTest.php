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
use oihana\arango\exceptions\RequestValidationException;
use oihana\arango\models\Documents;
use oihana\arango\models\enums\filters\FilterType;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that a filter on a list held inside another list of the same document
 * answers the elements of the inner list.
 *
 * `doc.stops[*].scans[* FILTER …]` yields one list PER STOP. Counted as is, it counts
 * the stops : a parcel with one stop and no matching scan was answered as matching,
 * whatever the value asked for. A `match` on the inner list lost its `match` on the way
 * down, and compared the list to nothing. The seed tells every one of these apart :
 *
 * - `a` — two stops, scans S1 (checked), S2 and S3 ;
 * - `b` — one stop, scan S9 ;
 * - `c` — no stop at all ;
 * - `d` — one stop holding no scan ;
 * - `e` — an empty list of stops ;
 * - `f` — one stop whose scans are missing.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class NestedListIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_nested_list_it' ;

    private const string COLLECTION = 'parcels' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $parcels = $db->collection( self::COLLECTION ) ;
        $parcels->create() ;

        $parcels->insert( [ '_key' => 'a' , 'stops' => [ [ 'scans' => [ [ 'code' => 'S1' , 'checked' => true ] , [ 'code' => 'S2' , 'checked' => false ] ] ] , [ 'scans' => [ [ 'code' => 'S3' ] ] ] ] ] ) ;
        $parcels->insert( [ '_key' => 'b' , 'stops' => [ [ 'scans' => [ [ 'code' => 'S9' ] ] ] ] ] ) ;
        $parcels->insert( [ '_key' => 'c' ] ) ;
        $parcels->insert( [ '_key' => 'd' , 'stops' => [ [ 'scans' => [] ] ] ] ) ;
        $parcels->insert( [ '_key' => 'e' , 'stops' => [] ] ) ;
        $parcels->insert( [ '_key' => 'f' , 'stops' => [ [ 'name' => 'depot' ] ] ] ) ;
    }

    /**
     * Each filter on the inner list, beside the parcels that carry what it asks for.
     *
     * @return array<string,array{0:array<string,mixed>,1:array<int,string>}>
     */
    public static function filters() :array
    {
        return
        [
            'a code of the first stop'           => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S1' ] , [ 'a' ] ] ,
            'a code of the second stop'          => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S3' ] , [ 'a' ] ] ,
            'a code of another parcel'           => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'S9' ] , [ 'b' ] ] ,
            'a code nobody holds'                => [ [ 'key' => 'stops[*].scans[*].code' , 'val' => 'ZZ' ] , [] ] ,
            'a code other than one'              => [ [ 'key' => 'stops[*].scans[*].code' , 'op' => 'ne' , 'val' => 'S1' ] , [ 'a' , 'b' ] ] ,
            'one of two codes'                   => [ [ 'key' => 'stops[*].scans[*].code' , 'op' => 'in' , 'val' => [ 'S2' , 'S9' ] ] , [ 'a' , 'b' ] ] ,
            'a match on one sub-field'           => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' ] ] , [ 'a' ] ] ,
            'a match on two, on one scan'        => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S1' , 'checked' => true ] ] , [ 'a' ] ] ,
            'a match on two, on no scan'         => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'code' => 'S2' , 'checked' => true ] ] , [] ] ,
            'a match, all form'                  => [ [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'all' => [ [ 'key' => 'code' , 'op' => 'like' , 'val' => 'S9%' ] ] ] ] , [ 'b' ] ] ,
            'at least one scan'                  => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 'any' ] , [ 'a' , 'b' ] ] ,
            'at least one scan, nothing said'    => [ [ 'key' => 'stops[*].scans[*]' ] , [ 'a' , 'b' ] ] ,
            'not one scan'                       => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 'none' ] , [ 'c' , 'd' , 'e' , 'f' ] ] ,
            'at least two scans'                 => [ [ 'key' => 'stops[*].scans[*]' , 'quant' => 2 ] , [ 'a' ] ] ,
        ] ;
    }

    /**
     * @param array<string,mixed> $filter
     * @param array<int,string>   $expected
     *
     * @throws ArangoException
     */
    #[DataProvider( 'filters' )]
    public function testAFilterOnTheInnerListAnswersItsElements( array $filter , array $expected ) :void
    {
        $this->assertSame( $expected , $this->keys( $filter ) ) ;
    }

    /**
     * The guards of a `match` play on the inner list too : a sub-field it does not
     * declare is refused, as it is on a list of the first level.
     *
     * @throws ArangoException
     */
    public function testAMatchOnAnUndeclaredSubFieldOfTheInnerListIsRefused() :void
    {
        $this->expectException( RequestValidationException::class ) ;
        $this->expectExceptionMessage( 'is not filterable' ) ;

        $this->keys( [ 'key' => 'stops[*].scans[*]' , 'match' => [ 'weight' => 3 ] ] ) ;
    }

    /**
     * A list of the first level answers as it did : the counter-proof the defect
     * belongs to the nesting.
     *
     * @throws ArangoException
     */
    public function testTheOuterListStillAnswersAsBefore() :void
    {
        $this->assertSame( [ 'a' , 'b' , 'd' , 'f' ] , $this->keys( [ 'key' => 'stops[*]' , 'quant' => 'any' ] ) ) ;
        $this->assertSame( [ 'f' ] , $this->keys( [ 'key' => 'stops[*].name' , 'val' => 'depot' ] ) ) ;
    }

    /**
     * The sorted keys of the parcels a filter answers.
     *
     * @param array<string,mixed> $filter
     *
     * @return array<int,string>
     *
     * @throws ArangoException
     */
    private function keys( array $filter ) :array
    {
        $keys = array_map
        (
            static fn( mixed $row ) :string => (string) ( is_object( $row ) ? $row->_key : $row[ '_key' ] ) ,
            $this->model()->list( [ Arango::FILTER => $filter ] )
        ) ;

        sort( $keys ) ;

        return $keys ;
    }

    private function model() :Documents
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
                'stops' =>
                [
                    AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                    AQL::FILTERS =>
                    [
                        'name'  => FilterType::STRING ,
                        'scans' =>
                        [
                            AQL::TYPE    => Filter::ARRAY_EXPANSION ,
                            AQL::FILTERS => [ 'code' => FilterType::STRING , 'checked' => FilterType::BOOL ] ,
                        ] ,
                    ] ,
                ] ,
            ] ,
        ]);
    }
}
