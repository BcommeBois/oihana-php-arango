<?php

namespace oihana\arango\commands\actions;

use Throwable;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

use oihana\arango\clients\analyzer\enums\AnalyzerField;
use oihana\arango\clients\analyzer\RawAnalyzer;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoAnalyzersTrait;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\commands\traits\ArangoMigrationsTrait;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\options\analyzers\AnalyzerDefinition;
use oihana\arango\db\results\DiffReport;
use oihana\arango\migrations\MigrationGenerator;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

use oihana\enums\Char;

use function oihana\core\strings\toPhpString;

// Manage the custom ArangoSearch analyzers of the database
// $ php bin/console.php command:arangodb analyzers                 (list the custom analyzers)
// $ php bin/console.php command:arangodb analyzers --diff          (compare the declared analyzers with the server)
// $ php bin/console.php command:arangodb analyzers --sync          (create the missing analyzers, signal the drifted ones)
// $ php bin/console.php command:arangodb analyzers --sync --force  (also repair the drifted ones, cascading to their Views)
// $ php bin/console.php command:arangodb analyzers --fix           (generate a repair migration per drifted analyzer; touches no database)
// $ php bin/console.php command:arangodb analyzers --prune         (drop the orphan custom analyzers declared by none; used ones need --force)

/**
 * Manages the **custom** ArangoSearch analyzers of the database, from the
 * declarative registry ({@see ArangoAnalyzersTrait}) — the analyzer
 * counterpart of {@see ArangoViewsAction}.
 *
 * The default behaviour lists the custom analyzers of the live database
 * (built-in ones are summarized as a count). The other modes:
 *
 * - `--diff`         : compares each declared {@see AnalyzerDefinition}
 *                      with the server ({@see DiffStatus}) and lists the orphan
 *                      custom analyzers (on the server, declared by none).
 * - `--sync`         : same walk, but missing analyzers are created; drifted
 *                      ones are only signalled (immutable). With `--force`,
 *                      drifted analyzers are repaired in place — drop + recreate
 *                      + rebuild of their dependent Views.
 * - `--fix`          : generates one ready-to-review **repair migration** per
 *                      drifted analyzer (the same-name drop + recreate, deferred
 *                      and versioned) — it writes files and **never** touches the
 *                      database. Review them, then run `migrate`.
 * - `--prune`        : drops the **orphan** custom analyzers (on the server,
 *                      declared by none), after confirmation. An orphan still
 *                      used by a View is only dropped with `--force` (it leaves
 *                      the View dangling). Built-in analyzers are never pruned.
 *
 * Analyzers are declared once at the database level via the `analyzers` init key
 * ({@see \oihana\arango\commands\enums\ArangoCommandParam::ANALYZERS}), not per
 * model — they are shared and database-scoped.
 *
 * @package oihana\arango\commands\actions
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.3.0
 */
trait ArangoAnalyzersAction
{
    use ArangoAnalyzersTrait ,
        ArangoClientTrait ,
        ArangoMigrationsTrait ,
        IOTrait ;

