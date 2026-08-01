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

use oihana\arango\db\enums\AQL;

use function oihana\arango\db\functions\documents\translate;
use function oihana\arango\db\functions\toBool;
use function oihana\arango\db\helpers\aqlValue;
use function oihana\arango\db\helpers\fields\aqlFieldUrl;
use function oihana\arango\db\functions\strings\concat;
use function oihana\arango\db\operators\ternary;
use function oihana\core\strings\betweenQuotes;
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
        $this->expectExceptionMessageIsOrContains('Field::PATHS requires an explicit Field::PATH fallback');

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
        $this->expectExceptionMessageIsOrContains('Field::PATHS must be a non-empty associative map');

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
        $this->expectExceptionMessageIsOrContains('Field::PATHS must be a non-empty associative map');

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

    // ---------------------------------------------------------------- Field::WHEN (conditional guard)

    /**
     * AQL drops null arguments from a CONCAT(), so a document with no key comes back with a
     * truncated link instead of no link at all. The guard lets the field abstain — and a
     * one-element leaf is a truthiness test, so an empty key abstains too.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenGuardsTheSimpleForm()
    {
        $result = aqlFieldUrl('url', 'doc', [ Field::PATH => '/things' , Field::WHEN => [ '_key' ] ]);

        $expected = keyValue('url', ternary
        (
            toBool(key('_key', 'doc')),
            concat(['/things', Char::SLASH, key('_key', 'doc')]),
            AQL::NULL
        ));

        $this->assertSame($expected, $result);
        $this->assertSame("url:TO_BOOL(doc._key) ? CONCAT('/things','/',doc._key) : null", $result);
    }

    /**
     * The guard carries the whole CONCAT(), discriminant routing included.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenGuardsTheDiscriminantForm()
    {
        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH  => '/thing' ,
            Field::PATHS => [ 'Place' => '/places' ] ,
            Field::WHEN  => [ '_key' ] ,
        ]);

        $expected = "url:TO_BOOL(doc._key) ? CONCAT(TRANSLATE(doc.additionalType,{Place:'/places'},'/thing'),'/',doc._key) : null";
        $this->assertSame($expected, $result);
    }

    /**
     * The two shapes of a fallback branch: an inlined literal and a sibling attribute.
     *
     * ⚠ A literal holding a slash looks like an AQL expression to aqlValue(), which leaves it
     * raw — `Field::ELSE => 'N/A'` would break the query. It is declared with betweenQuotes().
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenComposesWithElse()
    {
        $literal = aqlFieldUrl('url', 'doc',
        [
            Field::PATH => '/things' ,
            Field::WHEN => [ '_key' ] ,
            Field::ELSE => betweenQuotes('N/A') ,
        ]);

        $this->assertSame("url:TO_BOOL(doc._key) ? CONCAT('/things','/',doc._key) : 'N/A'", $literal);

        $property = aqlFieldUrl('url', 'doc',
        [
            Field::PATH => '/things' ,
            Field::WHEN => [ '_key' ] ,
            Field::ELSE => [ Field::PROPERTY => 'fallbackUrl' ] ,
        ]);

        $this->assertSame("url:TO_BOOL(doc._key) ? CONCAT('/things','/',doc._key) : doc.fallbackUrl", $property);
    }

    /**
     * On this filter Field::NAME renames the appended *key*, not the source attribute — the
     * condition must be able to name that same attribute.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenReadsTheCustomKeyName()
    {
        $result = aqlFieldUrl('url', 'doc',
        [
            Field::PATH => '/things' ,
            Field::NAME => 'customKey' ,
            Field::WHEN => [ 'customKey' ] ,
        ]);

        $this->assertSame("url:TO_BOOL(doc.customKey) ? CONCAT('/things','/',doc.customKey) : null", $result);
    }

    /**
     * The condition is compiled against the reference the projection itself reads from. Inside
     * a sub-document projection that reference is the sub-document, so a discriminant carried
     * by the embedded copy — the case this guard exists for — is read from it, not from the
     * parent.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenIsCompiledAgainstTheProjectionReference()
    {
        $result = aqlFieldUrl('url', 'doc.thing', [ Field::PATH => '/things' , Field::WHEN => [ 'additionalType' , 'Place' ] ]);

        $this->assertSame("url:doc.thing.additionalType == 'Place' ? CONCAT('/things','/',doc.thing._key) : null", $result);
    }

    /**
     * Backward compatibility: without the marker the emitted AQL is the one from before, byte
     * for byte — no ternary, no test, not one extra space. Both branches are pinned.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWithoutMarkerTheEmittedAqlIsUnchanged()
    {
        $simple = aqlFieldUrl('url', 'doc', [ Field::PATH => '/things' ]);
        $this->assertSame("url:CONCAT('/things','/',doc._key)", $simple);

        $routed = aqlFieldUrl('url', 'doc', [ Field::PATH => '/thing' , Field::PATHS => [ 'Place' => '/places' ] ]);
        $this->assertSame("url:CONCAT(TRANSLATE(doc.additionalType,{Place:'/places'},'/thing'),'/',doc._key)", $routed);
    }

    /**
     * A malformed condition fails loudly rather than emitting a guard that lets everything through.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testMalformedWhenThrows()
    {
        $this->expectException(UnsupportedOperationException::class);
        aqlFieldUrl('url', 'doc', [ Field::PATH => '/things' , Field::WHEN => [] ]);
    }

    /**
     * The condition attribute flows into `doc.<attr>` and is validated against AQL injection.
     *
     * @throws UnsupportedOperationException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ValidationException
     */
    public function testWhenThrowsOnUnsafeAttribute()
    {
        $this->expectException(ValidationException::class);
        aqlFieldUrl('url', 'doc', [ Field::PATH => '/things' , Field::WHEN => [ 'foo; REMOVE doc IN col' ] ]);
    }
}
