<?php

namespace tests\oihana\arango\integration;

use Throwable;

use DI\Container;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use oihana\arango\clients\Database;
use oihana\arango\clients\collection\indexes\InvertedIndex;
use oihana\arango\clients\collection\indexes\RawIndexDefinition;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\AQL;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\enums\IndexType;
use oihana\arango\db\options\indexes\GeoIndexOptions;
use oihana\arango\db\options\indexes\IndexOptions;
use oihana\arango\db\options\indexes\MDIIndexOptions;
use oihana\arango\db\options\indexes\PersistentIndexOptions;
use oihana\arango\db\options\indexes\TTLIndexOptions;
use oihana\arango\db\options\indexes\VectorIndexOptions;
use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function oihana\init\initConfig;

/**
 * Live proof that every index declared through its options class is created, and
 * that the comparison reads no difference right after.
 *
 * 🚨 **The unit essays of the comparison write the server's answer themselves**, and
 * that is how a class creating no index at all went unseen : the multi-dimensional
 * options sent two empty lists the server refuses, and an `estimates` it answers
 * otherwise. Only the server says what it accepts and what it answers.
 *
 * Each declaration gets a collection of its own, so a comparison reads its index and
 * nothing else. The vector index needs a server run with
 * `--experimental-vector-index` ; without it, its essay is skipped and says why.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class IndexDeclarationsIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_index_declarations_it' ;

    /**
     * @throws ArangoException
     */
    protected static function seed( Database $db ) :void
    {
        foreach ( array_keys( self::declarations() ) as $name )
        {
            $places = $db->collection( self::collectionOf( $name ) ) ;
            $places->create() ;

            for ( $i = 0 ; $i < 20 ; $i++ )
            {
                $places->insert
                ([
                    '_key'     => "p$i" ,
                    'owner'    => 'o' . ( $i % 4 ) ,
                    'due'      => sprintf( '2026-03-%02d' , 1 + $i ) ,
                    'at'       => 1700000000 + $i ,
                    'location' => [ 45.0 + $i / 100 , 1.0 ] ,
                    'area'     => [ 'type' => 'Point' , 'coordinates' => [ 1.0 , 45.0 + $i / 100 ] ] ,
                    'x'        => 1.0 * $i ,
                    'y'        => 2.0 * $i ,
                    'kind'     => $i % 2 ? 'a' : 'b' ,
                    'label'    => "label $i" ,
                    'vector'   => [ 0.1 * $i , 0.2 , 0.3 ] ,
                ]) ;
            }
        }
    }

    /**
     * One declaration per type and per option the audit measured.
     *
     * @return array<string,array{0:IndexOptions}>
     */
    public static function declarations() :array
    {
        return
        [
            'persistent'                 => [ new PersistentIndexOptions([ IndexOptions::NAME => 'byOwnerDue' , IndexOptions::FIELDS => [ 'owner' , 'due' ] ]) ] ,
            'persistent, stored values'  => [ new PersistentIndexOptions([ IndexOptions::NAME => 'byOwner' , IndexOptions::FIELDS => [ 'owner' ] , IndexOptions::STORED_VALUES => [ 'due' , '_key' ] ]) ] ,
            'persistent, unique sparse'  => [ new PersistentIndexOptions([ IndexOptions::NAME => 'byLabel' , IndexOptions::FIELDS => [ 'label' ] , IndexOptions::UNIQUE => true , IndexOptions::SPARSE => true ]) ] ,
            'ttl'                        => [ new TTLIndexOptions([ IndexOptions::NAME => 'expiry' , IndexOptions::FIELDS => [ 'at' ] , IndexOptions::EXPIRE_AFTER => 86400 * 3650 ]) ] ,
            'ttl, a decimal lifetime'    => [ new TTLIndexOptions([ IndexOptions::NAME => 'expiryDecimal' , IndexOptions::FIELDS => [ 'at' ] , IndexOptions::EXPIRE_AFTER => 86400 * 3650 + 0.5 ]) ] ,
            // 🚨 A whole number written as a decimal comes back as an integer : the
            // comparison announced a drift until it compared numbers by their value.
            'ttl, a whole decimal'       => [ new TTLIndexOptions([ IndexOptions::NAME => 'expiryWhole' , IndexOptions::FIELDS => [ 'at' ] , IndexOptions::EXPIRE_AFTER => 86400.5 * 3650 ]) ] ,
            'geo'                        => [ new GeoIndexOptions([ IndexOptions::NAME => 'where' , IndexOptions::FIELDS => [ 'location' ] ]) ] ,
            'geo, legacy polygons'       => [ new GeoIndexOptions([ IndexOptions::NAME => 'whereLegacy' , IndexOptions::FIELDS => [ 'area' ] , IndexOptions::GEO_JSON => true , IndexOptions::LEGACY_POLYGONS => true ]) ] ,
            'mdi'                        => [ new MDIIndexOptions([ IndexOptions::NAME => 'xy' , IndexOptions::FIELDS => [ 'x' , 'y' ] ]) ] ,
            'mdi-prefixed'               => [ new MDIIndexOptions([ IndexOptions::NAME => 'kindXy' , IndexOptions::TYPE => IndexType::MDI_PREFIXED , IndexOptions::FIELDS => [ 'x' , 'y' ] , IndexOptions::PREFIX_FIELDS => [ 'kind' ] ]) ] ,
            'mdi-prefixed, stored'       => [ new MDIIndexOptions([ IndexOptions::NAME => 'kindXyStored' , IndexOptions::TYPE => IndexType::MDI_PREFIXED , IndexOptions::FIELDS => [ 'x' , 'y' ] , IndexOptions::PREFIX_FIELDS => [ 'kind' ] , IndexOptions::STORED_VALUES => [ 'label' ] ]) ] ,
        ] ;
    }

    /**
     * The collection a declaration is created on.
     */
    private static function collectionOf( string $declaration ) :string
    {
        return 'places_' . substr( md5( $declaration ) , 0 , 8 ) ;
    }

    private function facade() :ArangoDB
    {
        return new ArangoDB( [ ...$this->arango() , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;
    }

    /**
     * @return array<string,mixed>
     */
    private function arango() :array
    {
        $config = initConfig( basePath: dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ) ;

        return is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;
    }

    /**
     * 🚨 Created, then compared without a difference : the declaration sends only what
     * the server accepts, and what it sends the server answers back.
     */
    #[DataProvider( 'declarations' )]
    public function testTheDeclaredIndexIsCreatedAndReadsInSync( IndexOptions $declaration ) :void
    {
        $collection = self::collectionOf( (string) $this->dataName() ) ;
        $facade     = $this->facade() ;

        $this->assertNotNull( $facade->createIndex( $collection , $declaration ) , 'the server refused the declaration' ) ;

        $report = $facade->indexesDiff( $collection , [ $declaration ] ) ;

        $this->assertSame( DiffStatus::IN_SYNC , $report->status , implode( ' | ' , $report->changes ) ) ;
    }

    /**
     * The stored values reach the server — the key the library used to offer for
     * them filled nothing.
     */
    public function testTheStoredValuesReachTheServer() :void
    {
        $collection = self::collectionOf( 'persistent, stored values' ) ;
        $facade     = $this->facade() ;

        $facade->createIndex( $collection , self::declarations()[ 'persistent, stored values' ][ 0 ] ) ;

        $stored = null ;

        foreach ( $facade->getIndexes( $collection ) as $index )
        {
            if ( ( $index[ 'name' ] ?? null ) === 'byOwner' )
            {
                $stored = $index[ 'storedValues' ] ?? null ;
            }
        }

        $this->assertSame( [ 'due' , '_key' ] , $stored ) ;
    }

    /**
     * ⚠️ An experimental index : skipped, with the server's own reason, when the
     * feature is off.
     */
    public function testTheVectorIndexIsCreatedAndReadsInSync() :void
    {
        $collection  = self::collectionOf( 'persistent' ) ;
        $declaration = new VectorIndexOptions
        ([
            IndexOptions::NAME   => 'near' ,
            IndexOptions::FIELDS => [ 'vector' ] ,
            IndexOptions::PARAMS => [ 'metric' => 'cosine' , 'dimension' => 3 , 'nLists' => 2 ] ,
        ]) ;

        try
        {
            self::$db?->collection( $collection )->createIndex( new RawIndexDefinition( $declaration->jsonSerialize() ) ) ;
        }
        catch ( Throwable $exception )
        {
            if ( str_contains( $exception->getMessage() , 'experimental-vector-index' ) )
            {
                $this->markTestSkipped( 'the server runs without --experimental-vector-index : ' . $exception->getMessage() ) ;
            }

            throw $exception ;
        }

        $report = $this->facade()->indexesDiff( $collection , [ $declaration ] ) ;

        $this->assertNotContains( 'near : missing on the server' , $report->changes ) ;
        $this->assertSame( [] , array_values( array_filter( $report->changes , fn( string $change ) => str_starts_with( $change , 'near.' ) ) ) , implode( ' | ' , $report->changes ) ) ;
    }

    /**
     * 🚨 A model creates the inverted index it declares through its client definition
     * when it creates its collection — the creation used to accept an options class
     * only, and there is no options class for an inverted index.
     */
    public function testAModelCreatesTheInvertedIndexItDeclares() :void
    {
        $container = new Container() ;
        $container->set( LoggerInterface::class , new NullLogger() ) ;

        new Documents( $container ,
        [
            Arango::DATABASE => $this->facade() ,
            AQL::COLLECTION  => 'articles' ,
            AQL::LAZY        => true ,
            AQL::INDEXES     => [ new InvertedIndex( fields : [ 'title' ] , name : 'byTitle' ) ] ,
        ]) ;

        $types = [] ;

        foreach ( $this->facade()->getIndexes( 'articles' ) as $index )
        {
            $types[ $index[ 'name' ] ?? '?' ] = $index[ 'type' ] ?? '?' ;
        }

        $this->assertSame( 'inverted' , $types[ 'byTitle' ] ?? null ) ;
    }
}
