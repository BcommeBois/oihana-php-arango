<?php

namespace tests\oihana\arango\db\helpers\fields;

use DI\Container;

use oihana\arango\enums\Arango;
use oihana\arango\enums\Field;
use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

use oihana\enums\Char;
use oihana\exceptions\UnsupportedOperationException;
use oihana\exceptions\ValidationException;

use org\schema\constants\Schema;

use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\fields\aqlFieldUrl;
use function oihana\arango\db\functions\strings\concat;
use function oihana\core\strings\keyValue;
use function oihana\core\strings\key;

final class AqlFieldUrlTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnsupportedOperationException
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithoutPlaceholdersOrContainer()
    {
        $result = aqlFieldUrl('url', 'doc', [ Field::PATH => '/static/path' ]);
        $expected = keyValue('url', concat(['/static/path', Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws UnsupportedOperationException
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithPlaceholders()
    {
        $path = '/observation/{observation:[A-Za-z0-9_]+}/workspace/{workspace}/places';
        $args = [ 'observation' => '15454', 'workspace' => '787878' ] ;

        $result = aqlFieldUrl
        (
            key     : 'url',
            options : [ Field::PATH => $path ] ,
            init    : [ Arango::ARGS => $args ]
        );

        $expectedPath = '/observation/15454/workspace/787878/places';
        $expected = keyValue('url', concat([$expectedPath, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithContainerBaseUrl()
    {
        $container = new Container();

        $container->set( 'baseUrl' , 'https://base.url' ) ;

        $result = aqlFieldUrl('url', 'doc', [ Field::PATH => '/foo/bar' ], $container ) ;

        $expected = "url:CONCAT('https://base.url/foo/bar','/',doc._key)";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithUndefinedPlaceholderFallback()
    {
        $path = '/foo/{missing}/bar';
        $args = ['other' => 'value'];

        $result = aqlFieldUrl('url', 'doc', [ Field::PATH => $path ], null, [Arango::ARGS => $args]);
        $expectedPath = '/foo/{missing}/bar'; // placeholder remains because not defined in args
        $expected = keyValue('url', concat([$expectedPath, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithCustomKeyName()
    {
        $path = '/my/path';
        $result = aqlFieldUrl('url', 'doc', [ Field::PATH => $path , Field::NAME => 'customKey' ]);
        $expected = keyValue('url', concat(['/my/path', Char::SLASH, key('customKey', 'doc')]));
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithEmptyPath()
    {
        $result = aqlFieldUrl('url', 'doc', []);
        $expected = keyValue('url', concat([Char::EMPTY, Char::SLASH, key('_key', 'doc')]));
        $this->assertSame($expected, $result);
    }

    // ---------------------------------------------------------------- Field::PATHS (discriminant routing)

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPaths()
    {
        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH  => '/thing' ,
            Field::PATHS => [ 'Place' => '/places' , 'Person' => '/people' ] ,
        ]);

        $expected = "url:CONCAT(TRANSLATE(doc.additionalType,{Place:'/places',Person:'/people'},'/thing'),'/',doc._key)";
        $this->assertSame($expected, $result);
    }

    /**
     * The discriminant default (Schema::ADDITIONAL_TYPE), the base URL pre-joining
     * per branch and the helper composition are all exercised here.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsWithBaseUrl()
    {
        $container = new Container();
        $container->set('baseUrl', 'https://base.url');

        $paths = [ 'Place' => '/places' , 'Person' => '/people' ];

        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH  => '/thing' ,
            Field::PATHS => $paths ,
        ], $container);

        $lookup = [ 'Place' => 'https://base.url/places' , 'Person' => 'https://base.url/people' ];
        $expected = keyValue('url', concat
        ([
            translate(key(Schema::ADDITIONAL_TYPE, 'doc'), aqlValue($lookup), aqlValue('https://base.url/thing')),
            Char::SLASH,
            key('_key', 'doc'),
        ]));

        $this->assertSame($expected, $result);
        $this->assertStringContainsString("'https://base.url/places'", $result);
        $this->assertStringContainsString("'https://base.url/thing'", $result); // fallback pre-joined too
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsWithCustomProperty()
    {
        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH     => '/thing' ,
            Field::PATHS    => [ 'Place' => '/places' ] ,
            Field::PROPERTY => 'kind' ,
        ]);

        $expected = "url:CONCAT(TRANSLATE(doc.kind,{Place:'/places'},'/thing'),'/',doc._key)";
        $this->assertSame($expected, $result);
    }

    /**
     * Placeholders are resolved in every branch and in the fallback alike.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsWithPlaceholders()
    {
        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH  => '/workspace/{workspace}/thing' ,
            Field::PATHS => [ 'Place' => '/workspace/{workspace}/places' ] ,
        ], null, [ Arango::ARGS => [ 'workspace' => '787878' ] ]);

        $expected = "url:CONCAT(TRANSLATE(doc.additionalType,{Place:'/workspace/787878/places'},'/workspace/787878/thing'),'/',doc._key)";
        $this->assertSame($expected, $result);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsThrowsWithoutDefault()
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('Field::PATHS requires an explicit Field::PATH fallback');

        aqlFieldUrl('url', 'doc', [ Field::PATHS => [ 'Place' => '/places' ] ]);
    }

    /**
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsThrowsWhenEmptyMap()
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('Field::PATHS must be a non-empty associative map');

        aqlFieldUrl('url', 'doc', [ Field::PATH => '/thing' , Field::PATHS => [] ]);
    }

    /**
     * A list (non-associative) array is rejected — keys are the discriminant values.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsThrowsWhenListMap()
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessage('Field::PATHS must be a non-empty associative map');

        aqlFieldUrl('url', 'doc', [ Field::PATH => '/thing' , Field::PATHS => [ '/a' , '/b' ] ]);
    }

    /**
     * The discriminant attribute flows into `doc.<attr>` and is validated against AQL injection.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testDiscriminantPathsThrowsOnInvalidProperty()
    {
        $this->expectException(ValidationException::class);

        aqlFieldUrl('url', 'doc',
        [
            Field::PATH     => '/thing' ,
            Field::PATHS    => [ 'Place' => '/places' ] ,
            Field::PROPERTY => 'foo; REMOVE doc IN col' ,
        ]);
    }
}
