<?php

namespace tests\oihana\arango\db\helpers\fields;

use DI\Container;

use oihana\arango\enums\Arango;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;

use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\helpers\fields\aqlFieldUrl;
use function oihana\core\strings\keyValue;
use function oihana\files\path\joinPaths;
use function oihana\core\strings\key;

final class AqlFieldUrlTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnsupportedOperationException
     * @throws NotFoundExceptionInterface
     */
    public function testWithoutPlaceholdersOrContainer()
    {
        $result = aqlFieldUrl('url', 'doc', '/static/path', '_key', null, []);
        $expected = keyValue('url', concat(['/static/path', Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws UnsupportedOperationException
     * @throws NotFoundExceptionInterface
     */
    public function testWithPlaceholders()
    {
        $path = '/observation/{observation:[A-Za-z0-9_]+}/workspace/{workspace}/places';
        $args = [ 'observation' => '15454', 'workspace' => '787878' ] ;

        $result = aqlFieldUrl
        (
            key  : 'url',
            path : $path ,
            init :  [Arango::ARGS => $args]
        );

        $expectedPath = '/observation/15454/workspace/787878/places';
        $expected = keyValue('url', concat([$expectedPath, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testWithContainerBaseUrl()
    {
        $container = new Container();

        $container->set( 'baseUrl' , 'https://base.url' ) ;

        $result = aqlFieldUrl('url', 'doc', '/foo/bar', '_key', $container ) ;

        $expected = "url:CONCAT('https://base.url/foo/bar','/',doc._key)";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testWithUndefinedPlaceholderFallback()
    {
        $path = '/foo/{missing}/bar';
        $args = ['other' => 'value'];

        $result = aqlFieldUrl('url', 'doc', $path, '_key', null, [Arango::ARGS => $args]);
        $expectedPath = '/foo/{missing}/bar'; // placeholder remains because not defined in args
        $expected = keyValue('url', concat([$expectedPath, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testWithCustomKeyName()
    {
        $path = '/my/path';
        $result = aqlFieldUrl('url', 'doc', $path, 'customKey', null, []);
        $expected = keyValue('url', concat(['/my/path', Char::SLASH, key('customKey', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testWithEmptyPath()
    {
        $result = aqlFieldUrl('url', 'doc', null, '_key', null, []);
        $expected = keyValue('url', concat([Char::EMPTY, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }
}
