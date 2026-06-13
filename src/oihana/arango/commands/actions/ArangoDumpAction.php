<?php

namespace oihana\arango\commands\actions;

use InvalidArgumentException;
use oihana\commands\enums\ExitCode;
use RuntimeException;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\enums\ArangoCommandParam;
use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoCollectionsTrait;
use oihana\arango\commands\traits\ArangoDumpTrait;
use oihana\arango\commands\traits\ArangoMaskingTrait;
use oihana\arango\commands\traits\ArangoOptionsTrait;
use oihana\arango\commands\traits\ArangoProfileTrait;
use oihana\arango\commands\traits\DirectoryTrait;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\db\enums\ArangoConfig;

use oihana\commands\enums\CommandArg;
use oihana\commands\exceptions\MissingPassphraseException;
use oihana\commands\traits\EncryptTrait;

use oihana\enums\Char;

use oihana\files\enums\CompressionType;
use oihana\files\enums\FileExtension;
use oihana\files\exceptions\DirectoryException;
use oihana\files\exceptions\FileException;
use oihana\files\exceptions\UnsupportedCompressionException;
use oihana\files\openssl\OpenSSLFileEncryption;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

use function oihana\files\archive\tar\tarDirectory;
use function oihana\files\assertFile;
use function oihana\files\deleteDirectory;
use function oihana\files\makeDirectory;
use function oihana\files\makeTemporaryDirectory;
use function oihana\files\makeTimestampedDirectory;

// Basic command :
// $ composer arango:dump
// $ php bin/console.php command:arangodb dump

// List all dump files
// $ composer arango:list
// $ php bin/console.php command:arangodb dump --list

