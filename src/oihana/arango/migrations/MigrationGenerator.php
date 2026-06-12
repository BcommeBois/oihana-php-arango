<?php

namespace oihana\arango\migrations ;

use RuntimeException ;

/**
 * Generates the empty shell of a new migration — the `migrate --create`
 * boilerplate, with no database dependency (it only writes a file).
 *
 * The engine never fills `up()` / `down()`: it produces a named, timestamped
 * {@see Migration} subclass with empty bodies, and the intent is always
 * written by a human afterwards.
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
     * Writes a new `Version<timestamp>_<Label>.php` shell and returns its
     * path.
     *
     * @param string      $description A human description (also turned into the class label).
     * @param string|null $timestamp   The `YmdHis` version prefix — injected for determinism; defaults to now.
     *
     * @return string The path of the created file.
     *
     * @throws RuntimeException When the directory is missing or the file cannot be written.
     */
    public function create( string $description , ?string $timestamp = null ) : string
    {
        if ( !is_dir( $this->path ) )
        {
            throw new RuntimeException( sprintf( 'The migrations directory "%s" does not exist.' , $this->path ) ) ;
        }

        $timestamp = $timestamp ?? $this->timestamp() ;
        $label     = $this->label( $description ) ;
        $class     = 'Version' . $timestamp . ( $label === '' ? '' : '_' . $label ) ;
        $file      = rtrim( $this->path , DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $class . '.php' ;

        if ( file_put_contents( $file , $this->skeleton( $class , $description ) ) === false )
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
     * @param string $class       The class name.
     * @param string $description  The human description.
     *
     * @return string
     */
    protected function skeleton( string $class , string $description ) : string
    {
        $namespace   = $this->namespace === '' ? '' : "namespace $this->namespace ;\n\n" ;
        $description = addslashes( $description ) ;

        return <<<PHP
        <?php

        {$namespace}use oihana\\arango\\migrations\\Migration ;

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
                // TODO: describe the transformation here, e.g.
                // \$this->query( 'FOR doc IN <collection> UPDATE doc WITH { … } IN <collection>' ) ;
            }

            public function down() : void
            {
                // TODO: the rollback, when reversible (leave empty otherwise).
            }
        }

        PHP ;
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
}
