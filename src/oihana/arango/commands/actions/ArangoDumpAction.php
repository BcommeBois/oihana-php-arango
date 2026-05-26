<?php

namespace oihana\arango\commands\actions;

use oihana\commands\enums\ExitCode;
use RuntimeException;

use oihana\arango\commands\options\ArangoDumpOption;
use oihana\arango\commands\traits\ArangoDumpTrait;
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

/**
 * The command to manage an ArangoDB database.
 */
trait ArangoDumpAction
{
    use ArangoDumpTrait ,
        ArangoListDumpsAction ,
        EncryptTrait ;

    /**
     * The dump/restore directory.
     * @var ?string
     */
    public ?string $directory ;

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

        $action   = $input->getArgument(CommandArg::ACTION ) ?? Char::EMPTY ;
        $database = $input->getOption( ArangoConfig::DATABASE ) ?? $this->getDatabase() ;
        $endpoint = $input->getOption( ArangoConfig::ENDPOINT ) ??$this->getEndpoint() ;
        $password = $input->getOption( ArangoConfig::PASSWORD ) ??$this->getPassword() ;
        $username = $input->getOption( ArangoConfig::USER     ) ??$this->getUsername() ;

        $io->section( sprintf( "Dump the '%s' database" , $database ) ) ;

        $outputDirectory = makeDirectory( $input->getOption( ArangoCommandOption::DIRECTORY ) ?? $this->directory ) ;
        $tmpDirectory    = makeTemporaryDirectory( [ $this->id , $this->getName() , $action , Uuid::v4() ] ) ;

        // 01. Creates the timestamped directory YYYY-MM-DDThh:mm:ss-{database}

        $timestampedDirectory = makeTimestampedDirectory
        (
            date     : $input->getOption( ArangoCommandOption::DATE ) ,
            basePath : $tmpDirectory ,
            suffix   : Char::DASH . $database ,
            timezone : $this->timezone   ?? self::DEFAULT_TIMEZONE ,
            format   : $this->dateFormat ?? self::DEFAULT_DATE_FORMAT ,
        ) ;

        // $io->text( '🗂 timestamped directory :: ' . $timestampedDirectory );

        // 02. Dump the ArangoDB database

        $this->arangoDump
        (
            options :
            [
                ArangoDumpOption::SERVER_DATABASE  => $database ,
                ArangoDumpOption::SERVER_ENDPOINT  => $endpoint ,
                ArangoDumpOption::SERVER_PASSWORD  => $password ,
                ArangoDumpOption::SERVER_USERNAME  => $username ,
                ArangoDumpOption::OUTPUT_DIRECTORY => $timestampedDirectory
            ]
            , silent : $output->isQuiet()
        ) ;

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
            throw new RuntimeException( "Failed to move the archive file in the final directory." ) ;
        }

        // 06 - Finish the process

        $io->newLine() ;
        $io->success( 'Database dump completed successfully.' ) ;

        return ExitCode::SUCCESS ;
    }
}