    /**
     * Manages the custom analyzers of the database.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function analyzers( InputInterface $input , OutputInterface $output ) :int
    {
        $io = $this->getIO( $input , $output ) ;

        if ( $input->getOption( ArangoCommandOption::FIX ) )
        {
            return $this->analyzersFix( $input , $io ) ;
        }

        if ( $input->getOption( ArangoCommandOption::PRUNE ) )
        {
            return $this->analyzersPrune( $input , $output , $io ) ;
        }

        if ( $input->getOption( ArangoCommandOption::SYNC ) !== false || $input->getOption( ArangoCommandOption::DIFF ) )
        {
            return $this->analyzersReport
            (
                $input ,
                $io ,
                apply : $input->getOption( ArangoCommandOption::SYNC ) !== false ,
                force : (bool) $input->getOption( ArangoCommandOption::FORCE ) ,
            ) ;
        }

        return $this->analyzersList( $input , $io ) ;
    }

    /**
     * Generates one ready-to-review **repair migration** per drifted analyzer —
     * the deferred, versioned form of the `--sync --force` cascade. It writes
     * files only and **never** touches the database.
     *
     * Each drifted {@see AnalyzerDefinition} yields a `Version*.php` migration
     * whose `up()` reconstructs the declared analyzer with a {@see RawAnalyzer}
     * (the declared `type` + `properties` dumped as a flat literal) and calls
     * `analyzerSync( $def , force: true )` — the same-name drop + recreate +
     * dependent-View rebuild (path B). The rollback is left as a comment: a
     * repair is not auto-reversible (re-applying the previous analyzer would
     * re-introduce the drift). Review the files, then run `migrate`.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     *
     * @return int
     */
    private function analyzersFix( InputInterface $input , SymfonyStyle $io ) :int
    {
        $io->section( 'Generate repair migrations for the drifted analyzers' ) ;

        if ( empty( $this->migrationsPath ) )
        {
            $io->error( 'No migrations path configured — pass it via the `migrationsPath` init key of the command.' ) ;
            return ExitCode::FAILURE ;
        }

        $definitions = $this->getAnalyzerDefinitions() ;

        if ( $definitions === [] )
        {
            $io->warning( 'No analyzers configured — pass them via the `analyzers` init key of the command.' ) ;
            return ExitCode::SUCCESS ;
        }

        $facade = $this->resolveFacade( $input ) ;

        if ( $facade === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        $generator = new MigrationGenerator( $this->migrationsPath , $this->migrationsNamespace ) ;
        $drifted   = 0 ;

        foreach ( $definitions as $definition )
        {
            if ( $facade->analyzerDiff( $definition )->status !== DiffStatus::DRIFTED )
            {
                continue ;
            }

            $drifted++ ;

            try
            {
                $file = $generator->create
                (
                    sprintf( 'repair analyzer %s' , $definition->name ) ,
                    up   : $this->analyzersRepairBody( $definition ) ,
                    down : '// Not auto-reversible : re-applying the previous (drifted) analyzer would re-introduce the drift.' ,
                    uses : [ AnalyzerDefinition::class , RawAnalyzer::class ] ,
                ) ;

                $io->text( sprintf( '<info>→</info> %s — repair migration generated : %s' , $definition->name , $file ) ) ;
            }
            catch ( Throwable $exception )
            {
                $io->error( sprintf( '%s — %s' , $definition->name , $exception->getMessage() ) ) ;
                return ExitCode::FAILURE ;
            }
        }

        if ( $drifted === 0 )
        {
            $io->text( 'No drifted analyzer — nothing to repair.' ) ;
        }
        else
        {
            $io->newLine() ;
            $io->text( 'Review the generated migration(s), then run `migrate`.' ) ;
        }

        $io->newLine() ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Lists the custom analyzers of the database (built-in ones summarized as a
     * count, since they are always present and not managed here).
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     *
     * @return int
     */
    private function analyzersList( InputInterface $input , SymfonyStyle $io ) :int
    {
        $db = $this->resolveDatabase( $input ) ;
        if ( $db === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        $database = $input->getOption( ArangoCommandOption::DATABASE ) ?? $this->getDatabase() ;

        $io->section( sprintf( "List the custom analyzers of the '%s' database" , $database ) ) ;

        try
        {
            $analyzers = $db->listAnalyzers() ;
        }
        catch ( ArangoException $exception )
        {
            $io->error( 'Unable to list the analyzers — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }

        $custom  = [] ;
        $builtin = 0 ;

        foreach ( $analyzers as $analyzer )
        {
            $name = $analyzer[ AnalyzerField::NAME ] ?? Char::EMPTY ;

            // Built-in analyzers (identity, text_*) carry no `dbname::` namespace.
            if ( !str_contains( $name , '::' ) )
            {
                $builtin++ ;
                continue ;
            }

            $custom[] = [ 'name' => $this->analyzersShortName( $name ) , 'type' => $analyzer[ AnalyzerField::TYPE ] ?? Char::EMPTY ] ;
        }

        usort( $custom , static fn( array $a , array $b ) : int => strcmp( $a[ 'name' ] , $b[ 'name' ] ) ) ;

        if ( $custom === [] )
        {
            $io->text( 'There are no custom analyzers in the database.' ) ;
        }
        else
        {
            foreach ( $custom as $analyzer )
            {
                $io->text( sprintf( '→ %s (%s)' , $analyzer[ 'name' ] , $analyzer[ 'type' ] ) ) ;
            }
        }

        if ( $builtin > 0 )
        {
            $io->text( sprintf( '(+ %d built-in)' , $builtin ) ) ;
        }

        $io->newLine() ;

        return ExitCode::SUCCESS ;
    }

    /**
     * Drops the **orphan** custom analyzers — on the server, declared by none —
     * after confirmation (the opt-in `--prune` mode).
     *
     * Only custom analyzers (named `dbname::…`) are considered: built-in ones
     * and analyzers in the registry are never pruned. An orphan still
     * referenced by a View is **not** dropped unless `--force` is given (the
     * drop would leave the View dangling) — without it, it is only signalled.
     * The drop set is confirmed interactively; `--yes` skips the prompt and a
     * non-interactive run without `--yes` refuses, by safety.
     *
     * ⚠ On a **shared** database an orphan may belong to another application —
     * see `db/analyzers.md`.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @param SymfonyStyle    $io
     *
     * @return int
     */
    private function analyzersPrune( InputInterface $input , OutputInterface $output , SymfonyStyle $io ) :int
    {
        $io->section( 'Prune the orphan custom analyzers' ) ;
        $io->text( 'Declared analyzers and built-in analyzers are never pruned.' ) ;

        $db     = $this->resolveDatabase( $input ) ;
        $facade = $this->resolveFacade( $input ) ;

        if ( $db === null || $facade === null )
        {
            $io->error( 'No ArangoDB HTTP client available (check the endpoint and database configuration).' ) ;
            return ExitCode::FAILURE ;
        }

        try
        {
            $analyzers = $db->listAnalyzers() ;
        }
        catch ( ArangoException $exception )
        {
            $io->error( 'Unable to list the analyzers — ArangoDB HTTP API unreachable: ' . $exception->getMessage() ) ;
            return ExitCode::FAILURE ;
        }

        $declared = array_map( static fn( AnalyzerDefinition $definition ) :string => $definition->name , $this->getAnalyzerDefinitions() ) ;
        $force    = (bool) $input->getOption( ArangoCommandOption::FORCE ) ;
        $orphans  = [] ; // short name => dependent View names

        foreach ( $analyzers as $analyzer )
        {
            $name = $analyzer[ AnalyzerField::NAME ] ?? Char::EMPTY ;

            if ( !str_contains( $name , '::' ) )
            {
                continue ; // built-in : never pruned
            }

            $short = $this->analyzersShortName( $name ) ;

            if ( in_array( $short , $declared , true ) )
            {
                continue ; // declared : never pruned
            }

            $orphans[ $short ] = $facade->analyzerDependentViews( $short ) ;
        }

        if ( $orphans === [] )
        {
            $io->text( 'There is no orphan custom analyzer to prune.' ) ;
            $io->newLine() ;
            return ExitCode::SUCCESS ;
        }

        ksort( $orphans ) ;

        $toDrop = [] ;

        foreach ( $orphans as $short => $views )
        {
            if ( $views !== [] && !$force )
            {
                $io->text( sprintf( '<comment>~</comment> %s — used by %s (drop with --force, would leave the View(s) dangling)' , $short , implode( ', ' , $views ) ) ) ;
                continue ;
            }

            $toDrop[ $short ] = $views ;
        }

        if ( $toDrop === [] )
        {
            $io->text( 'No orphan to drop — the one(s) above are still used (rerun with --force to drop them anyway).' ) ;
            $io->newLine() ;
            return ExitCode::SUCCESS ;
        }

        $io->newLine() ;
        $io->text( sprintf( 'To drop (%d) :' , count( $toDrop ) ) ) ;

        foreach ( $toDrop as $short => $views )
        {
            $io->text( $views === []
                     ? sprintf( '  · %s (orphan)' , $short )
                     : sprintf( '  · %s (orphan, used by %s — View(s) left dangling)' , $short , implode( ', ' , $views ) ) ) ;
        }

        if ( !$input->getOption( ArangoCommandOption::YES ) )
        {
            if ( !$input->isInteractive() )
            {
                $io->error( 'Refusing to drop analyzers without confirmation — rerun with --yes.' ) ;
                return ExitCode::FAILURE ;
            }

            $question = new ConfirmationQuestion( sprintf( 'Drop these %d analyzer(s) ? [y/N] ' , count( $toDrop ) ) , false ) ;

            if ( !$this->getQuestionHelper()->ask( $input , $output , $question ) )
            {
                $io->text( 'Aborted.' ) ;
                $io->newLine() ;
                return ExitCode::SUCCESS ;
            }
        }

        $io->newLine() ;

        $failed = false ;

        foreach ( $toDrop as $short => $views )
        {
            try
            {
                $db->analyzer( $short )->drop( force: $views !== [] ) ;

                $io->text( $views === []
                         ? sprintf( '<info>✓</info> %s — dropped (orphan)' , $short )
                         : sprintf( '<info>✓</info> %s — dropped (orphan, was used by %s)' , $short , implode( ', ' , $views ) ) ) ;
            }
            catch ( ArangoException $exception )
            {
                $io->text( sprintf( '<error>✗</error> %s — %s' , $short , $exception->getMessage() ) ) ;
                $failed = true ;
            }
        }

        $io->newLine() ;

        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS ;
    }

    /**
     * Prints the orphan custom analyzers — on the server but declared by none.
     * Report only : orphans are never dropped here.
     *
     * @param InputInterface     $input
     * @param SymfonyStyle       $io
     * @param array<int, string> $declared The analyzer names declared in the registry.
     *
     * @return void
     */
    private function analyzersRenderOrphans( InputInterface $input , SymfonyStyle $io , array $declared ) :void
    {
        $db = $this->resolveDatabase( $input ) ;
        if ( $db === null )
        {
            return ;
        }

        try
        {
            $names = $db->listAnalyzers() ;
        }
        catch ( ArangoException )
        {
            return ;
        }

        $orphans = [] ;
        foreach ( $names as $analyzer )
        {
            $name = $analyzer[ AnalyzerField::NAME ] ?? Char::EMPTY ;
            if ( !str_contains( $name , '::' ) )
            {
                continue ; // built-in : never an orphan
            }
            $short = $this->analyzersShortName( $name ) ;
            if ( !in_array( $short , $declared , true ) )
            {
                $orphans[] = $short ;
            }
        }

        if ( $orphans === [] )
        {
            return ;
        }

        sort( $orphans ) ;

        $io->newLine() ;
        $io->text( sprintf( 'Orphan custom analyzers (on the server, declared by none) : %s' , implode( ', ' , $orphans ) ) ) ;
    }

    /**
     * Prints one analyzer's {@see DiffReport} as a status line plus one
     * indented line per change.
     *
     * @param SymfonyStyle $io
     * @param DiffReport   $report
     * @param bool         $apply Whether the report comes from `analyzerSync()`.
     * @param bool         $force Whether the sync was forced.
     *
     * @return void
     */
    private function analyzersRenderReport( SymfonyStyle $io , DiffReport $report , bool $apply , bool $force ) :void
    {
        $line = match( true )
        {
            $report->status === DiffStatus::IN_SYNC                     => sprintf( '<info>✓</info> %s — in sync' , $report->name ) ,
            $report->status === DiffStatus::MISSING && $report->applied => sprintf( '<info>✓</info> %s — created' , $report->name ) ,
            $report->status === DiffStatus::MISSING                     => sprintf( '<error>✗</error> %s — missing on the server' , $report->name ) ,
            $report->status === DiffStatus::DRIFTED && $report->applied => sprintf( '<info>✓</info> %s — repaired' , $report->name ) ,
            $report->status === DiffStatus::DRIFTED                     => sprintf( '<comment>~</comment> %s — drifted' , $report->name ) ,
            $report->status === DiffStatus::INVALID                     => sprintf( '<error>!</error> %s — invalid' , $report->name ) ,
            default                                                     => sprintf( '<error>!</error> %s — unreachable' , $report->name ) ,
        } ;

        if ( $apply && !$report->applied )
        {
            if ( $report->status === DiffStatus::MISSING )
            {
                $line .= ' (create failed)' ;
            }
            elseif ( $report->status === DiffStatus::DRIFTED )
            {
                $line .= $force ? ' (repair failed)' : ' (immutable — use --force or --fix)' ;
            }
        }

        $io->text( $line ) ;

        foreach ( $report->changes as $change )
        {
            $io->text( '    · ' . $change ) ;
        }
    }

    /**
     * Builds the `up()` body of a repair migration for one drifted analyzer:
     * the declared `type` + `properties` are dumped as a flat PHP literal
     * (via {@see toPhpString()}) into a {@see RawAnalyzer}, wrapped in an
     * {@see AnalyzerDefinition} and forced through `analyzerSync()` (path B —
     * same-name drop + recreate + dependent-View rebuild).
     *
     * @param AnalyzerDefinition $definition The declared analyzer to repair to.
     *
     * @return string The PHP code of the migration `up()` body.
     */
    private function analyzersRepairBody( AnalyzerDefinition $definition ) :string
    {
        $declared   = $definition->options->toArray() ;
        $type       = (string) ( $declared[ AnalyzerField::TYPE ] ?? Char::EMPTY ) ;
        $properties = (array) ( $declared[ AnalyzerField::PROPERTIES ] ?? [] ) ;
        $options    = [ 'useBrackets' => true ] ;

        return implode( "\n" ,
        [
            '$definition = new AnalyzerDefinition' ,
            '(' ,
            sprintf( '    %s ,' , toPhpString( $definition->name , $options ) ) ,
            sprintf( '    new RawAnalyzer( %s , %s ) ,' , toPhpString( $type , $options ) , toPhpString( $properties , $options ) ) ,
            sprintf( '    %s ,' , toPhpString( array_values( $definition->features ) , $options ) ) ,
            ') ;' ,
            '' ,
            '$this->db->analyzerSync( $definition , force: true ) ;' ,
        ] ) ;
    }

    /**
     * Walks the declared analyzers and reports (or provisions, in apply mode)
     * their state, with the orphan custom analyzers as a footnote.
     *
     * @param InputInterface $input
     * @param SymfonyStyle   $io
     * @param bool           $apply False → `--diff` (read-only) ; true → `--sync`.
     * @param bool           $force Repair drifted analyzers in place (cascade) — only with `$apply`.
     *
     * @return int
     */
    private function analyzersReport( InputInterface $input , SymfonyStyle $io , bool $apply , bool $force ) :int
    {
        $io->section( $apply ? 'Synchronize the declared analyzers' : 'Diff the declared analyzers' ) ;

        $definitions = $this->getAnalyzerDefinitions() ;

        if ( $definitions === [] )
        {
            $io->warning( 'No analyzers configured — pass them via the `analyzers` init key of the command.' ) ;
            return ExitCode::SUCCESS ;
        }

        $facade   = $this->resolveFacade( $input ) ;
        $declared = [] ;
        $status   = ExitCode::SUCCESS ;

        foreach ( $definitions as $definition )
        {
            $declared[] = $definition->name ;

            $report = $facade === null
                    ? new DiffReport( $definition->name , DiffStatus::UNREACHABLE , [ 'no database available' ] , kind : DiffKind::ANALYZER )
                    : ( $apply ? $facade->analyzerSync( $definition , $force ) : $facade->analyzerDiff( $definition ) ) ;

            $this->analyzersRenderReport( $io , $report , $apply , $force ) ;

            if ( $report->status === DiffStatus::UNREACHABLE )
            {
                $status = ExitCode::FAILURE ;
            }
        }

        $this->analyzersRenderOrphans( $input , $io , $declared ) ;

        $io->newLine() ;

        return $status ;
    }

    /**
     * Strips the `dbname::` namespace prefix from an analyzer name.
     *
     * @param string $name
     *
     * @return string
     */
    private function analyzersShortName( string $name ) :string
    {
        $position = strpos( $name , '::' ) ;

        return $position === false ? $name : substr( $name , $position + 2 ) ;
    }
}
