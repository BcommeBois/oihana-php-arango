<?php

namespace tests\oihana\arango\integration;

use ReflectionException;
use Throwable;

use DI\Container;
use DI\DependencyException;
use DI\NotFoundException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Devium\Toml\TomlError;

use oihana\arango\clients\Database;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\reflect\exceptions\ConstantException;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of a **multilingual** `?sort=`: ordering a list on one locale of
 * a translations object, falling back to another locale, then to a plain field.
 *
 * The unit suite freezes the compiled expression —
 * `NOT_NULL(doc.alternateName["en"], doc.alternateName["fr"], doc.name)`. It cannot
 * say whether the server accepts a bracket accessor on a possibly-absent object,
 * whether reaching into a non-object raises a warning (which `failOnWarning` turns
 * into a failed query), nor whether the rows come back in the announced order.
 *
 * 🔑 The seed is built so that **every** case has its own signature. Sorting the
 * three products yields four different orders — by `fr`, by `en`, by `name` alone,
 * and by the locales with no fallback field:
 *
 * ```
 * a  name "Zinc-3000"  fr "Aspirateur"  en "Hoover"
 * b  name "Bureau"                      en "Desk"     // no French
 * c  name "Miroir"                                    // no translations at all
 *
 * fr → a,b,c     en → b,a,c     name → b,c,a     locales only → c,b,a
 * ```
 *
 * No expected order can therefore be produced by a query that ignored the criterion,
 * ordered on the wrong locale, or dropped the fallback.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class MultilingualSortIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_multilingual_sort_it' ;

    private const string PRODUCTS = 'products' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $products = $db->collection( self::PRODUCTS ) ;
        $products->create() ;

        $products->insert( [ '_key' => 'a' , 'name' => 'Zinc-3000' , 'alternateName' => [ 'fr' => 'Aspirateur' , 'en' => 'Hoover' ] ] ) ;
        $products->insert( [ '_key' => 'b' , 'name' => 'Bureau'    , 'alternateName' => [ 'en' => 'Desk' ] ] ) ;
        $products->insert( [ '_key' => 'c' , 'name' => 'Miroir'    ] ) ;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    private function model( ?string $defaultLang = 'fr' , bool $withElse = true ) :Documents
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => self::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        $entry = [ Field::PATH => 'alternateName' ] ;

        if ( $withElse )
        {
            $entry[ Field::ELSE ] = 'name' ;
        }

        return new Documents( $container ,
        [
            Arango::DATABASE     => $arangodb ,
            AQL::COLLECTION      => self::PRODUCTS ,
            AQL::LAZY            => false ,
            Arango::DEFAULT_LANG => $defaultLang ,

            AQL::FIELDS =>
            [
                '_key'          => [] ,
                'name'          => [] ,
                'alternateName' => Filter::TRANSLATE ,
            ] ,

            AQL::SORTABLE => [ 'name' , 'label' => $entry ] ,
        ]) ;
    }

    /**
     * The keys of the returned rows, in the order the server produced them.
     *
     * @return array<int,string>
     */
    private function keys( array $rows ) :array
    {
        return array_map( fn( $row ) => (string) ( is_array( $row ) ? $row[ '_key' ] : $row->_key ) , $rows ) ;
    }

    /**
     * 🔑 The measurement the unit suite cannot make: the rows really come back
     * ordered by the requested locale, and the two documents that have no such
     * translation really fall back — `b` on the French chain, `c` on both.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheListIsOrderedByTheRequestedLocale() :void
    {
        $model = $this->model() ;

        $this->assertSame
        (
            [ 'a' , 'b' , 'c' ] ,
            $this->keys( $model->list( [ Arango::SORT => 'label' , Arango::LANG => 'fr' ] ) ) ,
            'French: Aspirateur (a), Bureau (b, no French → name), Miroir (c, nothing → name).' ,
        ) ;

        $this->assertSame
        (
            [ 'b' , 'a' , 'c' ] ,
            $this->keys( $model->list( [ Arango::SORT => 'label' , Arango::LANG => 'en' ] ) ) ,
            'English: Desk (b), Hoover (a), Miroir (c, nothing in either locale → name).' ,
        ) ;
    }

    /**
     * The seed does its job: ordering on the plain field is a **third** order,
     * which neither of the two locale orders can be mistaken for. Without this
     * case a green run could still mean the sort ignored the locale entirely.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheLocaleOrderIsNeitherTheNameOrderNorTheNaturalOne() :void
    {
        $model = $this->model() ;

        $byName = $this->keys( $model->list( [ Arango::SORT => 'name' ] ) ) ;

        $this->assertSame( [ 'b' , 'c' , 'a' ] , $byName , 'Bureau, Miroir, Zinc-3000.' ) ;

        $natural = $this->keys( $model->list( [] ) ) ;

        $this->assertNotSame( [ 'a' , 'b' , 'c' ] , $byName ) ;
        $this->assertNotSame( [ 'b' , 'a' , 'c' ] , $byName ) ;
        $this->assertNotSame( [ 'b' , 'a' , 'c' ] , $natural ) ;
    }

    /**
     * No `?lang=` at all, and `?lang=all` (which resolves to null upstream), both
     * order on the fallback locale — widening the payload does not unplug the sort.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testAnAbsentOrClearedLanguageOrdersOnTheFallback() :void
    {
        $model = $this->model() ;

        $this->assertSame( [ 'a' , 'b' , 'c' ] , $this->keys( $model->list( [ Arango::SORT => 'label' ] ) ) ) ;

        $this->assertSame
        (
            [ 'a' , 'b' , 'c' ] ,
            $this->keys( $model->list( [ Arango::SORT => 'label' , Arango::LANG => null ] ) ) ,
        ) ;
    }

    /**
     * 🔑 The cascade, measured rather than asserted on a string: the host pushes a
     * fallback per call, and the model's own declaration outranks it. A default
     * must never override an explicit declaration.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testTheModelFallbackOutranksTheHostOne() :void
    {
        // No model declaration: the host's fallback answers.
        $this->assertSame
        (
            [ 'b' , 'a' , 'c' ] ,
            $this->keys( $this->model( null )->list( [ Arango::SORT => 'label' , Arango::DEFAULT_LANG => 'en' ] ) ) ,
            'English fallback from the host.' ,
        ) ;

        // The model declares French: the host's English is ignored.
        $this->assertSame
        (
            [ 'a' , 'b' , 'c' ] ,
            $this->keys( $this->model( 'fr' )->list( [ Arango::SORT => 'label' , Arango::DEFAULT_LANG => 'en' ] ) ) ,
            'The model wins.' ,
        ) ;
    }

    /**
     * Without `Field::ELSE` the chain stops at the locales, and a document with no
     * translation at all orders on `null` — first, where it ordered before this
     * lot existed. A **fourth** distinct order, so the case cannot be confused
     * with any of the others.
     *
     * @throws ArangoException
     * @throws BindException
     * @throws ConstantException
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     * @throws UnsupportedOperationException
     * @throws ValidationException
     */
    public function testWithoutAFallbackFieldTheUntranslatedRowSortsOnNull() :void
    {
        $call = [ Arango::SORT => 'label' , Arango::LANG => 'en' ] ;

        $this->assertSame
        (
            [ 'c' , 'b' , 'a' ] ,
            $this->keys( $this->model( 'fr' , withElse: false )->list( $call ) ) ,
            'Miroir has neither locale → null, which sorts before any string.' ,
        ) ;

        // ⚠ That order alone proves little: ordering on the raw translations
        // object — what an entry without this lot would emit — happens to produce
        // it too. What the case actually pins is that Field::ELSE *changes* the
        // answer, so the same call under the same locale is compared to it.
        $this->assertSame
        (
            [ 'b' , 'a' , 'c' ] ,
            $this->keys( $this->model( 'fr' , withElse: true )->list( $call ) ) ,
            'With the fallback field, Miroir joins the strings instead of leading on null.' ,
        ) ;
    }
}
