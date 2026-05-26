<?php

namespace oihana\arango\commands\actions;

use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\options\ArangoRestoreOption;
use oihana\arango\commands\traits\ArangoRestoreTrait;

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
    use ArangoListDumpsAction ,
        ArangoRestoreTrait ,
        EncryptTrait ;

    /**
     * The regexp to find the archive file.
     */
    public const string ARCHIVE_REGEXP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-.+\.tar(\.gz(\.enc)?)?$/' ;

    /**
     * The dump/restore directory.
     * @var ?string
     */
    public ?string $directory ;

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

        $io->section( sprintf( "Restore the '%s' database" , $database ) ) ;

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
                    suffix     : Char::DASH . $database . $shouldEncrypt ? FileExtension::TAR_GZ_ENCRYPTED : FileExtension::TAR ,
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
                $inputFile = end( $files ) ;
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

        $this->arangoRestore
        (
            [
                ArangoRestoreOption::SERVER_DATABASE   => $database ,
                ArangoRestoreOption::SERVER_ENDPOINT   => $endpoint ,
                ArangoRestoreOption::SERVER_PASSWORD   => $password ,
                ArangoRestoreOption::SERVER_USERNAME   => $username ,
                ArangoRestoreOption::INPUT_DIRECTORY   => $inputDirectory ,
                ArangoRestoreOption::CREATE_COLLECTION => true ,
                ArangoRestoreOption::CREATE_DATABASE   => true
            ]
            , $output->isQuiet()
        );

        // deleteDirectory( $inputDirectory ) ;

        // 07 - Finish the process

        $io->newLine() ;
        $io->success( 'The database is restored successfully.' ) ;

        return ExitCode::SUCCESS ;
    }
}