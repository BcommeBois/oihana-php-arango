<?php

namespace tests\oihana\arango\integration;

use Throwable;

use Devium\Toml\TomlError;

use Psr\Log\NullLogger;

use oihana\arango\clients\analyzer\enums\AnalyzerFeature;
use oihana\arango\clients\analyzer\TextAnalyzer;
use oihana\arango\commands\actions\ArangoAnalyzersAction;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoConfigTrait;
use oihana\arango\db\ArangoDB;
use oihana\arango\db\enums\ArangoConfig;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;

use oihana\commands\enums\ExitCode;

use PHPUnit\Framework\Attributes\Group;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

use function oihana\init\initConfig;

/**
 * Live end-to-end coverage of the `analyzers` command action (Lot A3a) — the
 * real `resolveDatabase()` / `resolveFacade()` seams against a disposable
 * database: list, `--diff` (missing → in sync) and `--sync` (creation).
 *
 * Skipped when no ArangoDB is reachable (see {@see IntegrationTestCase}).
 *
 * @group integration
 */
#[Group( 'integration' )]
final class AnalyzerCommandIntegrationTest extends IntegrationTestCase
{
    protected static string $database = 'oihana_analyzercmd_it' ;

    /**
     * A command host driving the real {@see ArangoAnalyzersAction} against the
     * disposable database, with the given analyzer registry.
     *
     * @param array<int, AnalyzerDefinition> $analyzers
     *
     * @throws TomlError
     * @throws Throwable
     */
    private function host( array $analyzers ) :object
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        return new class( $arango , $analyzers )
        {
            use ArangoAnalyzersAction ;
            use ArangoConfigTrait ;

            public function __construct( array $arango , array $analyzers )
            {
                $this->endpoint  = $arango[ ArangoConfig::ENDPOINT ] ?? '' ;
                $this->username  = $arango[ ArangoConfig::USER ]     ?? '' ;
                $this->password  = $arango[ ArangoConfig::PASSWORD ] ?? '' ;
                $this->database  = AnalyzerCommandIntegrationTest::databaseName() ;
                $this->analyzers = $analyzers ;
            }

            public function getQuestionHelper() :QuestionHelper
            {
                return new QuestionHelper() ;
            }

            public function run( $input , $output ) :int
            {
                return $this->analyzers( $input , $output ) ;
            }
        } ;
    }

    /**
     * An {@see ArangoDB} façade wired to the disposable database — used to seed
     * and inspect the live server state around the command runs.
     *
     * @throws TomlError
     * @throws Throwable
     */
    private function arango() :ArangoDB
    {
        $configDir = dirname( __DIR__ , 4 ) . DIRECTORY_SEPARATOR . 'configs' ;
        $config    = initConfig( basePath: $configDir ) ;
        $arango    = is_array( $config[ 'arango' ] ?? null ) ? $config[ 'arango' ] : [] ;

        return new ArangoDB( [ ...$arango , ArangoConfig::DATABASE => static::$database ] , new NullLogger() ) ;
    }

    public static function databaseName() :string
    {
        return static::$database ;
    }

    private function input( array $options = [] ) :ArrayInput
    {
        $definition = new InputDefinition
        ([
            new InputOption( ArangoCommandOption::DATABASE , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER     , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DIFF     , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FIX      , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::FORCE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::PRUNE    , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::SYNC     , null , InputOption::VALUE_OPTIONAL , '' , false ) ,
            new InputOption( ArangoCommandOption::YES      , null , InputOption::VALUE_NONE ) ,
        ]) ;

        $input = new ArrayInput( $options , $definition ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /**
     * @throws TomlError
     * @throws Throwable
     */
    public function testDiffThenSyncThenList() :void
    {
        $definition = new AnalyzerDefinition
        (
            'cmd_az' ,
            new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: true ) ,
            [ AnalyzerFeature::FREQUENCY , AnalyzerFeature::POSITION ] ,
        ) ;

        // A fresh host per run : getIO() memoizes its SymfonyStyle on the first
        // call, so each invocation needs its own host + output.

        // 1 — diff : the declared analyzer is missing on the server.
        $output = new BufferedOutput() ;
        $this->host( [ $definition ] )->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $this->assertStringContainsString( 'cmd_az — missing on the server' , $output->fetch() ) ;

        // 2 — sync : it gets created.
        $output = new BufferedOutput() ;
        $code   = $this->host( [ $definition ] )->run( $this->input( [ '--' . ArangoCommandOption::SYNC => null ] ) , $output ) ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'cmd_az — created' , $output->fetch() ) ;

        // 3 — diff again : now in sync.
        $output = new BufferedOutput() ;
        $this->host( [ $definition ] )->run( $this->input( [ '--' . ArangoCommandOption::DIFF => true ] ) , $output ) ;
        $this->assertStringContainsString( 'cmd_az — in sync' , $output->fetch() ) ;

        // 4 — list : the custom analyzer is shown, built-ins counted apart.
        $output = new BufferedOutput() ;
        $this->host( [ $definition ] )->run( $this->input() , $output ) ;
        $text = $output->fetch() ;
        $this->assertStringContainsString( '→ cmd_az (text)' , $text ) ;
        $this->assertStringContainsString( 'built-in' , $text ) ;
    }

    /**
     * `--prune` drops an orphan custom analyzer (declared by none) from the
     * live server.
     *
     * @throws TomlError
     * @throws Throwable
     */
    public function testPruneDropsAnOrphanCustomAnalyzer() :void
    {
        $arango = $this->arango() ;

        // Seed a custom analyzer that the (empty) registry declares by none.
        $arango->analyzerSync( new AnalyzerDefinition( 'cmd_orphan' , new TextAnalyzer( locale: 'en' ) , [] ) ) ;
        $this->assertTrue( $arango->analyzerExists( 'cmd_orphan' ) ) ;

        $output = new BufferedOutput() ;
        $code   = $this->host( [] )->run( $this->input( [ '--' . ArangoCommandOption::PRUNE => true , '--' . ArangoCommandOption::YES => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'cmd_orphan — dropped (orphan)' , $output->fetch() ) ;
        $this->assertFalse( $arango->analyzerExists( 'cmd_orphan' ) ) ;
    }

    /**
     * `--fix` writes a repair migration for a drifted analyzer and leaves the
     * live server untouched.
     *
     * @throws TomlError
     * @throws Throwable
     */
    public function testFixGeneratesARepairMigrationAndTouchesNoDatabase() :void
    {
        $arango = $this->arango() ;

        // Seed the server analyzer with stemming ON, declare it with stemming OFF → drift.
        $arango->analyzerSync( new AnalyzerDefinition( 'cmd_fix_az' , new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: true ) , [] ) ) ;
        $declared = new AnalyzerDefinition( 'cmd_fix_az' , new TextAnalyzer( locale: 'en' , case: 'lower' , accent: false , stemming: false ) , [] ) ;

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oihana_cmdfix_' . uniqid() ;
        mkdir( $dir ) ;

        $host = $this->host( [ $declared ] ) ;
        $host->migrationsPath      = $dir ;
        $host->migrationsNamespace = 'tests\\live\\migrations' ;

        $output = new BufferedOutput() ;
        $code   = $host->run( $this->input( [ '--' . ArangoCommandOption::FIX => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'repair migration generated' , $output->fetch() ) ;

        $files = glob( $dir . DIRECTORY_SEPARATOR . 'Version*.php' ) ;
        $this->assertCount( 1 , $files ) ;
        $this->assertStringContainsString( "new RawAnalyzer( 'text'" , file_get_contents( $files[ 0 ] ) ) ;

        // --fix touches no database : the server analyzer is still the stemmed one.
        $this->assertTrue( $arango->database()->analyzer( 'cmd_fix_az' )->get()[ 'properties' ][ 'stemming' ] ?? false ) ;

        foreach ( $files as $file )
        {
            unlink( $file ) ;
        }
        rmdir( $dir ) ;
    }
}
