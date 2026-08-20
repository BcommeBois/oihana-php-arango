<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\functions\strings\charLength;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\functions\strings\concatSeparator;
use function oihana\arango\db\functions\strings\contains;
use function oihana\arango\db\functions\strings\like;
use function oihana\arango\db\functions\strings\startsWith;
use function oihana\arango\db\functions\strings\toChar;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\core\strings\betweenDoubleQuotes;

class StringFunctionsTest extends TestCase
{
    #[DataProvider('provideSimpleStringFunctions')]
    public function testSimpleFunctions( string $functionName, string $expectedFunc, array $args, string $expected): void
    {
        $result = call_user_func_array("oihana\\arango\\db\\functions\\strings\\$functionName", $args);
        $this->assertSame($expectedFunc . "($expected)", $result);
    }

    /**
     * The expected AQL function name is written as a **literal**, never as the
     * `StringFunction::` constant the helper itself reads. Asserting through the
     * constant compares a value to itself: the row stays green whatever the
     * constant holds, which is exactly how `IS_IPV4` and `IPV4_TO_NUMBER` could
     * both carry `'IPV4_FROM_NUMBER'` under a fully covered, fully green suite.
     */
    public static function provideSimpleStringFunctions(): array
    {
        return [
            'charLength'         => [ 'charLength', 'CHAR_LENGTH', ['foo'], 'foo'],
            'crc32'              => [ 'crc32', 'CRC32', ['bar'], 'bar'],
            'encodeURIComponent' => [ 'encodeURIComponent', 'ENCODE_URI_COMPONENT', ['héllo'], 'héllo'],
            'fnv64'              => [ 'fnv64', 'FNV64', ['abc'], 'abc'],
            'ipv4FromNumber'     => [ 'ipv4FromNumber', 'IPV4_FROM_NUMBER', ['12345'], '12345'],
            'ipv4ToNumber'       => [ 'ipv4ToNumber', 'IPV4_TO_NUMBER', ['1.2.3.4'], '1.2.3.4'],
            'isIPV4'             => [ 'isIPV4', 'IS_IPV4', ['1.2.3.4'], '1.2.3.4'],
            'jsonParse'          => [ 'jsonParse', 'JSON_PARSE', ['{"a":1}'], '{"a":1}'],
            'jsonStringify'      => [ 'jsonStringify', 'JSON_STRINGIFY', ['{"b":2}'], '{"b":2}'],
            'left'               => [ 'left', 'LEFT', ['foobar', 3], 'foobar,3'],
            'levenshtein'        => [ 'levenshtein', 'LEVENSHTEIN_DISTANCE', ['kitten','sitting'], 'kitten,sitting'],
            'lower'              => [ 'lower', 'LOWER', ['TEST'], 'TEST'],
            'md5'                => [ 'md5', 'MD5', ['abc'], 'abc'],
            'randomToken'        => [ 'randomToken', 'RANDOM_TOKEN', [12], '12'],
            'right'              => [ 'right', 'RIGHT', ['foobar', 2], 'foobar,2'],
            'sha1'               => [ 'sha1', 'SHA1', ['x'], 'x'],
            'sha256'             => [ 'sha256', 'SHA256', ['y'], 'y'],
            'sha512'             => [ 'sha512', 'SHA512', ['z'], 'z'],
            'soundex'            => [ 'soundex', 'SOUNDEX', ['hello'], 'hello'],
            'toBase64'           => [ 'toBase64', 'TO_BASE64', ['abcd'], 'abcd'],
            'toChar'             => [ 'toChar', 'TO_CHAR', [65], '65'],
            'toHex'              => [ 'toHex', 'TO_HEX', ['hi'], 'hi'],
            'upper'              => [ 'upper', 'UPPER', ['test'], 'test'],
            'uuid'               => [ 'uuid', 'UUID', [], ''],
            'findLast'           => [ 'findLast', 'FIND_LAST', ['doc.text', '"o"', 0, null], 'doc.text,"o",0'],
            'findLastWithEnd'    => [ 'findLast', 'FIND_LAST', ['doc.text', '"o"', 0, 5], 'doc.text,"o",0,5'],
            'split'              => [ 'split', 'SPLIT', ['doc.text', '","'], 'doc.text,","'],
            'splitWithLimit'     => [ 'split', 'SPLIT', ['doc.text', '","', 2], 'doc.text,",",2'],
            // A separator naming another field is left raw, which is why the caller quotes text.
            'splitOnAField'      => [ 'split', 'SPLIT', ['doc.text', 'doc.separator'], 'doc.text,doc.separator'],
            'tokens'             => [ 'tokens', 'TOKENS', ['doc.content', '"text_en"'], 'doc.content,"text_en"'],
        ];
    }

