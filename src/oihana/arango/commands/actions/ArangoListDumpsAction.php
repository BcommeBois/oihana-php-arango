<?php

namespace oihana\arango\commands\actions;

use oihana\commands\enums\ExitCode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use oihana\arango\commands\enums\ArchivePattern;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\DirectoryTrait;
use oihana\commands\traits\IOTrait;
use oihana\files\enums\FindFilesOption;
use oihana\files\exceptions\DirectoryException;

use function oihana\files\findFiles;
use function oihana\files\getDirectory;

// List all dump files
// $ composer arango:list
// $ php bin/console.php command:arangodb dump --list

/**
 * List all dumps files in the dump directory.
 */
trait ArangoListDumpsAction
{
    use DirectoryTrait ,
        IOTrait ;

    /**
     * The pattern to find the archive files.
     */
    public const string ARCHIVE_REGEXP = ArchivePattern::REGEXP ;

    /**
     * List the dump files of the database.
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws DirectoryException
     */
    protected function listDumps( InputInterface $input, OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        $io->section( 'List the arangodb dumps' ) ;

        $inputDirectory = getDirectory( $input->getOption( ArangoCommandOption::DIRECTORY ) ?? $this->directory ) ;

        $files = findFiles
        (
            $inputDirectory ,
            [
                FindFilesOption::PATTERN => self::ARCHIVE_REGEXP ,
                FindFilesOption::FILTER  => fn( $file ) => $file->getFilename()
            ]
        ) ;

        if( empty( $files ) )
        {
            $io->text ( 'There are no files in the dump directory.' ) ;
        }
        else
        {
            sort( $files );
            foreach( $files as $file )
            {
                $io->text ( '→ ' . $file ) ;
            }
        }

        $io->newLine() ;

        return ExitCode::SUCCESS ;
    }
}