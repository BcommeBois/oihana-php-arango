<?php

namespace oihana\arango\commands\actions;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use oihana\arango\clients\analyzer\enums\AnalyzerField;
use oihana\arango\clients\exceptions\ArangoException;
use oihana\arango\commands\options\ArangoCommandOption;
use oihana\arango\commands\traits\ArangoAnalyzersTrait;
use oihana\arango\commands\traits\ArangoClientTrait;
use oihana\arango\db\enums\DiffKind;
use oihana\arango\db\enums\DiffStatus;
use oihana\arango\db\results\DiffReport;

use oihana\commands\enums\ExitCode;
use oihana\commands\traits\IOTrait;

use oihana\enums\Char;

// Manage the custom ArangoSearch analyzers of the database
// $ php bin/console.php command:arangodb analyzers                 (list the custom analyzers)
// $ php bin/console.php command:arangodb analyzers --diff          (compare the declared analyzers with the server)
// $ php bin/console.php command:arangodb analyzers --sync          (create the missing analyzers, signal the drifted ones)
// $ php bin/console.php command:arangodb analyzers --sync --force  (also repair the drifted ones, cascading to their Views)

/**
 * Manages the **custom** ArangoSearch analyzers of the database, from the
 * declarative registry ({@see ArangoAnalyzersTrait}) — the analyzer
 * counterpart of {@see ArangoViewsAction}.
 *
 * The default behaviour lists the custom analyzers of the live database
 * (built-in ones are summarized as a count). Two report modes are available:
 *
 * - `--diff`         : compares each declared {@see \oihana\arango\db\options\analyzers\AnalyzerDefinition}
 *                      with the server ({@see DiffStatus}) and lists the orphan
 *                      custom analyzers (on the server, declared by none).
 * - `--sync`         : same walk, but missing analyzers are created; drifted
 *                      ones are only signalled (immutable). With `--force`,
 *                      drifted analyzers are repaired in place — drop + recreate
 *                      + rebuild of their dependent Views.
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
