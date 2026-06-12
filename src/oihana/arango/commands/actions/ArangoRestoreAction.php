<?php

namespace oihana\arango\commands\actions;

use InvalidArgumentException;
use RuntimeException;

use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\traits\ArangoCollectionsTrait;
use oihana\arango\commands\traits\ArangoOptionsTrait;
use oihana\arango\commands\traits\ArangoProfileTrait;
use oihana\arango\commands\traits\ArangoRestoreTrait;
use oihana\arango\commands\traits\DirectoryTrait;

use oihana\commands\enums\CommandArg;
use oihana\commands\enums\ExitCode;
use oihana\commands\exceptions\ExitException;
use oihana\commands\exceptions\MissingPassphraseException;
use oihana\commands\traits\EncryptTrait;

use oihana\enums\Char;

use oihana\files\enums\FileExtension;
use oihana\files\enums\FindFileOption;
use oihana\files\exceptions\DirectoryException;
use oihana\files\exceptions\FileException;
use oihana\files\openssl\OpenSSLFileEncryption;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Uid\Uuid;

use function oihana\files\archive\tar\untar;
use function oihana\files\assertFile;
use function oihana\files\findFiles;
use function oihana\files\getBaseFileName;
use function oihana\files\getDirectory;
use function oihana\files\getTimestampedFile;
use function oihana\files\makeTemporaryDirectory;

// Interactive selection across the dump folder
// $ composer arango:restore
// $ php bin/console.php command:arangodb restore

// List the dumps instead of restoring
// $ composer arango:list
// $ php bin/console.php command:arangodb restore --list

// Inject the passphrase for an encrypted archive
// $ composer arango:restore -- -p mysecretpassword
// $ composer arango:restore -- --passphrase mysecretpassword
// $ composer arango:restore -- --encrypt --passphrase mysecretpassword

// Pick the most recent archive in the dump folder
// $ composer arango:restore -- -la
// $ composer arango:restore -- --last

// Pick by date
// $ composer arango:restore -- -d 2025-07-05T18:14:22
// $ composer arango:restore -- --date 2025-07-05T18:14:22