// Anonymize at dump time (dump-only). A native arangodump maskings file:
// $ php bin/console.php command:arangodb dump --maskings /etc/oihana/maskings.json
// Or the convenient form via a profile / [arango.dump.masking] (compiled to a temp file):
// $ php bin/console.php command:arangodb dump --profile test-local

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoDumpAction
{
    use ArangoClientTrait ,
        ArangoCollectionsTrait ,
        ArangoDumpTrait ,
        ArangoListDumpsAction ,
        ArangoMaskingTrait ,
        ArangoOptionsTrait ,
        ArangoProfileTrait ,
        DirectoryTrait ,
        EncryptTrait ;

    /**
     * The system collections added by a `--complete` backup (the custom
     * analyzers and the named graph definitions).
     */
    private const array COMPLETE_SYSTEM_COLLECTIONS = [ '_analyzers' , '_graphs' ] ;

    /**
     * The compression of the dump file.
     * @var string|null
     */
    public ?string $compression = CompressionType::GZIP ;

    /**
     * Dump the ArangoDB database.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws DirectoryException
     * @throws FileException
     * @throws UnsupportedCompressionException
     * @throws MissingPassphraseException
     */
    public function dump( InputInterface $input, OutputInterface $output ) :int
    {
        if( $input->getOption( ArangoCommandOption::LIST ) )
        {
            return $this->listDumps( $input , $output ) ;
        }

        $io = $this->getIO( $input , $output ) ;

        // 00 - Initialize the process

        $action = $input->getArgument( CommandArg::ACTION ) ?? Char::EMPTY ;

        // --- Selection: --complete, a profile, or the --collection / --ignore-collection flags ---

        $complete = (bool) $input->getOption( ArangoCommandOption::COMPLETE )
                 || (bool) ( $this->dumpConfig[ ArangoCommandOption::COMPLETE ] ?? false ) ;

        $profileName = $input->getOption( ArangoCommandOption::PROFILE ) ;
        $profile     = $this->resolveProfile( $profileName ) ;

        $collection = $this->normalizeCollections( (array) $input->getOption( ArangoCommandOption::COLLECTION        ) ) ;
        $ignore     = $this->normalizeCollections( (array) $input->getOption( ArangoCommandOption::IGNORE_COLLECTION ) ) ;
        $label      = $this->sanitizeLabel( $input->getOption( ArangoCommandOption::LABEL ) ) ;

        if( $complete && ( $collection !== [] || $ignore !== [] || $profile !== null ) )
        {
            throw new InvalidArgumentException( '--complete backs up the whole database (plus _analyzers and _graphs) and cannot be combined with --collection / --ignore-collection / --profile.' ) ;
        }

        if( $profile !== null && ( $collection !== [] || $ignore !== [] ) )
        {
            throw new InvalidArgumentException( '--profile cannot be combined with --collection / --ignore-collection (choose one selection mode).' ) ;
        }

        $this->assertCollectionTargeting( $collection , $ignore ) ;

        // Connection — the CLI wins over the profile **source** connection
        // (dump only), which wins over the [arango] configuration.
        $connection = $profile !== null ? $this->profileConnection( $profile ) : [] ;
        $database   = $input->getOption( ArangoConfig::DATABASE ) ?? $connection[ ArangoConfig::DATABASE ] ?? $this->getDatabase() ;
        $endpoint   = $input->getOption( ArangoConfig::ENDPOINT ) ?? $connection[ ArangoConfig::ENDPOINT ] ?? $this->getEndpoint() ;
        $password   = $input->getOption( ArangoConfig::PASSWORD ) ?? $connection[ ArangoConfig::PASSWORD ] ?? $this->getPassword() ;
        $username   = $input->getOption( ArangoConfig::USER     ) ?? $connection[ ArangoConfig::USER     ] ?? $this->getUsername() ;

        $partial = $collection !== [] || $ignore !== [] || $profile !== null ;

        $io->section( sprintf( "Dump the '%s' database" , $database ) ) ;

        // Resolve the effective list of collections to pass to arangodump.
        //
        // - --profile : the positive selection minus exclude; an exclude-only
        //   profile means "everything minus exclude" (needs the HTTP API).
        // - --collection : validated best-effort against the live database
        //   (skipped with a warning when the HTTP API is unreachable).
        // - --ignore-collection : arangodump has no exclusion option, so the
        //   complement is computed client-side and passed as --collection.

        $targetCollections = $collection ;

        if( $complete )
        {
            $io->text( 'Complete backup (+ system: ' . implode( ', ' , self::COMPLETE_SYSTEM_COLLECTIONS ) . ')' ) ;

            $targetCollections = $this->dumpCompleteCollections( $endpoint , $username , $password , $database ) ;

            $io->text( sprintf( '→ %d collection(s) will be dumped.' , count( $targetCollections ) ) ) ;
        }
        elseif( $profile !== null )
        {
            $io->text( sprintf( 'Profile : %s' , $profileName ) ) ;

            $available         = $this->profilePositive( $profile ) === []
                               ? $this->dumpAvailableCollections( $endpoint , $username , $password , $database )
                               : [] ;
            $targetCollections = $this->profileSelection( $profile , $available ) ;

            if( $targetCollections === [] )
            {
                throw new RuntimeException( 'Nothing to dump: the profile selects no collection.' ) ;
            }

            $io->text( sprintf( '→ %d collection(s) will be dumped.' , count( $targetCollections ) ) ) ;
        }
        elseif( $ignore !== [] )
        {
            $io->text( 'Ignored collections : ' . implode( ', ' , $ignore ) ) ;

            $available = $this->dumpAvailableCollections( $endpoint , $username , $password , $database ) ;

            $missing = $this->missingCollections( $ignore , $available ) ;
            if( $missing !== [] )
            {
                throw new RuntimeException
                (
                    sprintf
                    (
                        'Unknown collection(s): %s. Available collections: %s.' ,
                        implode( ', ' , $missing ) ,
                        implode( ', ' , $available )
                    )
                ) ;
            }

            $targetCollections = $this->excludeCollections( $available , $ignore ) ;
            if( $targetCollections === [] )
            {
                throw new RuntimeException( 'Nothing to dump: every collection is excluded by --ignore-collection.' ) ;
            }

            $io->text( sprintf( '→ %d collection(s) will be dumped.' , count( $targetCollections ) ) ) ;
        }
        elseif( $collection !== [] )
        {
            $io->text( 'Collections : ' . implode( ', ' , $collection ) ) ;

            $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
            if( $db === null )
            {
                $io->warning( 'Collection validation skipped — no ArangoDB HTTP client available.' ) ;
            }
            else
            {
                try
                {
                    $available = array_map( fn( $c ) => $c->getName() , $db->collections( true ) ) ;
                    $missing   = $this->missingCollections( $collection , $available ) ;

                    if( $missing !== [] )
                    {
                        throw new RuntimeException
                        (
                            sprintf
                            (
                                'Unknown collection(s): %s. Available collections: %s.' ,
                                implode( ', ' , $missing ) ,
                                implode( ', ' , $available )
                            )
                        ) ;
                    }
                }
                catch( ArangoException $exception )
                {
                    $io->warning( 'Collection validation skipped — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
                }
            }
        }

        // --- Masking (dump-only): a convenient table (profile overrides the
        //     [arango.dump.masking] default) compiled to a temp file, or a
        //     native file (--maskings, highest). The [arango.dump] maskings
        //     native default, if any, flows through resolveDumpOptions. ---

        $maskingTable = $this->dumpConfig[ ArangoCommandParam::MASKING ] ?? null ;
        if( is_array( $profile ) && isset( $profile[ ArangoCommandParam::MASKING ] ) )
        {
            $maskingTable = $profile[ ArangoCommandParam::MASKING ] ;
        }

        $maskingFileCli = $input->getOption( ArangoCommandOption::MASKINGS ) ;

        // --dry-run : report the resolved plan, run nothing.
        if( $input->getOption( ArangoCommandOption::DRY_RUN ) )
        {
            $io->text( sprintf( 'Source  : %s @ %s' , $database , $endpoint ) ) ;
            $io->text( 'Collections : ' . ( $targetCollections === [] ? 'all' : implode( ', ' , $targetCollections ) ) ) ;

            if( is_string( $maskingFileCli ) && $maskingFileCli !== '' )
            {
                $io->text( 'Masking : native file ' . $maskingFileCli ) ;
            }
            elseif( is_array( $maskingTable ) && $maskingTable !== [] )
            {
                $io->text( sprintf( 'Masking : %d entry(ies) compiled from the convenient table' , count( $maskingTable ) ) ) ;
            }

            $io->success( 'Dry run — nothing was dumped.' ) ;
            return ExitCode::SUCCESS ;
        }

        // Resolve the effective maskings file (native CLI wins over the compiled table).
        $maskingsFile = null ;
        if( is_string( $maskingFileCli ) && $maskingFileCli !== '' )
        {
            assertFile( $maskingFileCli ) ;
            $maskingsFile = $maskingFileCli ;
        }
        elseif( is_array( $maskingTable ) && $maskingTable !== [] )
        {
            $maskingsFile = $this->materializeMaskings( $maskingTable , [ $this->id , $this->getName() , $action , 'maskings' , Uuid::v4() ] ) ;
            $io->text( sprintf( 'Masking : %d entry(ies) → %s' , count( $maskingTable ) , basename( $maskingsFile ) ) ) ;
        }

        $outputDirectory = makeDirectory( $input->getOption( ArangoCommandOption::DIRECTORY ) ?? $this->directory ) ;
        $tmpDirectory    = makeTemporaryDirectory( [ $this->id , $this->getName() , $action , Uuid::v4() ] ) ;

        // 01. Creates the timestamped directory YYYY-MM-DDThh:mm:ss-{database}[-partial][-{label}]

        $timestampedDirectory = makeTimestampedDirectory
        (
            date     : $input->getOption( ArangoCommandOption::DATE ) ,
            basePath : $tmpDirectory ,
            suffix   : static::getArchiveNameSuffix( $database , $partial , $label ) ,
            timezone : $this->timezone   ?? self::DEFAULT_TIMEZONE ,
            format   : $this->dateFormat ?? self::DEFAULT_DATE_FORMAT ,
        ) ;

        // $io->text( '🗂 timestamped directory :: ' . $timestampedDirectory );

        // 02. Dump the ArangoDB database

        $explicit =
        [
            ArangoDumpOption::SERVER_DATABASE  => $database ,
            ArangoDumpOption::SERVER_ENDPOINT  => $endpoint ,
            ArangoDumpOption::SERVER_PASSWORD  => $password ,
            ArangoDumpOption::SERVER_USERNAME  => $username ,
            ArangoDumpOption::OUTPUT_DIRECTORY => $timestampedDirectory
        ] ;

        if( $targetCollections !== [] )
        {
            $explicit[ ArangoDumpOption::COLLECTION ] = $targetCollections ;
        }

        // A complete backup lists the system collections explicitly, so
        // arangodump must be allowed to dump them.
        if( $complete )
        {
            $explicit[ ArangoDumpOption::INCLUDE_SYSTEM_COLLECTIONS ] = true ;
        }

        // Anonymize at dump time (native file, or the compiled convenient table).
        if( $maskingsFile !== null )
        {
            $explicit[ ArangoDumpOption::MASKINGS ] = $maskingsFile ;
        }

        // Layer the [arango.dump] config defaults under the resolved
        // connection/output, then let the curated CLI flags override.
        $options = $this->resolveDumpOptions( $explicit , $input ) ;

        $this->arangoDump( options : $options , silent : $output->isQuiet() ) ;

        $io->newLine() ;

        // 03. Creates the archive file (tar.gz)

        $fromFile = tarDirectory( $timestampedDirectory , $this->compression ) ;

        // $io->text( '📄 tar file  :: ' . $fromFile );

        // 04. Encrypted the archive file (tar.gz.enc) (optional)

        if( $this->shouldEncrypt( $input ) )
        {
            $passphrase = $this->getPassphrase( $input , $output ) ;

            $encryptor = new OpenSSLFileEncryption( $passphrase );

            $encryptor->encrypt( $fromFile ) ;

            $fromFile = $fromFile . FileExtension::ENCRYPTED  ;

            deleteDirectory( $timestampedDirectory ) ;
        }

        // 05 - Move the archive file in the output directory

        $toFile = $outputDirectory. DIRECTORY_SEPARATOR . basename( $fromFile ) ;
        if( !rename( $fromFile , $toFile ) )
        {
            // @codeCoverageIgnoreStart
            throw new RuntimeException( "Failed to move the archive file in the final directory." ) ;
            // @codeCoverageIgnoreEnd
        }

        // 06 - Finish the process

        $io->newLine() ;
        $io->success( 'Database dump completed successfully.' ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Returns the user (non-system) collection names of the live database, or
     * throws when the HTTP API is unavailable — used by the exclusion paths
     * (`--ignore-collection` and exclude-only profiles) that need the universe.
     *
     * @param string $endpoint
     * @param string $username
     * @param string $password
     * @param string $database
     * @return array<int,string>
     */
    private function dumpAvailableCollections( string $endpoint , string $username , string $password , string $database ) :array
    {
        $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
        if( $db === null )
        {
            throw new RuntimeException( 'This selection requires the ArangoDB HTTP API, but no client is available (check endpoint/database).' ) ;
        }

        try
        {
            return array_map( fn( $c ) => $c->getName() , $db->collections( false ) ) ;
        }
        catch( ArangoException $exception )
        {
            throw new RuntimeException( 'This selection requires the ArangoDB HTTP API, which is unreachable: ' . $exception->getMessage() , 0 , $exception ) ;
        }
    }

    /**
     * Returns the collection list of a `--complete` backup: every user
     * collection plus the {@see COMPLETE_SYSTEM_COLLECTIONS} that exist on the
     * server. Throws when the HTTP API is unavailable.
     *
     * @param string $endpoint
     * @param string $username
     * @param string $password
     * @param string $database
     * @return array<int,string>
     */
    private function dumpCompleteCollections( string $endpoint , string $username , string $password , string $database ) :array
    {
        $db = $this->buildDatabase( $endpoint , $username , $password , $database ) ;
        if( $db === null )
        {
            throw new RuntimeException( '--complete requires the ArangoDB HTTP API, but no client is available (check endpoint/database).' ) ;
        }

        try
        {
            $all = array_map( fn( $c ) => $c->getName() , $db->collections( true ) ) ;
        }
        catch( ArangoException $exception )
        {
            throw new RuntimeException( '--complete requires the ArangoDB HTTP API, which is unreachable: ' . $exception->getMessage() , 0 , $exception ) ;
        }

        return array_values( array_filter
        (
            $all ,
            fn( string $name ) => !str_starts_with( $name , '_' ) || in_array( $name , self::COMPLETE_SYSTEM_COLLECTIONS , true ) ,
        ) ) ;
    }
}