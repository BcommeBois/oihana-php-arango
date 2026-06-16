<?php

namespace oihana\arango\migrations ;

use RuntimeException ;

/**
 * Generates a new migration file — the `migrate --create` boilerplate, with no
 * database dependency (it only writes a file).
 *
 * By default it produces a named, timestamped {@see Migration} subclass with
 * empty `// TODO` `up()` / `down()` bodies, the intent written by a human
 * afterwards. {@see create()} can also **pre-fill** those bodies with provided
 * PHP code — the hook the `arango:analyzers --fix` action uses to emit a
 * ready-to-review repair migration rather than an empty shell.
 *
 * @package oihana\arango\migrations
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.2.0
 */
class MigrationGenerator
{
    /**
     * @param string $path      The directory the version file is written to.
     * @param string $namespace The PHP namespace the generated class is declared in.
     */
    public function __construct
    (
        protected string $path ,
        protected string $namespace = '' ,
    )
    {
    }

    /**
     * Writes a new `Version<timestamp>_<Label>.php` file and returns its path.
     *
     * By default the `up()` / `down()` bodies are left as a `// TODO` shell (the
     * `migrate --create` case). When `$up` (and optionally `$down`) is given,
     * that PHP code is injected verbatim into the method bodies — the hook the
     * `arango:analyzers --fix` action uses to write a ready-to-review repair
     * migration instead of an empty shell.
     *
     * @param string             $description A human description (also turned into the class label).
     * @param string|null        $timestamp   The `YmdHis` version prefix — injected for determinism; defaults to now.
     * @param string|null        $up          PHP code for the `up()` body (without the method wrapper); `null` keeps the `// TODO` shell.
     * @param string|null        $down        PHP code for the `down()` body; `null` keeps the `// TODO` shell.
     * @param array<int, string> $uses        Extra fully-qualified class names to `use` at the top of the file (so an injected body can reference them by their short name). `Migration` is always imported.
     *
     * @return string The path of the created file.
     *
     * @throws RuntimeException When the directory is missing or the file cannot be written.
     */
    public function create( string $description , ?string $timestamp = null , ?string $up = null , ?string $down = null , array $uses = [] ) : string
    {
        if ( !is_dir( $this->path ) )
        {
            throw new RuntimeException( sprintf( 'The migrations directory "%s" does not exist.' , $this->path ) ) ;
        }

        $timestamp = $timestamp ?? $this->timestamp() ;
        $label     = $this->label( $description ) ;
        $class     = 'Version' . $timestamp . ( $label === '' ? '' : '_' . $label ) ;
        $file      = rtrim( $this->path , DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $class . '.php' ;

        if ( file_put_contents( $file , $this->skeleton( $class , $description , $up , $down , $uses ) ) === false )
        {
            // @codeCoverageIgnoreStart
            throw new RuntimeException( sprintf( 'Unable to write the migration file "%s".' , $file ) ) ;
            // @codeCoverageIgnoreEnd
        }

        return $file ;
    }

    /**
     * Turns a free description into a PascalCase class label.
     *
     * @param string $description
     *
     * @return string
     */
    protected function label( string $description ) : string
    {
        $words = preg_split( '/[^a-zA-Z0-9]+/' , $description , -1 , PREG_SPLIT_NO_EMPTY ) ?: [] ;
        return implode( '' , array_map( ucfirst( ... ) , $words ) ) ;
    }

    /**
     * The body of a generated migration file.
     *
     * @param string             $class       The class name.
     * @param string             $description The human description.
     * @param string|null        $up          PHP code for the `up()` body; `null` falls back to the `// TODO` shell.
     * @param string|null        $down        PHP code for the `down()` body; `null` falls back to the `// TODO` shell.
     * @param array<int, string> $uses        Extra fully-qualified class names to import (deduplicated and sorted with `Migration`).
     *
     * @return string
     */
    protected function skeleton( string $class , string $description , ?string $up = null , ?string $down = null , array $uses = [] ) : string
    {
        $namespace   = $this->namespace === '' ? '' : "namespace $this->namespace ;\n\n" ;
        $description = addslashes( $description ) ;
        $useBlock    = $this->useBlock( $uses ) ;

        $upBody   = $this->body( $up ?? "// TODO: describe the transformation here, e.g.\n// \$this->query( 'FOR doc IN <collection> UPDATE doc WITH { … } IN <collection>' ) ;" ) ;
        $downBody = $this->body( $down ?? '// TODO: the rollback, when reversible (leave empty otherwise).' ) ;

        return <<<PHP
        <?php

        {$namespace}{$useBlock}

        /**
         * {$description}
         */
        class $class extends Migration
        {
            public function description() : string
            {
                return '$description' ;
            }

            public function up() : void
            {
        $upBody
            }

            public function down() : void
            {
        $downBody
            }
        }

        PHP ;
    }

    /**
     * Indents a (possibly multi-line) code body to the method-body column of
     * the generated file, leaving blank lines empty.
     *
     * @param string $code
     *
     * @return string
     */
    protected function body( string $code ) : string
    {
        $lines = explode( "\n" , $code ) ;

        return implode( "\n" , array_map( static fn( string $line ) : string => $line === '' ? '' : '        ' . $line , $lines ) ) ;
    }

    /**
     * The current `YmdHis` version prefix (overridable in tests).
     *
     * @return string
     */
    protected function timestamp() : string
    {
        return date( 'YmdHis' ) ;
    }

    /**
     * Builds the `use …;` import block — the always-needed {@see Migration}
     * plus the caller-supplied class names, deduplicated and sorted so the
     * output is deterministic.
     *
     * @param array<int, string> $uses Extra fully-qualified class names.
     *
     * @return string
     */
    protected function useBlock( array $uses ) : string
    {
        $imports = array_values( array_unique( array_merge( [ Migration::class ] , $uses ) ) ) ;
        sort( $imports ) ;

        return implode( "\n" , array_map( static fn( string $fqcn ) : string => "use $fqcn ;" , $imports ) ) ;
    }
}