    public function testCharLength() :void
    {
        $this->assertSame('CHAR_LENGTH(doc.name)' , charLength('doc.name'));
        $this->assertSame('CHAR_LENGTH("name")'   , charLength( betweenDoubleQuotes('name') ));
    }

    public function testToCharAcceptsALiteralOrAnExpression() :void
    {
        // A literal codepoint — the historical form.
        $this->assertSame( 'TO_CHAR(65)' , toChar( 65 ) );

        // Any AQL expression producing one. This is what makes the `alt:"toChar"`
        // transformation possible: it hands the field, like every other entry of
        // the catalogue does.
        $this->assertSame( 'TO_CHAR(doc.codepoint)' , toChar( 'doc.codepoint' ) );

        // Emitted as written — a fractional codepoint names no character, but the
        // helper does not arbitrate: refusing it here would guard nothing, since
        // the same value passes as a string through the `string` arm anyway.
        $this->assertSame( 'TO_CHAR(65.5)' , toChar( 65.5 ) );
        $this->assertSame( 'TO_CHAR(65.5)' , toChar( '65.5' ) );
    }

    public function testLike() :void
    {
        // Default: case-sensitive — the AQL third `caseInsensitive` argument is omitted.
        $this->assertSame( 'LIKE(doc.name,"John%")' , like( 'doc.name' , '"John%"' ) );

        // caseInsensitive: true → emits AQL's third argument `true`.
        $this->assertSame( 'LIKE(doc.name,"john%",true)' , like( 'doc.name' , '"john%"' , true ) );
        $this->assertSame( 'LIKE(doc.name,"john%",true)' , like( 'doc.name' , '"john%"' , caseInsensitive: true ) );
    }

    public function testStartsWith() :void
    {
        // Legacy string form: the prefix is kept raw (callers quote it themselves).
        $this->assertSame( 'STARTS_WITH(doc.name,"John")' , startsWith( 'doc.name' , '"John"' ) );
        $this->assertSame( 'STARTS_WITH(doc.name,doc.prefix)' , startsWith( 'doc.name' , 'doc.prefix' ) );
    }

    public function testStartsWithArrayOfPrefixes() :void
    {
        // ArangoSearch form: an array of prefixes is JSON-encoded (strings quoted).
        $this->assertSame( 'STARTS_WITH(doc.text,["lor","ips"])' , startsWith( 'doc.text' , [ 'lor' , 'ips' ] ) );
    }

    public function testStartsWithMinMatchCount() :void
    {
        $this->assertSame( 'STARTS_WITH(doc.text,["lor","ips"],1)' , startsWith( 'doc.text' , [ 'lor' , 'ips' ] , 1 ) );
        $this->assertSame( 'STARTS_WITH(doc.text,["lor","ips"],2)' , startsWith( 'doc.text' , [ 'lor' , 'ips' ] , minMatchCount: 2 ) );
    }

    /**
     * @return void
     * @throws UnsupportedOperationException
     */
    public function testConcatWithArray(): void
    {
        $values = array_map( fn( $value ) => aqlValue( $value ), ['a', 'b', 'c'] ) ;
        $result = concat( $values );
        $this->assertSame("CONCAT('a','b','c')", $result);
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function testConcatWithString(): void
    {
        $this->assertSame("CONCAT('foo')", concat('foo') );
        $this->assertSame('CONCAT()', concat(null));
    }

    public function testConcatSeparatorWithArray(): void
    {
        $this->assertSame("CONCAT_SEPARATOR('-','a','b')", concatSeparator('-', ['a', 'b'] ) );
    }

    public function testContainsWithReturnIndexTrue(): void
    {
        $this->assertSame
        (
            "CONTAINS('hello','e',true)" ,
            contains('hello', 'e', true )
        );
    }

    public function testContainsWithReturnIndexFalse(): void
    {
        $this->assertSame
        (
            "CONTAINS('hello','e')",
            contains('hello', 'e' )
        );
    }
}
