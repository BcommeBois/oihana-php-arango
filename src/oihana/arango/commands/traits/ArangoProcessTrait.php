<?php

namespace oihana\arango\commands\traits;

use ReflectionException;
use RuntimeException;

use oihana\enums\Char;
use oihana\options\Option;
use oihana\options\Options;

/**
 * Builds and runs the `arangodump` / `arangorestore` external processes
 * **without going through a shell**.
 *
 * The previous implementation passed a single string (assembled by the
 * options serializer, which only `json_encode`s values) to `system()`.
 * Inside a shell, that string is re-parsed: a value containing `$(…)`,
 * backticks, spaces or quotes would break the command — or inject
 * arbitrary shell. Building an explicit argument vector and handing it to
 * {@see proc_open()} (array form, available since PHP 7.4) bypasses the
 * shell entirely, so option values are passed verbatim as `argv` entries
 * and can never be re-interpreted.
 *
 * @package oihana\arango\commands\traits
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
trait ArangoProcessTrait
{
    /**
     * Converts an {@see Options} object into a flat argument vector, using
     * the same flag mapping as the string serializer (prefix +
     * {@see Option::getCommandOption()}), but keeping each value as its own
     * `argv` entry instead of quoting it into a shell string.
     *
     * - array value  → the flag is repeated for each item (`--collection a --collection b`);
     * - boolean true → the bare flag (`--create-collection`);
     * - boolean false → flag followed by the literal `false`;
     * - scalar value → flag followed by the value, as a single argv entry.
     *
     * Null and empty values are dropped (handled by {@see Options::toArray()}).
     *
     * @param Options $options The options to serialize.
     * @param class-string<Option> $optionClass The {@see Option} subclass providing the flag mapping.
     * @return array<int, string>
     * @throws ReflectionException
     */
    protected static function optionsToArguments( Options $options , string $optionClass ) :array
    {
        $arguments = [] ;

        foreach ( $options->toArray( true ) as $name => $value )
        {
            $prefix = $optionClass::getCommandPrefix( $name ) ?? Char::DOUBLE_HYPHEN ;
            $flag   = $prefix . $optionClass::getCommandOption( $name ) ;

            if ( is_array( $value ) )
            {
                foreach ( $value as $item )
                {
                    $arguments[] = $flag ;
                    $arguments[] = (string) $item ;
                }
            }
            elseif ( $value === true )
            {
                $arguments[] = $flag ;
            }
            else
            {
                $arguments[] = $flag ;
                $arguments[] = $value === false ? 'false' : (string) $value ;
            }
        }

        return $arguments ;
    }

    /**
     * Runs an external process from an argument vector, without a shell.
     *
     * stdin/stdout/stderr are inherited from the parent process so the
     * native `arangodump` / `arangorestore` progress output stays visible,
     * unless `$silent` redirects stdout/stderr to the null device.
     *
     * @param array<int, string> $arguments The argv (argv[0] = binary name).
     * @param bool                $silent    Whether to discard the process output.
     * @return int The process exit code.
     * @throws RuntimeException When the process cannot be started.
     */
    protected static function runProcess( array $arguments , bool $silent = false ) :int
    {
        $devNull = strtoupper( substr( PHP_OS_FAMILY , 0 , 3 ) ) === 'WIN' ? 'NUL' : '/dev/null' ;

        $descriptors =
        [
            0 => STDIN ,
            1 => $silent ? [ 'file' , $devNull , 'w' ] : STDOUT ,
            2 => $silent ? [ 'file' , $devNull , 'w' ] : STDERR ,
        ] ;

        $process = proc_open( $arguments , $descriptors , $pipes ) ;

        if ( !is_resource( $process ) )
        {
            throw new RuntimeException( sprintf( 'Failed to start the process: %s' , $arguments[ 0 ] ?? Char::EMPTY ) ) ;
        }

        return proc_close( $process ) ;
    }
}