// Pick by explicit file
// $ composer arango:restore -- -f /var/data/arango/dumps/2025-07-05T18:14:22-mydb.tar.gz.enc
// $ composer arango:restore -- --file /var/data/arango/dumps/2025-07-05T18:14:22-mydb.tar.gz.enc

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoRestoreAction
{
    use ArangoCollectionsTrait ,
        ArangoListDumpsAction ,
        ArangoOptionsTrait ,
        ArangoProfileTrait ,
        ArangoRestoreTrait ,
        DirectoryTrait ,
        EncryptTrait ;

    /**
     * The regexp to find the archive file.
     */
    public const string ARCHIVE_REGEXP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-.+\.tar(\.gz(\.enc)?)?$/' ;

    /**
     * The exit message.
     * @var string
     */
    public string $exit = '⏻ Exit the command.' ;

    /**
     * Restore the ArangoDB database.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws DirectoryException
     * @throws ExitException
     * @throws FileException
     * @throws MissingPassphraseException
     */
    public function restore( InputInterface $input, OutputInterface $output ) :int
    {
        if( $input->getOption( ArangoCommandOption::LIST ) )
        {
            return $this->listDumps( $input , $output ) ;
        }

        $io = $this->getIO( $input , $output ) ;

        // 01 - Initialize the process

        $inputFile      = null ;
        $inputDirectory = getDirectory($input->getOption( ArangoCommandOption::DIRECTORY ) ?? $this->directory ) ;
        $shouldEncrypt  = $this->shouldEncrypt( $input ) ;

        // 02 - Initialize the argument and options.

        $action = $input->getArgument(CommandArg::ACTION ) ?? Char::EMPTY ;

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoCommandOption::ENDPOINT ) ?? $this->getEndpoint() ;
        $password = $input->getOption( ArangoCommandOption::PASSWORD ) ?? $this->getPassword() ;
        $username = $input->getOption( ArangoCommandOption::USER     ) ?? $this->getUsername() ;

        $collection = $this->normalizeCollections( (array) $input->getOption( ArangoCommandOption::COLLECTION ) ) ;
        $label      = $this->sanitizeLabel( $input->getOption( ArangoCommandOption::LABEL ) ) ;

        // A profile borrows only its selection — never its (source) connection:
        // the restore always writes to the local target resolved above.
        $profileName = $input->getOption( ArangoCommandOption::PROFILE ) ;
        $profile     = $this->resolveProfile( $profileName ) ;

        if( $profile !== null && $collection !== [] )
        {
            throw new InvalidArgumentException( '--profile cannot be combined with --collection (choose one selection mode).' ) ;
        }

        $partial = $collection !== [] || $profile !== null ;

        $io->section( sprintf( "Restore the '%s' database" , $database ) ) ;

        if( $profile !== null )
        {
            $io->text( sprintf( 'Profile : %s' , $profileName ) ) ;
        }
        elseif( $collection !== [] )
        {
            $io->text( 'Collections : ' . implode( ', ' , $collection ) ) ;
        }

        // 03 - Find the database dump file with a specific file, date
        // or in the list of all files in the input folder (or the last).

        $file = $input->getOption( ArangoCommandOption::FILE ) ;
        if( isset( $file ) && $file != Char::EMPTY )
        {
            assertFile( $file ) ;
            $inputFile = $file ;
        }

        if( !isset( $inputFile ) )
        {
            $date = $input->getOption( ArangoCommandOption::DATE ) ;
            if( isset( $date ) )
            {
                $inputFile = getTimestampedFile
                (
                    date       : $date ,
                    basePath   : $inputDirectory ,
                    suffix     : static::getArchiveFileSuffix( $database , $shouldEncrypt , $partial , $label ) ,
                    timezone   : $this->timezone   ?? self::DEFAULT_TIMEZONE ,
                    format     : $this->dateFormat ?? self::DEFAULT_DATE_FORMAT ,
                    // assertable : true -> default
                ) ;
            }
        }

        if( !isset( $inputFile ) )
        {
            $files = findFiles
            (
                $inputDirectory ,
                [
                    FindFileOption::FILTER  => fn( $file ) => $file->getFilename() ,
                    FindFileOption::PATTERN => self::ARCHIVE_REGEXP ,
                ]
            ) ;

            if ( empty( $files ) )
            {
                throw new FileException( 'No matching file found.' ) ;
            }

            sort( $files );

            $last = $input->getOption( ArangoCommandOption::LAST ) ;
            if( $last )
            {
                $inputFile = $inputDirectory . DIRECTORY_SEPARATOR . end( $files ) ;
            }
            elseif ( $input->isInteractive() )
            {
                $files[] = $this->exit ;
                $helper  = $this->getQuestionHelper() ;

                $question = new ChoiceQuestion
                (
                    '📂 Please select a file below :',
                    $files,
                    0 // Index par défaut
                );

                $question->setErrorMessage('⚠️ The file %s is invalid.');

                $file = $helper->ask( $input, $output , $question ) ;
                if( $file == $this->exit )
                {
                    throw new ExitException() ;
                }

                $io->newLine();

                $inputFile = $inputDirectory . DIRECTORY_SEPARATOR . $file ;

            }

            assertFile( $inputFile ) ;
        }

        $io->text( sprintf( 'Restore the database with the file: %s' , $inputFile ) ) ;

        // --dry-run : report the resolved plan, restore nothing (no untar).
        if( $input->getOption( ArangoCommandOption::DRY_RUN ) )
        {
            $io->text( sprintf( 'Target  : %s @ %s (local)' , $database , $endpoint ) ) ;
            $io->text( 'Collections : ' . $this->restoreSelectionLabel( $profile , $collection ) ) ;
            $io->success( 'Dry run — nothing was restored.' ) ;
            return ExitCode::SUCCESS ;
        }

        $inputDirectory = makeTemporaryDirectory( [ $this->id , $this->getName() , $action , Uuid::v4() ] ) ;

        $io->text( 'The temporary input directory : ' . json_encode( $inputDirectory , JSON_UNESCAPED_SLASHES) ) ;

        // 04 - Decrypt the file if is encrypted.

        if( $shouldEncrypt )
        {
            $passphrase = $this->getPassphrase( $input , $output ) ;

            $decryptedFile = implode
            (
                DIRECTORY_SEPARATOR ,
                [ $inputDirectory , basename( str_replace( FileExtension::ENCRYPTED , Char::EMPTY , $inputFile ) ) ]
            ) ;

            $io->text( sprintf( 'The expected decrypted file: %s' , $decryptedFile ) ) ;

            $inputFile = new OpenSSLFileEncryption( $passphrase )->decrypt( $inputFile , $decryptedFile ) ;

            $io->text( sprintf( 'The decrypted file : %s' , $inputFile ) ) ;
        }

        // 05 - Unarchive the file

        $inputDirectory = $inputDirectory . DIRECTORY_SEPARATOR . getBaseFileName( $inputFile ) ;

        untar( $inputFile , $inputDirectory ) ;

        unlink( $inputFile ) ;

        // 06 - Restore the database

        // Resolve the effective collection filter. A profile borrows the
        // selection only: a positive list (minus exclude), or — for an
        // exclude-only profile — every collection in the archive minus exclude.
        if( $profile !== null )
        {
            $selection = $this->profilePositive( $profile ) !== []
                       ? $this->profileSelection( $profile )
                       : $this->profileSelection( $profile , $this->archiveCollections( $inputDirectory ) ) ;

            if( $selection === [] )
            {
                throw new RuntimeException( 'Nothing to restore: the profile selects no collection.' ) ;
            }
        }
        else
        {
            $selection = $collection ;
        }

        $explicit =
        [
            ArangoRestoreOption::SERVER_DATABASE   => $database ,
            ArangoRestoreOption::SERVER_ENDPOINT   => $endpoint ,
            ArangoRestoreOption::SERVER_PASSWORD   => $password ,
            ArangoRestoreOption::SERVER_USERNAME   => $username ,
            ArangoRestoreOption::INPUT_DIRECTORY   => $inputDirectory ,
            ArangoRestoreOption::CREATE_COLLECTION => true ,
            ArangoRestoreOption::CREATE_DATABASE   => true
        ] ;

        if( $selection !== [] )
        {
            $explicit[ ArangoRestoreOption::COLLECTION ] = $selection ;
        }

        // Layer the [arango.restore] config defaults under the resolved
        // connection/input, then let the curated CLI flags override.
        $options = $this->resolveRestoreOptions( $explicit , $input ) ;

        $this->arangoRestore( $options , $output->isQuiet() );

        // deleteDirectory( $inputDirectory ) ;

        // 07 - Finish the process

        $io->newLine() ;
        $io->success( 'The database is restored successfully.' ) ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Builds the archive file name suffix used to locate a dump by date.
     *
     * The dump action always produces a gzip-compressed tarball
     * (`{date}-{database}[-partial][-{label}].tar.gz`), optionally
     * AES-encrypted (`….tar.gz.enc`). This helper mirrors that naming so a
     * targeted dump can be located by `--date` (the caller must pass the
     * same `--collection`/`--ignore-collection` and `--label` it dumped
     * with). The name part is delegated to {@see getArchiveNameSuffix()}.
     *
     * @param string      $database The database name embedded in the suffix.
     * @param bool        $encrypt  Whether the archive is encrypted.
     * @param bool        $partial  Whether the dump targets a subset of collections.
     * @param string|null $label    Optional label appended to the name.
     * @return string e.g. `-mydb.tar.gz`, `-mydb-partial.tar.gz` or `-mydb-partial-pre-migration.tar.gz.enc`.
     */
    protected static function getArchiveFileSuffix( string $database , bool $encrypt = false , bool $partial = false , ?string $label = null ) :string
    {
        return static::getArchiveNameSuffix( $database , $partial , $label )
             . ( $encrypt ? FileExtension::TAR_GZ_ENCRYPTED : FileExtension::TAR_GZ ) ;
    }

    /**
     * The collection names declared by the `*.structure.json` files of an
     * untarred dump — the universe used by an exclude-only profile on restore.
     *
     * @param string $directory The untarred dump directory.
     * @return array<int,string>
     */
    private function archiveCollections( string $directory ) :array
    {
        $names = [] ;
        foreach ( glob( $directory . DIRECTORY_SEPARATOR . '*.structure.json' ) ?: [] as $path )
        {
            $data = json_decode( (string) file_get_contents( $path ) , true ) ;
            $name = is_array( $data ) ? ( $data[ 'parameters' ][ 'name' ] ?? null ) : null ;
            if( is_string( $name ) && $name !== '' )
            {
                $names[] = $name ;
            }
        }
        return array_values( array_unique( $names ) ) ;
    }

    /**
     * A human-readable description of the restore selection, for `--dry-run`
     * (the exact exclude-only list is only known once the archive is untarred).
     *
     * @param array|null $profile
     * @param array      $collection The CLI `--collection` selection.
     * @return string
     */
    private function restoreSelectionLabel( ?array $profile , array $collection ) :string
    {
        if( $collection !== [] )
        {
            return implode( ', ' , $collection ) ;
        }

        if( $profile !== null )
        {
            if( $this->profilePositive( $profile ) !== [] )
            {
                return implode( ', ' , $this->profileSelection( $profile ) ) ;
            }

            $exclude = $this->profileExclude( $profile ) ;
            return 'all in archive' . ( $exclude === [] ? '' : ' except: ' . implode( ', ' , $exclude ) ) ;
        }

        return 'all' ;
    }
}