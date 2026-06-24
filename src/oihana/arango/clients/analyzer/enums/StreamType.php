<?php

namespace oihana\arango\clients\analyzer\enums ;

use oihana\reflect\traits\ConstantsTrait ;

/**
 * Input encoding the `ngram` analyzer operates on, carried as the
 * {@see AnalyzerField::STREAM_TYPE} property of the payload sent to
 * `POST /_api/analyzer`.
 *
 * @example
 * ```php
 * use oihana\arango\clients\analyzer\enums\StreamType;
 *
 * new NgramAnalyzer( min : 3 , max : 5 , streamType : StreamType::UTF8 ) ; // instead of 'utf8'
 * ```
 *
 * @see https://docs.arangodb.com/stable/index-and-search/analyzers/#ngram
 *
 * @package oihana\arango\clients\analyzer\enums
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.5.0
 */
class StreamType
{
    use ConstantsTrait ;

    /** Byte-wise input. Server default for the `ngram` analyzer. */
    public const string BINARY = 'binary' ;

    /** Codepoint-wise (UTF-8) input. */
    public const string UTF8 = 'utf8' ;
}
