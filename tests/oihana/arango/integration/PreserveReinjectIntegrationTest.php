<?php

namespace tests\oihana\arango\integration;

use RuntimeException;
use Throwable;

use DI\Container;

use oihana\arango\clients\collection\enums\CollectionField;
use oihana\arango\clients\collection\enums\CollectionType;
use oihana\arango\clients\Database;
use oihana\arango\commands\ArangoCommand;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\models\enums\filters\FilterType;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\Group;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function oihana\init\initConfig;

/**
 * Live end-to-end coverage of the `preserve` and `reinject` actions, driven
 * through the real {@see ArangoCommand} constructor (the `[arango.preserves]`
 * rules arrive by its init, as in a host) against a disposable database.
 *
 * The scene : a collection mixes documents that come from elsewhere (`src:…`
 * keys, identical everywhere) with documents created locally (numeric keys). A
 * restore replaces the collection ; `preserve` before and `reinject` after must
 * leave the local documents where they were, and drop the foreign ones the
 * restore brought along. The same for an edge collection, whose `_from` / `_to`
 * must survive.
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 * @group integration
 */
#[Group( 'integration' )]
final class PreserveReinjectIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_preserve_it' ;

    private const string ARTICLES = 'articles' ;

    private const string LINKS = 'article_links' ;

    private const string RULE = '{"key":"_key","op":"nsw","val":"src:"}' ;

    protected static function seed( Database $db ) :void
    {
        $db->collection( self::ARTICLES )->create() ;
        $db->collection( self::LINKS )->create( [ CollectionField::TYPE => CollectionType::EDGE ] ) ;
    }

    protected function setUp() :void
    {
        parent::setUp() ;

        foreach( [ self::ARTICLES , self::LINKS ] as $collection )
        {
            if( self::$db->collection( $collection . '__preserved' )->exists() )
            {
                self::$db->collection( $collection . '__preserved' )->drop() ;
            }
        }

        // The local state : two documents from elsewhere, two created here.
        $this->replace( self::ARTICLES , [ [ '_key' => 'src:1' ] , [ '_key' => 'src:2' ] , [ '_key' => '10' ] , [ '_key' => '11' ] ] ) ;
        $this->replace( self::LINKS ,
        [
            [ '_key' => 'src:e1' , '_from' => 'articles/src:1' , '_to' => 'articles/src:2' ] ,
            [ '_key' => 'e10'    , '_from' => 'articles/10'    , '_to' => 'articles/11'    ] ,
        ]) ;
    }

    /**
     * Empties a collection and fills it with `$documents` — what a restore does.
     */
    private function replace( string $collection , array $documents ) :void
    {
        self::$db->query( 'FOR doc IN @@c REMOVE doc IN @@c' , [ '@c' => $collection ] ) ;
        foreach( $documents as $document )
        {
            self::$db->collection( $collection )->insert( $document ) ;
        }
    }

    /**
     * What a restore of the archive brings : the documents from elsewhere (one
     * more than before) and a test document created on the other machine.
     */
    private function restoreTheArchive() :void
    {
        $this->replace( self::ARTICLES , [ [ '_key' => 'src:1' ] , [ '_key' => 'src:2' ] , [ '_key' => 'src:3' ] , [ '_key' => '20' ] ] ) ;
        $this->replace( self::LINKS ,
        [
            [ '_key' => 'src:e1' , '_from' => 'articles/src:1' , '_to' => 'articles/src:2' ] ,
            [ '_key' => 'e20'    , '_from' => 'articles/20'    , '_to' => 'articles/src:1' ] ,
        ]) ;
    }

    /** @return array<int, string> */
    private function keys( string $collection ) :array
    {
        return array_map( fn( array $doc ) => $doc[ '_key' ] , iterator_to_array( self::$db->query( 'FOR doc IN @@c SORT doc._key RETURN doc' , [ '@c' => $collection ] ) , false ) ) ;
    }

    /**
     * The real command, with `$rule` as the rule of both collections. A fresh
     * command per run : getIO() memoizes its output on the first call.
     */
    private function command( string $rule = self::RULE , array $protected = [] ) :ArangoCommand
    {
        $config = initConfig( basePath: dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ) ;
        $arango = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        $preserve = [ ArangoCommandParam::PRESERVE_FILTERS => [ '_key' => FilterType::STRING ] , ArangoCommandParam::PRESERVE_FILTER => $rule ] ;

        $init =
        [
            ...$arango ,
            ArangoConfig::DATABASE         => static::$database ,
            ArangoCommandParam::PRESERVES  => [ self::ARTICLES => $preserve , self::LINKS => $preserve ] ,
            ArangoCommandParam::RESTORE    => [ ArangoCommandParam::PROTECTED => $protected ] ,
        ] ;

        return new class( ArangoCommand::NAME , new Container() , $init ) extends ArangoCommand
        {
            public function runPreserve( InputInterface $input , OutputInterface $output ) :int
            {
                return $this->preserve( $input , $output ) ;
            }

            public function runReinject( InputInterface $input , OutputInterface $output ) :int
            {
                return $this->reinject( $input , $output ) ;
            }
        } ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $input = new ArrayInput( $options , new InputDefinition
        ([
            new InputOption( ArangoCommandOption::COLLECTION , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::DATABASE   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DRY_RUN    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE      , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::YES        , null , InputOption::VALUE_NONE ) ,
        ]) ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    private function preserve() :string
    {
        $output = new BufferedOutput() ;
        $this->assertSame( ExitCode::SUCCESS , $this->command()->runPreserve( $this->input() , $output ) ) ;
        return $output->fetch() ;
    }

    /**
     * @throws Throwable
     */
    public function testTheLocalDocumentsSurviveARestore() :void
    {
        $text = $this->preserve() ;

        $this->assertStringContainsString( 'articles : 2 document(s) set aside in articles__preserved' , $text ) ;
        $this->assertStringContainsString( 'article_links : 1 document(s) set aside in article_links__preserved' , $text ) ;
        $this->assertSame( [ '10' , '11' ] , $this->keys( 'articles__preserved' ) ) ;
        $this->assertSame( CollectionType::EDGE , self::$db->collection( 'article_links__preserved' )->properties()[ CollectionField::TYPE ] ) ;

        $this->restoreTheArchive() ;

        $output = new BufferedOutput() ;
        $code   = $this->command()->runReinject( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;
        $text   = $output->fetch() ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'articles : 1 removed · 2 put back · articles__preserved dropped' , $text ) ;
        $this->assertStringContainsString( 'article_links : 1 removed · 1 put back · article_links__preserved dropped' , $text ) ;

        // The archive's documents from elsewhere stay, its test document is gone, ours are back.
        $this->assertSame( [ '10' , '11' , 'src:1' , 'src:2' , 'src:3' ] , $this->keys( self::ARTICLES ) ) ;
        $this->assertSame( [ 'e10' , 'src:e1' ] , $this->keys( self::LINKS ) ) ;

        $edge = iterator_to_array( self::$db->query( 'RETURN DOCUMENT( "article_links/e10" )' ) , false )[ 0 ] ;
        $this->assertSame( 'articles/10' , $edge[ '_from' ] ) ;
        $this->assertSame( 'articles/11' , $edge[ '_to' ] ) ;

        $this->assertFalse( self::$db->collection( 'articles__preserved' )->exists() ) ;
        $this->assertFalse( self::$db->collection( 'article_links__preserved' )->exists() ) ;
    }

    public function testAPendingBackupIsNeverOverwritten() :void
    {
        $this->preserve() ;

        $output = new BufferedOutput() ;
        $code   = $this->command()->runPreserve( $this->input() , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Run reinject first' , preg_replace( '/\s+/' , ' ' , $output->fetch() ) ) ;
        $this->assertSame( [ '10' , '11' ] , $this->keys( 'articles__preserved' ) ) ;
    }

    /**
     * @throws Throwable
     */
    public function testAFailedPutBackChangesNothingAndCanBeRunAgain() :void
    {
        $this->preserve() ;
        $this->restoreTheArchive() ;

        // A document of the backup collides with one of the archive.
        self::$db->collection( 'articles__preserved' )->insert( [ '_key' => 'src:1' ] ) ;

        try
        {
            $this->command()->runReinject( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , new BufferedOutput() ) ;
            $this->fail( 'A duplicate key must abort the put back.' ) ;
        }
        catch( Throwable $exception )
        {
            $this->assertStringContainsString( 'unique constraint violated' , $exception->getMessage() ) ;
        }

        // The removal of the archive's test document was rolled back with the rest.
        $this->assertSame( [ '20' , 'src:1' , 'src:2' , 'src:3' ] , $this->keys( self::ARTICLES ) ) ;
        $this->assertSame( [ '10' , '11' , 'src:1' ] , $this->keys( 'articles__preserved' ) ) ;

        // Once the backup is fixed, running reinject again finishes the job.
        self::$db->collection( 'articles__preserved' )->remove( 'src:1' ) ;

        $code = $this->command()->runReinject( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , new BufferedOutput() ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertSame( [ '10' , '11' , 'src:1' , 'src:2' , 'src:3' ] , $this->keys( self::ARTICLES ) ) ;
    }

    public function testARuleChangedSincePreserveChangesNothing() :void
    {
        $this->preserve() ;
        $this->restoreTheArchive() ;

        // The new rule no longer names what was set aside : 10 and 11 start with "1".
        try
        {
            $this->command( '{"key":"_key","op":"nsw","val":"1"}' )->runReinject( $this->input( [ '--' . ArangoCommandOption::YES => true , '--' . ArangoCommandOption::COLLECTION => [ self::ARTICLES ] ] ) , new BufferedOutput() ) ;
            $this->fail( 'A rule that changed must abort the put back.' ) ;
        }
        catch( RuntimeException $exception )
        {
            $this->assertStringContainsString( 'has the rule changed since preserve' , $exception->getMessage() ) ;
        }

        $this->assertSame( [ '20' , 'src:1' , 'src:2' , 'src:3' ] , $this->keys( self::ARTICLES ) ) ;
        $this->assertTrue( self::$db->collection( 'articles__preserved' )->exists() ) ;
    }

    public function testWithoutABackupNothingIsRemoved() :void
    {
        $output = new BufferedOutput() ;
        $code   = $this->command()->runReinject( $this->input( [ '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::FAILURE , $code ) ;
        $this->assertStringContainsString( 'Nothing to reinject' , $output->fetch() ) ;
        $this->assertSame( [ '10' , '11' , 'src:1' , 'src:2' ] , $this->keys( self::ARTICLES ) ) ;
    }
}
