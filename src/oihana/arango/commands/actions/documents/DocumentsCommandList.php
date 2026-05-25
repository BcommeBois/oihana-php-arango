<?php

namespace oihana\arango\commands\actions\documents;

use oihana\arango\clients\exceptions\ArangoException;
use oihana\commands\enums\ExitCode;
use oihana\exceptions\BindException;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use ReflectionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides the implementation for a 'list' command action.
 *
 * This trait should be used in a Symfony Console command that needs to
 * retrieve and display all documents from its associated model.
 *
 * ## Example
 *
 * **Basic usage**
 * ```shell
 * bin/console command:places list
 *
 * Command:places
 * ==============
 *
 * Listing documents in the collection
 * -----------------------------------
 *
 * Documents Found: 4
 *
 * ✅  Done in 5 ms
 * ----------------
 *
 * Thank you and see you soon!
 * ```
 *
 * **Use the verbose mode (-v)**
 * ```shell
 * $ bin/console.php command:places list -v
 *
 * Command:places
 * ==============
 *
 * List the document(s) of the collection
 * --------------------------------------
 *
 * Document Found: 4
 * ---------- ----------------- ---------------------- ----------------------
 * _key       Name              Created                Modified
 * ---------- ----------------- ---------------------- ----------------------
 * 59918826   Béhuard           2025-01-20T15:28:41Z   2025-01-20T15:28:41Z
 * 59943726   Aix-en-Provence   2025-01-21T10:05:41Z   2025-01-21T10:05:41Z
 * 60280105   Savennières       2025-02-05T14:58:20Z   2025-02-05T14:58:20Z
 * 65910397   Marseille         2025-08-25T10:06:26Z   2025-08-25T10:06:26Z
 * ---------- ----------------- ---------------------- ----------------------
 *
 * ✅  Done in 6 ms
 * ----------------
 *
 * Thank you and see you soon!
 * ```
 *
 * **Use the very verbose mode (-vv)**
 * ```shell
 * bin/console.php command:places list -vv
 *
 * Command:places
 * ==============
 *
 * Listing documents in the collection
 * -----------------------------------
 *
 * Documents Found: 4
 *
 * [
 *     {
 *         "@type": "Place",
 *         "@context": "https://schema.org",
 *         "_key": 59943726,
 *         "name": "Aix-en-Provence",
 *         "created": "2025-01-21T10:05:41Z",
 *         "modified": "2025-01-21T10:05:41Z",
 *         "address": {
 *             "postalCode": 49170
 *         }
 *     },
 *     {
 *         "@type": "Place",
 *         "@context": "https://schema.org",
 *         "_key": 60280105,
 *         "name": "Savennières",
 *         "created": "2025-02-05T14:58:20Z",
 *         "modified": "2025-02-05T14:58:20Z",
 *         "address": {
 *             "postalCode": 49170
 *         }
 *     }
 *     ...
 * ]
 * ```
 */
trait DocumentsCommandList
{
    use DocumentsCommandTrait ;

    /**
     * Executes the action to list all documents from the model.
     *
     * @param InputInterface $input The console input instance.
     * @param OutputInterface $output The console output instance.
     * @param mixed|null $option This parameter is currently unused.
     *
     * @return int The exit code for the command, typically `ExitCode::SUCCESS`.
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     * @throws ArangoException
     * @throws BindException
     */
    protected function list( InputInterface $input, OutputInterface $output , mixed $option = null ):int
    {
        $this->assertDocuments() ;

        $io = $this->getIO( $input , $output ) ;

        $io->section( 'Listing documents in the collection' ) ;

        $documents = $this->documents->list() ;

        $this->outputDocuments( $documents , $input , $output ) ;

        return ExitCode::SUCCESS ;
    }
}