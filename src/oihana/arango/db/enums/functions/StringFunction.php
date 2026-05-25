<?php

namespace oihana\arango\db\enums\functions;

use oihana\reflect\traits\FunctionCallTrait;

/**
 * AQL offers functions for string processing.
 * @see https://docs.arangodb.com/stable/aql/functions/string
 */
class StringFunction
{
    use FunctionCallTrait ;

    public const string CHAR_LENGTH = 'CHAR_LENGTH' ;
    public const string CONCAT = 'CONCAT' ;
    public const string CONCAT_SEPARATOR = 'CONCAT_SEPARATOR' ;
    public const string CONTAINS = 'CONTAINS' ;
    public const string COUNT = 'COUNT' ;
    public const string CRC32 = 'CRC32' ;
    public const string ENCODE_URI_COMPONENT = 'ENCODE_URI_COMPONENT' ;
    public const string FIND_FIRST = 'FIND_FIRST' ;
    public const string FIND_LAST = 'FIND_LAST' ;
    public const string FNV64 = 'FNV64' ;
    public const string IPV4_FROM_NUMBER = 'IPV4_FROM_NUMBER' ;
    public const string IPV4_TO_NUMBER = 'IPV4_FROM_NUMBER' ;
    public const string IS_IPV4 = 'IPV4_FROM_NUMBER' ;
    public const string JSON_PARSE = 'JSON_PARSE' ;
    public const string JSON_STRINGIFY = 'JSON_STRINGIFY' ;
    public const string LEFT = 'LEFT' ;
    public const string LENGTH = 'LENGTH' ;
    public const string LEVENSHTEIN_DISTANCE = 'LEVENSHTEIN_DISTANCE' ;
    public const string LIKE = 'LIKE' ;
    public const string LOWER = 'LOWER' ;
    public const string LTRIM = 'LTRIM' ;
    public const string MD5 = 'MD5' ;
    public const string NGRAM_POSITIONAL_SIMILARITY = 'NGRAM_POSITIONAL_SIMILARITY' ;
    public const string NGRAM_SIMILARITY = 'NGRAM_SIMILARITY' ;
    public const string RANDOM_TOKEN = 'RANDOM_TOKEN' ;
    public const string REGEX_MATCHES = 'REGEX_MATCHES' ;
    public const string REGEX_REPLACE = 'REGEX_REPLACE' ;
    public const string REGEX_SPLIT = 'REGEX_SPLIT' ;
    public const string REGEX_TEST = 'REGEX_TEST' ;
    public const string REPEAT = 'REPEAT' ;
    public const string REVERSE = 'REVERSE' ;
    public const string RIGHT = 'RIGHT' ;
    public const string RTRIM = 'RTRIM' ;
    public const string SHA1 = 'SHA1' ;
    public const string SHA256 = 'SHA256' ;
    public const string SHA512 = 'SHA512' ;
    public const string SOUNDEX = 'SOUNDEX' ;
    public const string SPLIT = 'SPLIT' ;
    public const string STARTS_WITH = 'STARTS_WITH' ;
    public const string SUBSTITUTE = 'SUBSTITUTE' ;
    public const string SUBSTRING = 'SUBSTRING' ;
    public const string SUBSTRING_BYTES = 'SUBSTRING_BYTES' ;
    public const string TO_BASE64 = 'TO_BASE64' ;
    public const string TO_CHAR = 'TO_CHAR' ;
    public const string TO_HEX = 'TO_HEX' ;
    public const string TOKENS = 'TOKENS' ;
    public const string TRIM = 'TRIM' ;
    public const string UPPER = 'UPPER' ;
    public const string UUID = 'UUID' ;
}
