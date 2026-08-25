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
use oihana\arango\db\enums\Traversal;
use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use oihana\arango\enums\Filter;
use oihana\arango\models\Documents;
use oihana\arango\models\Edges;
use oihana\arango\models\enums\Group as GroupSpec;

use oihana\exceptions\BindException;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use oihana\reflect\exceptions\ConstantException;

use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live validation of `?sort=` through a **relation**: ordering a list by a field
 * of the document each row is linked to.
 *
 * The unit suite freezes the compiled expression — `FIRST(authorRef).name ASC`.
 * It cannot say whether the server accepts that expression, nor whether the rows
 * come back in the announced order, and those are the two things that matter.
 *
 * The seed is built so a green run **means** something: the three authors are
 * named so that alphabetical order **contradicts** the insertion order of the
 * articles. A query that ignored the criterion would answer Alpha, Beta, Gamma —
 * which is neither of the two orders asserted here.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class RelationSortIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_relation_sort_it' ;

    private const string ARTICLES = 'articles' ;

    private const string AUTHORS = 'authors' ;

    private const string ARTICLES_AUTHORS = 'articles_authors' ;

    private const int EDGE_TYPE = 3 ;

    /**
     * Three articles, each linked to one author. The edges **leave** the article
     * (the article is the `_from`), so the relation is followed `OUTBOUND`.
     *
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $articles = $db->collection( self::ARTICLES ) ;
        $articles->create() ;
        $articles->insert( [ '_key' => 'a1' , 'title' => 'Alpha' , 'amount' => 10 ] ) ;
        $articles->insert( [ '_key' => 'a2' , 'title' => 'Beta'  , 'amount' => 20 ] ) ;
        $articles->insert( [ '_key' => 'a3' , 'title' => 'Gamma' , 'amount' => 30 ] ) ;

        // ⚠ The names deliberately break the insertion order: a1 → Zoe, a2 →
        // Alice, a3 → Mia. Sorting on the author therefore cannot coincide with
        // sorting on nothing.
        $authors = $db->collection( self::AUTHORS ) ;
        $authors->create() ;
        $authors->insert( [ '_key' => 'auZ' , 'name' => 'Zoe'   ] ) ;
        $authors->insert( [ '_key' => 'auA' , 'name' => 'Alice' ] ) ;
        $authors->insert( [ '_key' => 'auM' , 'name' => 'Mia'   ] ) ;

        $edges = $db->collection( self::ARTICLES_AUTHORS ) ;
        $edges->create( [ 'type' => self::EDGE_TYPE ] ) ;
        $edges->insert( [ '_from' => 'articles/a1' , '_to' => 'authors/auZ' ] ) ;
        $edges->insert( [ '_from' => 'articles/a2' , '_to' => 'authors/auA' ] ) ;
        $edges->insert( [ '_from' => 'articles/a3' , '_to' => 'authors/auM' ] ) ;
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
    private function context() :array
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $arangodb  = new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => self::$database ] , new NullLogger() ) ;

        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        return [ $arangodb , $container ] ;
    }

    /**
     * The articles model: the author relation is **projected** (so the `LET`
     * exists) and **pinned** (so the sort can name it).
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    private function model( ?array $authorField = null , ?array $sortable = null ) :Documents
    {
        [ $arangodb , $container ] = $this->context() ;

        $authors = new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::AUTHORS ,
            AQL::LAZY        => false ,
        ]) ;

        $articlesAuthors = new Edges( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ARTICLES_AUTHORS ,
            AQL::FROM        => new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::ARTICLES , AQL::LAZY => false ] ) ,
            AQL::TO          => $authors ,
            AQL::LAZY        => false ,
        ]) ;

        return new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ARTICLES ,
            AQL::LAZY        => false ,

            AQL::FIELDS =>
            [
                'title'  => [] ,
                'author' => $authorField ?? [ Field::FILTER => Filter::EDGE , Field::UNIQUE => 'authorRef' ] ,
            ] ,

            AQL::EDGES =>
            [
                'author' =>
                [
                    AQL::MODEL     => $articlesAuthors ,
                    AQL::DIRECTION => Traversal::OUTBOUND ,
                    AQL::FIELDS    => [ '_key' => [] , 'name' => [] ] ,
                ] ,
            ] ,

            AQL::SORTABLE => $sortable ?? [ 'title' , 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ] ,

            AQL::GROUPABLE    => [ 'title' => 'title' , 'author' => [ AQL::EDGE => 'author' , Field::PATH => 'name' ] ] ,
            AQL::AGGREGATABLE => [ 'amount' => 'amount' ] ,
        ]) ;
    }

    /**
     * The titles of the returned rows, in the order the server produced them.
     *
     * @return array<int,string>
     */
    private function titles( array $rows ) :array
    {
        return array_map( fn( $row ) => (string) ( is_array( $row ) ? $row[ 'title' ] : $row->title ) , $rows ) ;
    }

    /**
     * 🔑 The measurement the unit suite cannot make: the rows really come back
     * ordered by the linked author's name, in both directions.
     *
     * Alphabetically the authors are Alice (a2), Mia (a3), Zoe (a1) — so the
     * expected titles are Beta, Gamma, Alpha, which is neither the insertion
     * order nor its reverse. A query that dropped the criterion could not
     * produce it by accident.
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
    public function testTheListIsOrderedByTheLinkedAuthorName() :void
    {
        $model = $this->model() ;

        $this->assertSame
        (
            [ 'Beta' , 'Gamma' , 'Alpha' ] ,
            $this->titles( $model->list( [ Arango::SORT => 'author' ] ) ) ,
            'Ascending: Alice, Mia, Zoe.' ,
        ) ;

        $this->assertSame
        (
            [ 'Alpha' , 'Gamma' , 'Beta' ] ,
            $this->titles( $model->list( [ Arango::SORT => '-author' ] ) ) ,
            'Descending: Zoe, Mia, Alice.' ,
        ) ;
    }

    /**
     * The seed does its job: without the criterion the order is the natural one,
     * which is **neither** of the two asserted above. Without this case, a green
     * run could still mean the sort did nothing.
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
    public function testTheAuthorOrderIsNotTheNaturalOrder() :void
    {
        $natural = $this->titles( $this->model()->list( [] ) ) ;

        $this->assertNotSame( [ 'Beta' , 'Gamma' , 'Alpha' ] , $natural ) ;
        $this->assertNotSame( [ 'Alpha' , 'Gamma' , 'Beta' ] , $natural ) ;
    }

    /**
     * A stored criterion and a relational one live in the same clause, and the
     * relation still projects — one traversal serves both.
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
    public function testTheProjectionStillServesTheRowsItOrders() :void
    {
        $rows = $this->model()->list( [ Arango::SORT => 'author' ] ) ;

        $first = (array) ( is_array( $rows[ 0 ] ) ? $rows[ 0 ] : json_decode( json_encode( $rows[ 0 ] ) , true ) ) ;

        $this->assertSame( 'Beta' , (string) $first[ 'title' ] ) ;
        $this->assertSame( 'Alice' , (string) ( (array) $first[ 'author' ] )[ 'name' ] , 'The ordered-on relation is also returned.' ) ;
    }

    /**
     * 🔑 Grouping through the same relation, which is a different mechanism
     * entirely: a grouped query never projects, so there is no `LET` to name and
     * the dimension carries its own traversal inline in the `COLLECT`.
     *
     * The `SUM` beside it is the point of the whole lot — the facet counts
     * already answer "how many per linked value", and what they cannot do is sit
     * next to another aggregate. It is also the measurement that justifies
     * refusing a plural relation: unwinding one before the `COLLECT` would count
     * a multi-vertex document once per vertex and inflate this very sum.
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
    public function testGroupingThroughTheRelationComposesWithAnAggregate() :void
    {
        $rows = $this->model()->list
        ([
            Arango::GROUP =>
            [
                GroupSpec::BY    => 'author' ,
                GroupSpec::AGG   => [ 'total' => 'sum:amount' ] ,
                GroupSpec::COUNT => true ,
            ] ,
        ]) ;

        $groups = [] ;
        foreach ( $rows as $row )
        {
            $row = (array) ( is_array( $row ) ? $row : json_decode( json_encode( $row ) , true ) ) ;
            $groups[ (string) $row[ 'author' ] ] = [ (int) $row[ 'total' ] , (int) $row[ 'count' ] ] ;
        }
        ksort( $groups ) ;

        // Alice holds a2 (20), Mia holds a3 (30), Zoe holds a1 (10).
        $this->assertSame
        (
            [ 'Alice' => [ 20 , 1 ] , 'Mia' => [ 30 , 1 ] , 'Zoe' => [ 10 , 1 ] ] ,
            $groups ,
        ) ;

        // And the sums add up to the real total: no document counted twice.
        $this->assertSame( 60 , array_sum( array_column( $groups , 0 ) ) ) ;
    }

    /**
     * A declaration that cannot be honoured is refused **loudly**, where an
     * unknown client key stays a silent drop. Measured end to end, since the
     * refusal has to survive the whole model call.
     *
     * @throws ContainerExceptionInterface
     * @throws DependencyException
     * @throws NotFoundException
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws Throwable
     * @throws TomlError
     */
    public function testAnUnpinnedRelationIsRefusedEndToEnd() :void
    {
        // The relation is projected but not pinned: its LET variable is the
        // generated random name, which no declaration can designate.
        $model = $this->model( [ Field::FILTER => Filter::EDGE ] ) ;

        $this->expectException( ValidationException::class ) ;
        $model->list( [ Arango::SORT => 'author' ] ) ;
    }
}
