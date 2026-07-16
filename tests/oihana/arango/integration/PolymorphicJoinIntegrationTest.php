<?php

namespace tests\oihana\arango\integration;

use Devium\Toml\TomlError;
use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\Group;

use ReflectionException;
use Throwable;

use function oihana\arango\models\helpers\buildVariables;
use function oihana\init\initConfig;

/**
 * Live validation of a **polymorphic join** — a join whose target collection is
 * chosen at query time from a discriminator field of the parent document.
 *
 * Neutral graph:
 *
 * ```
 * pricingConditions.selector.areaServed --(join)--> warehouses    when areaScope == …#Warehouse
 *                                        --(join)--> subsidiaries  when areaScope == …#Company
 *                                        --(join)--> regions       (fallback, any other value)
 * ```
 *
 * The real {@see buildVariables()} dispatch (`isPolymorphic` → the polymorphic
 * builder → `APPEND` of guarded branches) is driven against a seeded, disposable
 * database, wrapped in a minimal `FOR doc IN pricingConditions … RETURN` query.
 * A correct result proves the generated `APPEND( ( FOR … ) , ( FOR … ) )` — a
 * shape the unit suite (frozen AQL string only) cannot execute — actually parses
 * AND routes to the right collection on a real server.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class PolymorphicJoinIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_polymorphic_join_it' ;

    private const string PRICING_CONDITIONS = 'pricingConditions' ;
    private const string WAREHOUSES         = 'warehouses' ;
    private const string SUBSIDIARIES       = 'subsidiaries' ;
    private const string REGIONS            = 'regions' ;

    private const string SCOPE_WAREHOUSE = 'https://schema.oihana.xyz/PricingAreaScope#Warehouse' ;
    private const string SCOPE_COMPANY   = 'https://schema.oihana.xyz/PricingAreaScope#Company' ;
    private const string SCOPE_UNKNOWN   = 'https://schema.oihana.xyz/PricingAreaScope#City' ;

    /**
     * Seeds three target collections and three pricing conditions, one per
     * discriminator value (Warehouse / Company / an undeclared City scope).
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::PRICING_CONDITIONS )->create() ;
        $db->collection( self::WAREHOUSES         )->create() ;
        $db->collection( self::SUBSIDIARIES       )->create() ;
        $db->collection( self::REGIONS            )->create() ;

        $db->collection( self::WAREHOUSES   )->insert( [ '_key' => 'w1' , 'name' => 'Central Warehouse' ] ) ;
        $db->collection( self::SUBSIDIARIES )->insert( [ '_key' => 's1' , 'name' => 'Paris Subsidiary'  ] ) ;
        $db->collection( self::REGIONS      )->insert( [ '_key' => 'r1' , 'name' => 'EU Region'         ] ) ;

        $db->collection( self::PRICING_CONDITIONS )->insert(
        [
            '_key'     => 'pc_wh' ,
            'selector' => [ 'areaScope' => self::SCOPE_WAREHOUSE , 'areaServed' => 'w1' ] ,
        ]) ;
        $db->collection( self::PRICING_CONDITIONS )->insert(
        [
            '_key'     => 'pc_co' ,
            'selector' => [ 'areaScope' => self::SCOPE_COMPANY , 'areaServed' => 's1' ] ,
        ]) ;
        $db->collection( self::PRICING_CONDITIONS )->insert(
        [
            '_key'     => 'pc_unknown' ,
            'selector' => [ 'areaScope' => self::SCOPE_UNKNOWN , 'areaServed' => 'r1' ] ,
        ]) ;
    }

    public function testWarehouseScopeResolvesFromWarehouses() :void
    {
        $this->assertSame
        (
            [ '_key' => 'w1' , 'name' => 'Central Warehouse' ] ,
            $this->resolveArea( 'pc_wh' , $this->polymorphicDefinition() )
        ) ;
    }

    public function testCompanyScopeResolvesFromSubsidiaries() :void
    {
        $this->assertSame
        (
            [ '_key' => 's1' , 'name' => 'Paris Subsidiary' ] ,
            $this->resolveArea( 'pc_co' , $this->polymorphicDefinition() )
        ) ;
    }

    public function testUndeclaredScopeResolvesToNullWithoutFallback() :void
    {
        // No branch matches "#City" → the LET holds [] → FIRST() is null.
        $this->assertNull( $this->resolveArea( 'pc_unknown' , $this->polymorphicDefinition() ) ) ;
    }

    public function testUndeclaredScopeResolvesFromFallback() :void
    {
        $this->assertSame
        (
            [ '_key' => 'r1' , 'name' => 'EU Region' ] ,
            $this->resolveArea( 'pc_unknown' , $this->polymorphicDefinition( withFallback: true ) )
        ) ;
    }

    /**
     * Builds the polymorphic join definition (map only, or map + fallback), each
     * branch a live `Documents` model bound to the disposable database.
     *
     * @param bool $withFallback
     *
     * @return array
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws TomlError
     * @throws Throwable
     */
    private function polymorphicDefinition( bool $withFallback = false ) :array
    {
        [ $arangodb , $container ] = $this->context() ;

        $fields = [ '_key' => [] , 'name' => [] ] ;

        $definition =
        [
            Arango::DISCRIMINATOR => 'selector.areaScope' ,
            Arango::PROPERTY      => 'selector.areaServed' ,
            Arango::MAP           =>
            [
                self::SCOPE_WAREHOUSE => [ AQL::MODEL => $this->documents( $arangodb , $container , self::WAREHOUSES   ) , AQL::FIELDS => $fields ] ,
                self::SCOPE_COMPANY   => [ AQL::MODEL => $this->documents( $arangodb , $container , self::SUBSIDIARIES ) , AQL::FIELDS => $fields ] ,
            ] ,
        ] ;

        if ( $withFallback )
        {
            $definition[ Arango::FALLBACK ] = [ AQL::MODEL => $this->documents( $arangodb , $container , self::REGIONS ) , AQL::FIELDS => $fields ] ;
        }

        return $definition ;
    }

    /**
     * Generates the polymorphic join `LET` through the real `buildVariables()`
     * dispatch, runs it over a single pricing condition and returns the resolved
     * `area` object (or null).
     *
     * @param string $parentKey
     * @param array  $definition
     *
     * @return array|null
     * @throws Throwable
     */
    private function resolveArea( string $parentKey , array $definition ) :?array
    {
        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'area' => [ Field::FILTER => Filter::JOIN ] ] ,
            [] ,
            [ 'area' => $definition ]
        ) ;

        $let   = $variables[ 0 ] ;
        $query = "FOR doc IN " . self::PRICING_CONDITIONS . " FILTER doc._key == '" . $parentKey . "' " . $let . " RETURN { area: FIRST(area) }" ;

        foreach ( self::$db->query( $query ) as $row )
        {
            return json_decode( json_encode( $row ) , true )[ 'area' ] ;
        }

        return null ;
    }

    /**
     * A live ArangoDB façade + container wired to the disposable database.
     *
     * @return array{ 0: ArangoDB , 1: Container }
     * @throws TomlError
     * @throws Throwable
     */
    private function context() :array
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return [ $arangodb , $container ] ;
    }

    /**
     * A live `Documents` model bound to a collection on the disposable database.
     *
     * @param ArangoDB  $arangodb
     * @param Container $container
     * @param string    $collection
     *
     * @return Documents
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    private function documents( ArangoDB $arangodb , Container $container , string $collection ) :Documents
    {
        return new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => $collection , AQL::LAZY => false ] ) ;
    }
}
