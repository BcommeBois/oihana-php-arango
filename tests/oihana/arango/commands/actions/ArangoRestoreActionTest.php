<?php

namespace tests\oihana\arango\commands\actions;

use Phar;
use PharData;

use oihana\arango\commands\actions\ArangoRestoreAction;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\options\ArangoRestoreOptions;

use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;

use oihana\enums\Char;
use oihana\files\enums\FileExtension;
use oihana\files\exceptions\FileException;

use PHPUnit\Framework\TestCase;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Host wiring {@see ArangoRestoreAction::restore()} for integration testing.
 *
 * Server config is supplied through the trait properties and the external
 * `arangorestore` process is stubbed out by overriding {@see arangoRestore()},
 * so the test exercises the real file-selection / decrypt-skip / untar /
 * option-assembly flow without touching ArangoDB.
 */
class ArangoRestoreActionHost
{
    use ArangoRestoreAction ;

    public string $id = 'test' ;

    /** Captured arguments of the (stubbed) restore process. */
    public array $capturedRestoreOptions = [] ;
    public bool  $restoreCalled          = false ;

    public function __construct( string $directory )
    {
        $this->directory = $directory ;
        $this->encrypt   = false ;                    // skip the decrypt branch
        $this->database  = 'mydb' ;
        $this->endpoint  = 'tcp://127.0.0.1:8529' ;
        $this->password  = 'secret' ;
        $this->username  = 'root' ;
    }

    public function getName() :string
    {
        return 'restore' ;
    }

    /** Stub the proc_open seam so no external binary is launched. */
    public function arangoRestore( array|ArangoRestoreOptions|null $options = null , bool $silent = false ) :int
    {
        $this->restoreCalled          = true ;
        $this->capturedRestoreOptions = (array) $options ;
        return ExitCode::SUCCESS ;
    }
}

/**
 * Minimal host exposing the protected static suffix helper of
 * {@see ArangoRestoreAction} so it can be unit-tested in isolation.
 */
class ArangoRestoreActionStub
{
    use ArangoRestoreAction ;

    public static function suffix( string $database , bool $encrypt = false , bool $partial = false , ?string $label = null ) :string
    {
        return static::getArchiveFileSuffix( $database , $encrypt , $partial , $label ) ;
    }
}

/**
 * Unit coverage for {@see ArangoRestoreAction::getArchiveFileSuffix()}.
 *
 * Guards against the operator-precedence regression where
 * `Char::DASH . $database . $encrypt ? A : B` was parsed as
 * `(Char::DASH . $database . $encrypt) ? A : B` — a string condition that is
 * always truthy, so the suffix collapsed to the encrypted extension and lost
 * the `-{database}` segment. Also guards the gzip extension (`.tar.gz`), since
 * the dump action always produces a gzip-compressed tarball.
 */
