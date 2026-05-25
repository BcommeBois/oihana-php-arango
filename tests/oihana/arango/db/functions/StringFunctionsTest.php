<?php

namespace tests\oihana\arango\db\functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use oihana\arango\db\enums\functions\StringFunction;
use oihana\exceptions\UnsupportedOperationException;
use function oihana\arango\db\functions\strings\charLength;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\functions\strings\concatSeparator;
use function oihana\arango\db\functions\strings\contains;
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

    public static function provideSimpleStringFunctions(): array
    {
        return [
            'charLength'         => [ 'charLength', StringFunction::CHAR_LENGTH, ['foo'], 'foo'],
            'crc32'              => [ 'crc32', StringFunction::CRC32, ['bar'], 'bar'],
            'encodeURIComponent' => [ 'encodeURIComponent', StringFunction::ENCODE_URI_COMPONENT, ['héllo'], 'héllo'],
            'fnv64'              => [ 'fnv64', StringFunction::FNV64, ['abc'], 'abc'],
            'ipv4FromNumber'     => [ 'ipv4FromNumber', StringFunction::IPV4_FROM_NUMBER, ['12345'], '12345'],
            'ipv4ToNumber'       => [ 'ipv4ToNumber', StringFunction::IPV4_TO_NUMBER, ['1.2.3.4'], '1.2.3.4'],
            'isIPV4'             => [ 'isIPV4', StringFunction::IS_IPV4, ['1.2.3.4'], '1.2.3.4'],
            'jsonParse'          => [ 'jsonParse', StringFunction::JSON_PARSE, ['{"a":1}'], '{"a":1}'],
            'jsonStringify'      => [ 'jsonStringify', StringFunction::JSON_STRINGIFY, ['{"b":2}'], '{"b":2}'],
            'left'               => [ 'left', StringFunction::LEFT, ['foobar', 3], 'foobar,3'],
            'levenshtein'        => [ 'levenshtein', StringFunction::LEVENSHTEIN_DISTANCE, ['kitten','sitting'], 'kitten,sitting'],
            'lower'              => [ 'lower', StringFunction::LOWER, ['TEST'], 'TEST'],
            'md5'                => [ 'md5', StringFunction::MD5, ['abc'], 'abc'],
            'randomToken'        => [ 'randomToken', StringFunction::RANDOM_TOKEN, [12], '12'],
            'right'              => [ 'right', StringFunction::RIGHT, ['foobar', 2], 'foobar,2'],
            'sha1'               => [ 'sha1', StringFunction::SHA1, ['x'], 'x'],
            'sha256'             => [ 'sha256', StringFunction::SHA256, ['y'], 'y'],
            'sha512'             => [ 'sha512', StringFunction::SHA512, ['z'], 'z'],
            'soundex'            => [ 'soundex', StringFunction::SOUNDEX, ['hello'], 'hello'],
            'toBase64'           => [ 'toBase64', StringFunction::TO_BASE64, ['abcd'], 'abcd'],
            'toChar'             => [ 'toChar', StringFunction::TO_CHAR, [65], '65'],
            'toHex'              => [ 'toHex', StringFunction::TO_HEX, ['hi'], 'hi'],
            'upper'              => [ 'upper', StringFunction::UPPER, ['test'], 'test'],
            'uuid'               => [ 'uuid', StringFunction::UUID, [], ''],
        ];
    }

    public function testCharLength() :void
    {
        $this->assertSame('CHAR_LENGTH(doc.name)' , charLength('doc.name'));
        $this->assertSame('CHAR_LENGTH("name")'   , charLength( betweenDoubleQuotes('name') ));
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
