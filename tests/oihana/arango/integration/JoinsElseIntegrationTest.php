<?php

namespace tests\oihana\arango\integration;

use Devium\Toml\TomlError;
use DI\Container;

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

use Throwable;

use function oihana\arango\models\helpers\buildVariables;
use function oihana\init\initConfig;

/**
 * Live validation of a LIST join that keeps what it cannot resolve — `Field::ELSE` on a
 * `Filter::JOINS` — and of a list of OBJECTS joined on `Arango::PROPERTY => '[*]._key'`.
 *
 * Neutral data — albums naming their tracks :
 *
 * ```
 * tracks  t1 « Intro » (audio) · t2 « Outro » (audio) · t4 « Bonus » (hidden)
 *
 * albums  a1  refs : [ { _key: t1 , id: 1 } , { id: 3 } , { _key: t9 , id: 9 } , { _key: t2 , id: 2 } ]
 *         a2  refs : []
 *         a3  (no refs at all)
 *         a4  keys : [ "t2" , "t1" , "tx" ]                 — a list of bare keys
 *         a5  refs : [ { _key: t4 , id: 4 } , { _key: t1 , id: 1 } ]
 * ```
 *
 * Every case reads the answer of the real model (`Documents::list()`), never the AQL
 * string : the shape the unit suite freezes has to parse AND answer right on a server.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class JoinsElseIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_joins_else_it' ;

    private const string ALBUMS = 'albums' ;
    private const string TRACKS = 'tracks' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        $db->collection( self::ALBUMS )->create() ;
        $db->collection( self::TRACKS )->create() ;

        $tracks = $db->collection( self::TRACKS ) ;
        $tracks->insert( [ '_key' => 't1' , 'name' => 'Intro' , 'kind' => 'audio'  ] ) ;
        $tracks->insert( [ '_key' => 't2' , 'name' => 'Outro' , 'kind' => 'audio'  ] ) ;
        $tracks->insert( [ '_key' => 't4' , 'name' => 'Bonus' , 'kind' => 'hidden' ] ) ;

        $albums = $db->collection( self::ALBUMS ) ;
        $albums->insert( [ '_key' => 'a1' , 'refs' => [ [ '_key' => 't1' , 'id' => '1' ] , [ 'id' => '3' ] , [ '_key' => 't9' , 'id' => '9' ] , [ '_key' => 't2' , 'id' => '2' ] ] ] ) ;
        $albums->insert( [ '_key' => 'a2' , 'refs' => [] ] ) ;
        $albums->insert( [ '_key' => 'a3' ] ) ;
        $albums->insert( [ '_key' => 'a4' , 'keys' => [ 't2' , 't1' , 'tx' ] ] ) ;
        $albums->insert( [ '_key' => 'a5' , 'refs' => [ [ '_key' => 't4' , 'id' => '4' ] , [ '_key' => 't1' , 'id' => '1' ] ] ] ) ;
    }

    // ---- Field::ELSE on a list of objects ------------------------------------

    /**
     * The list keeps its four elements, in their stored order : the two tracks found,
     * and the two references the collection does not hold, as they are stored.
     *
     * @throws Throwable
     */
    public function testElseTrueKeepsEveryElementInItsStoredOrder() :void
    {
        $this->assertSame
        (
            [
                [ '_key' => 't1' , 'name' => 'Intro' ] ,
                [ 'id' => '3' ] ,
                [ '_key' => 't9' , 'id' => '9' ] ,
                [ '_key' => 't2' , 'name' => 'Outro' ] ,
            ] ,
            $this->refsOf( 'a1' , true )
        ) ;
    }

    /**
     * @throws Throwable
     */
    public function testElseOnAnAttributeServesThatAttributeOfTheElement() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't1' , 'name' => 'Intro' ] , '3' , '9' , [ '_key' => 't2' , 'name' => 'Outro' ] ] ,
            $this->refsOf( 'a1' , [ Field::PROPERTY => 'id' ] )
        ) ;
    }

    /**
     * @throws Throwable
     */
    public function testElseOnALiteralServesTheLiteral() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't1' , 'name' => 'Intro' ] , 'unknown' , 'unknown' , [ '_key' => 't2' , 'name' => 'Outro' ] ] ,
            $this->refsOf( 'a1' , 'unknown' )
        ) ;
    }

    /**
     * An empty list and an absent field both answer an empty list.
     *
     * @throws Throwable
     */
    public function testAnEmptyOrAbsentListAnswersAnEmptyList() :void
    {
        $this->assertSame( [] , $this->refsOf( 'a2' , true ) ) ;
        $this->assertSame( [] , $this->refsOf( 'a3' , true ) ) ;
    }

    /**
     * A document a condition masks is not served : its element keeps its fallback,
     * and the element after it is still joined.
     *
     * @throws Throwable
     */
    public function testAMaskedDocumentKeepsItsStoredReference() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't4' , 'id' => '4' ] , [ '_key' => 't1' , 'name' => 'Intro' ] ] ,
            $this->refsOf( 'a5' , true , [ Arango::CONDITIONS => fn( string $join ) => [ $join . '.kind == "audio"' ] ] )
        ) ;
    }

    // ---- a list of bare keys ------------------------------------------------

    /**
     * @throws Throwable
     */
    public function testElseOnAListOfBareKeysKeepsTheKeyItFindsNothingFor() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't2' , 'name' => 'Outro' ] , [ '_key' => 't1' , 'name' => 'Intro' ] , 'tx' ] ,
            $this->albumField( 'a4' , 'keys' , [ Field::FILTER => Filter::JOINS , Field::ELSE => true ] , [] )
        ) ;
    }

    /**
     * Without Field::ELSE, a list of bare keys is the join it always was : the found
     * documents only, sorted on their key, descending.
     *
     * @throws Throwable
     */
    public function testWithoutElseAListOfBareKeysIsUnchanged() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't2' , 'name' => 'Outro' ] , [ '_key' => 't1' , 'name' => 'Intro' ] ] ,
            $this->albumField( 'a4' , 'keys' , [ Field::FILTER => Filter::JOINS ] , [] )
        ) ;
    }

    // ---- a list of objects WITHOUT Field::ELSE --------------------------------

    /**
     * 🚨 The join `'[*]._key'` could not be written before (a syntax error) : it now
     * answers the documents it finds, as any list join does.
     *
     * @throws Throwable
     */
    public function testWithoutElseAListOfObjectsAnswersTheDocumentsItFinds() :void
    {
        $this->assertSame
        (
            [ [ '_key' => 't2' , 'name' => 'Outro' ] , [ '_key' => 't1' , 'name' => 'Intro' ] ] ,
            $this->refsOf( 'a1' , null )
        ) ;
    }

    // ---- the cost --------------------------------------------------------------

    /**
     * Each element is read through the primary index — no scan of the collection.
     *
     * @throws Throwable
     */
    public function testEachElementIsReadThroughThePrimaryIndex() :void
    {
        [ $arangodb ] = $this->context() ;

        $variables = [] ;
        buildVariables
        (
            $variables ,
            [ 'refs' => [ Field::FILTER => Filter::JOINS , Field::ELSE => true ] ] ,
            [] ,
            [ 'refs' => $this->joinDefinition() ]
        ) ;

        $result = $arangodb->explain( 'FOR doc IN ' . self::ALBUMS . ' FILTER doc._key == "a1" ' . $variables[ 0 ] . ' RETURN refs' ) ;

        $primary = array_filter( $result->indexesUsed() , fn( $index ) => $index->type === 'primary' && $index->collection === self::TRACKS ) ;

        $this->assertNotEmpty( $primary , 'the element lookup should use the primary index of the tracks' ) ;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * The `refs` of one album, read by the real model, the join declaring the given
     * Field::ELSE (null : none).
     *
     * @param array<string,mixed> $extra Extra keys of the join definition.
     *
     * @return mixed
     *
     * @throws Throwable
     */
    private function refsOf( string $album , mixed $else , array $extra = [] ) :mixed
    {
        $field = [ Field::FILTER => Filter::JOINS ] ;

        if ( $else !== null )
        {
            $field[ Field::ELSE ] = $else ;
        }

        return $this->albumField( $album , 'refs' , $field , [ Arango::PROPERTY => '[*]._key' , ...$extra ] ) ;
    }

    /**
     * One field of one album, read by a live model declaring that field as a join on
     * the tracks.
     *
     * @param array<string,mixed> $field The field declaration.
     * @param array<string,mixed> $extra Extra keys of the join definition.
     *
     * @return mixed
     *
     * @throws Throwable
     */
    private function albumField( string $album , string $name , array $field , array $extra ) :mixed
    {
        [ $arangodb , $container ] = $this->context() ;

        $albums = new Documents( $container ,
        [
            Arango::DATABASE => $arangodb ,
            AQL::COLLECTION  => self::ALBUMS ,
            AQL::LAZY        => false ,
            AQL::FIELDS      => [ '_key' => Filter::DEFAULT , $name => $field ] ,
            AQL::JOINS       => [ $name => [ ...$this->joinDefinition() , ...$extra ] ] ,
        ]) ;

        foreach ( $albums->list( [ AQL::LIMIT => 0 ] ) as $row )
        {
            $row = json_decode( json_encode( $row ) , true ) ;

            if ( ( $row[ '_key' ] ?? null ) === $album )
            {
                return $row[ $name ] ?? null ;
            }
        }

        $this->fail( 'album ' . $album . ' not read' ) ;
    }

    /**
     * The join on the tracks — their key and name.
     *
     * @return array<string,mixed>
     *
     * @throws Throwable
     */
    private function joinDefinition() :array
    {
        [ $arangodb , $container ] = $this->context() ;

        return
        [
            AQL::MODEL  => new Documents( $container , [ Arango::DATABASE => $arangodb , AQL::COLLECTION => self::TRACKS , AQL::LAZY => false ] ) ,
            AQL::FIELDS => [ '_key' => [] , 'name' => [] ] ,
        ] ;
    }

    /**
     * A live ArangoDB façade + container wired to the disposable database.
     *
     * @return array{ 0: ArangoDB , 1: Container }
     *
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
}