class ArangoRestoreActionTest extends TestCase
{
    public function testSuffixWhenNotEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' , false ) ) ;
    }

    public function testSuffixWhenEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz.enc' , ArangoRestoreActionStub::suffix( 'mydb' , true ) ) ;
    }

    public function testSuffixDefaultsToNotEncrypted() :void
    {
        $this->assertSame( '-mydb.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' ) ) ;
    }

    public function testSuffixKeepsDatabaseSegment() :void
    {
        // The precedence bug used to drop the "-{database}" part entirely.
        $this->assertStringContainsString( Char::DASH . 'orders' , ArangoRestoreActionStub::suffix( 'orders' , true ) ) ;
        $this->assertStringStartsWith( Char::DASH . 'orders' , ArangoRestoreActionStub::suffix( 'orders' , false ) ) ;
    }

    public function testEncryptionFlagActuallyChangesTheExtension() :void
    {
        // The precedence bug made both branches return the same (encrypted) value.
        $this->assertNotSame
        (
            ArangoRestoreActionStub::suffix( 'mydb' , true ) ,
            ArangoRestoreActionStub::suffix( 'mydb' , false ) ,
        ) ;
    }

    public function testSuffixUsesCanonicalFileExtensions() :void
    {
        $this->assertSame( '-mydb' . FileExtension::TAR_GZ           , ArangoRestoreActionStub::suffix( 'mydb' , false ) ) ;
        $this->assertSame( '-mydb' . FileExtension::TAR_GZ_ENCRYPTED , ArangoRestoreActionStub::suffix( 'mydb' , true  ) ) ;
    }

    public function testSuffixPartialDump() :void
    {
        $this->assertSame( '-mydb-partial.tar.gz'     , ArangoRestoreActionStub::suffix( 'mydb' , false , true ) ) ;
        $this->assertSame( '-mydb-partial.tar.gz.enc' , ArangoRestoreActionStub::suffix( 'mydb' , true  , true ) ) ;
    }

    public function testSuffixPartialWithLabel() :void
    {
        $this->assertSame
        (
            '-mydb-partial-pre-migration.tar.gz' ,
            ArangoRestoreActionStub::suffix( 'mydb' , false , true , 'pre-migration' ) ,
        ) ;
        $this->assertSame
        (
            '-mydb-partial-pre-migration.tar.gz.enc' ,
            ArangoRestoreActionStub::suffix( 'mydb' , true , true , 'pre-migration' ) ,
        ) ;
    }

    public function testSuffixFullWithLabel() :void
    {
        $this->assertSame( '-mydb-nightly.tar.gz' , ArangoRestoreActionStub::suffix( 'mydb' , false , false , 'nightly' ) ) ;
    }

    // ------------------------------------------------------------------ restore() integration

    private string $dumpDir = '' ;

    protected function setUp() :void
    {
        $this->dumpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arango_restore_test_' . bin2hex( random_bytes( 6 ) ) ;
        mkdir( $this->dumpDir , 0o777 , true ) ;
    }

    protected function tearDown() :void
    {
        foreach ( glob( $this->dumpDir . DIRECTORY_SEPARATOR . '*' ) ?: [] as $f )
        {
            @unlink( $f ) ;
        }
        @rmdir( $this->dumpDir ) ;
    }

    /**
     * Full option/argument surface the restore() action reads, so a plain
     * ArrayInput can answer every getOption()/getArgument() call.
     */
    private function definition() :InputDefinition
    {
        return new InputDefinition
        ([
            new InputArgument( 'action' , InputArgument::OPTIONAL ) ,
            new InputOption( ArangoCommandOption::LIST       , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::LAST       , null , InputOption::VALUE_NONE ) ,
            new InputOption( ArangoCommandOption::DIRECTORY  , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::FILE       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::DATE       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::LABEL      , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::COLLECTION , null , InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY ) ,
            new InputOption( ArangoCommandOption::DATABASE   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::ENDPOINT   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::PASSWORD   , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( ArangoCommandOption::USER       , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( 'encrypt'    , null , InputOption::VALUE_OPTIONAL ) ,
            new InputOption( 'passphrase' , null , InputOption::VALUE_OPTIONAL ) ,
        ]) ;
    }

    private function input( array $options ) :ArrayInput
    {
        $input = new ArrayInput( $options , $this->definition() ) ;
        $input->setInteractive( false ) ;
        return $input ;
    }

    /** Builds a real gzip-compressed tar archive at $path (which must end in .tar.gz). */
    private function makeArchive( string $path ) :void
    {
        $tarPath = $this->dumpDir . DIRECTORY_SEPARATOR . 'build_' . bin2hex( random_bytes( 4 ) ) . '.tar' ;

        $tar = new PharData( $tarPath ) ;
        $tar->addFromString( 'dump/sample.json' , '{"_key":"1"}' ) ;
        $tar->compress( Phar::GZ ) ;          // → $tarPath . '.gz'
        unset( $tar ) ;

        @unlink( $tarPath ) ;
        rename( $tarPath . '.gz' , $path ) ;
    }

    public function testListReportsEmptyDumpDirectory() :void
    {
        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $code = $host->restore( $this->input( [ '--' . ArangoCommandOption::LIST => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'no files' , $output->fetch() ) ;
    }

    public function testListShowsTheArchivesInTheDumpDirectory() :void
    {
        touch( $this->dumpDir . DIRECTORY_SEPARATOR . '2025-07-05T18:14:22-mydb.tar.gz' ) ;
        touch( $this->dumpDir . DIRECTORY_SEPARATOR . '2025-07-06T09:00:00-mydb.tar.gz' ) ;

        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $code = $host->restore( $this->input( [ '--' . ArangoCommandOption::LIST => true ] ) , $output ) ;

        $text = $output->fetch() ;
        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( '2025-07-05T18:14:22-mydb.tar.gz' , $text ) ;
        $this->assertStringContainsString( '2025-07-06T09:00:00-mydb.tar.gz' , $text ) ;
    }

    public function testRestoreFromExplicitFileUntarsAndInvokesRestore() :void
    {
        $archive = $this->dumpDir . DIRECTORY_SEPARATOR . 'backup.tar.gz' ;
        $this->makeArchive( $archive ) ;

        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $code = $host->restore( $this->input( [ '--' . ArangoCommandOption::FILE => $archive ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->restoreCalled ) ;
        $this->assertSame( 'mydb' , $host->capturedRestoreOptions[ ArangoRestoreOption::SERVER_DATABASE ] ) ;
        $this->assertArrayHasKey( ArangoRestoreOption::INPUT_DIRECTORY , $host->capturedRestoreOptions ) ;
        // the archive is consumed (unlinked) after extraction
        $this->assertFileDoesNotExist( $archive ) ;
    }

    public function testRestoreWithCollectionSubsetForwardsTheCollectionOption() :void
    {
        $archive = $this->dumpDir . DIRECTORY_SEPARATOR . 'backup.tar.gz' ;
        $this->makeArchive( $archive ) ;

        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $code = $host->restore( $this->input
        ([
            '--' . ArangoCommandOption::FILE       => $archive ,
            '--' . ArangoCommandOption::COLLECTION => [ 'users' , 'orders' ] ,
        ]) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertStringContainsString( 'Collections :' , $output->fetch() ) ;
        $this->assertArrayHasKey( ArangoRestoreOption::COLLECTION , $host->capturedRestoreOptions ) ;
    }

    public function testRestorePicksTheMostRecentArchiveWithLast() :void
    {
        $this->makeArchive( $this->dumpDir . DIRECTORY_SEPARATOR . '2025-07-05T18:14:22-mydb.tar.gz' ) ;
        $this->makeArchive( $this->dumpDir . DIRECTORY_SEPARATOR . '2025-07-06T09:00:00-mydb.tar.gz' ) ;

        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $code = $host->restore( $this->input( [ '--' . ArangoCommandOption::LAST => true ] ) , $output ) ;

        $this->assertSame( ExitCode::SUCCESS , $code ) ;
        $this->assertTrue( $host->restoreCalled ) ;
    }

    public function testRestoreThrowsWhenNoMatchingFileIsFound() :void
    {
        $host   = new ArangoRestoreActionHost( $this->dumpDir ) ;
        $output = new BufferedOutput() ;

        $this->expectException( FileException::class ) ;
        $host->restore( $this->input( [] ) , $output ) ;
    }
}
