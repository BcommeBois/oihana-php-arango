<?php

declare(strict_types=1);

namespace tests\oihana\arango\controllers\traits;

use DI\DependencyException;
use DI\NotFoundException;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\enums\AQLType;
use oihana\arango\controllers\traits\PayloadsTrait;
use oihana\arango\enums\Arango;
use oihana\enums\FilterOption;
use oihana\enums\http\HttpMethod;

use org\schema\constants\Prop;

class PayloadsTraitSub
{
    use PayloadsTrait;

    public function __construct()
    {
        $this->languages = ['fr', 'en'];
        $this->path      = 'places';
        $this->fullPath  = '/places';
    }
}

#[CoversTrait(PayloadsTrait::class)]
class PayloadsTraitTest extends TestCase
{
    // -------------------- propertyPayload --------------------

    public function testPropertyPayloadReturnsNullWhenPropertyIsEmpty(): void
    {
        $subject = new PayloadsTraitSub();

        $request = $this->stubRequest() ;

        $result = $subject->propertyPayload( $request , null ) ;

        $this->assertNull($result);
    }

    public function testPropertyPayloadReturnsWholeBodyWhenPayloadIsNotSimple(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['name' => 'Test', 'age' => 30]);

        // Set a complex payload (with HttpMethod keys)
        $subject->payload = [
            HttpMethod::ALL => [ Prop::NAME => [ Arango::TYPE => AQLType::STRING ] ]
        ];

        $result = $subject->propertyPayload($request, 'data');

        $this->assertSame(['data' => ['name' => 'Test', 'age' => 30]], $result);
    }

    public function testPropertyPayloadHandlesStringTypeDefinition(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['description' => 'A nice place']);

        // Set payload as simple string type
        $subject->payload = AQLType::STRING;

        $result = $subject->propertyPayload($request, 'description');

        $this->assertSame(['description' => 'A nice place'], $result);
    }

    public function testPropertyPayloadReturnsValueWhenDefinitionHasValue(): void
    {
        $subject = new PayloadsTraitSub();
        // Create a stub since getParsedBody won't be called
        $request = $this->stubRequest();

        // Set payload with a fixed VALUE
        $subject->payload = [ Arango::VALUE => 'fixed-value' ];

        $result = $subject->propertyPayload($request, 'status');

        $this->assertSame('fixed-value', $result);
    }

    public function testPropertyPayloadExtractsStringFromBody(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['title' => 'My Title']);

        $subject->payload = [ Arango::TYPE => AQLType::STRING ];

        $result = $subject->propertyPayload($request, 'title');

        $this->assertSame(['title' => 'My Title'], $result);
    }

    public function testPropertyPayloadExtractsIntegerFromBody(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['count' => '42']);

        $subject->payload = [ Arango::TYPE => AQLType::INT ];

        $result = $subject->propertyPayload($request, 'count');

        $this->assertSame(['count' => 42], $result);
    }

    public function testPropertyPayloadExtractsBooleanFromBody(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['active' => 'true']);

        $subject->payload = [ Arango::TYPE => AQLType::BOOL ];

        $result = $subject->propertyPayload($request, 'active');

        $this->assertSame(['active' => true], $result);
    }

    public function testPropertyPayloadExtractsFloatFromBody(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['price' => '19.99']);

        $subject->payload = [ Arango::TYPE => AQLType::FLOAT ];

        $result = $subject->propertyPayload($request, 'price');

        $this->assertSame(['price' => 19.99], $result);
    }

    public function testPropertyPayloadExtractsArrayFromBody(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['tags' => ['php', 'testing']]);

        $subject->payload = [ Arango::TYPE => AQLType::ARRAY ];

        $result = $subject->propertyPayload($request, 'tags');

        $this->assertSame(['tags' => ['php', 'testing']], $result);
    }

    public function testPropertyPayloadFiltersI18nLanguages(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest([
            'description' => [
                'fr' => 'Bonjour',
                'en' => 'Hello',
                'de' => 'Hallo', // Should be filtered out
                'es' => 'Hola'   // Should be filtered out
            ]
        ]);

        $subject->payload = [ Arango::TYPE => AQLType::I18N ];

        $result = $subject->propertyPayload($request, 'description');

        $this->assertSame([
            'description' => [
                'fr' => 'Bonjour',
                'en' => 'Hello'
            ]
        ], $result);
    }

    public function testPropertyPayloadUsesDefaultValueWhenDataMissing(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest([]); // Empty body

        $subject->payload = [
            Arango::TYPE    => AQLType::STRING,
            Arango::DEFAULT => 'default-value'
        ];

        $result = $subject->propertyPayload($request, 'name');

        $this->assertSame(['name' => 'default-value'], $result);
    }

    public function testPropertyPayloadClipsIntWithRange(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['score' => 150]);

        $subject->payload =
        [
            Arango::TYPE => AQLType::INT_WITH_RANGE,
            FilterOption::MIN_RANGE => 0,
            FilterOption::MAX_RANGE => 100
        ];

        $result = $subject->propertyPayload($request, 'score');

        $this->assertSame(['score' => 100], $result); // Clipped to max
    }

    public function testPropertyPayloadClipsFloatWithRange(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['rating' => -5.5]);

        $subject->payload =
        [
            Arango::TYPE => AQLType::FLOAT_WITH_RANGE,
            FilterOption::MIN_RANGE => 0.0,
            FilterOption::MAX_RANGE => 5.0
        ];

        $result = $subject->propertyPayload($request, 'rating');

        $this->assertSame(['rating' => 0.0], $result); // Clipped to min
    }

    public function testPropertyPayloadRegistersEdgeInRelations(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['category' => 'categories/123']);

        $subject->payload =
        [
            Arango::TYPE => AQLType::EDGE,
            'collection' => 'categories'
        ];

        $relations = [];
        $result = $subject->propertyPayload($request, 'category', $relations);

        $this->assertSame(['category' => 'categories/123'], $result);
        $this->assertArrayHasKey('category', $relations);
        $this->assertSame('categories/123', $relations['category'][Arango::VALUE]);
        $this->assertSame('categories', $relations['category']['collection']);
    }

    // -------------------- initializePayloads --------------------

    public function testInitializePayloadsOverridesWhenProvided(): void
    {
        $subject = new PayloadsTraitSub();

        $this->assertSame( [] , $subject->payload ) ; // default empty

        $init =
        [
            Arango::PAYLOAD =>
            [
                HttpMethod::ALL => [ Prop::NAME => [ Arango::TYPE => AQLType::STRING ] ],
            ]
        ];

        $subject->initializePayload($init);

        $this->assertArrayHasKey(HttpMethod::ALL, $subject->payload);
        $this->assertArrayHasKey(Prop::NAME, $subject->payload[HttpMethod::ALL]);

        // calling with unrelated init keeps previous payload

        $subject->initializePayload(['foo' => 'bar']);

        $this->assertArrayHasKey(HttpMethod::ALL, $subject->payload);
    }

    // -------------------- preparePayload --------------------

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testPreparePayloadFallsBackToBodyWhenNoDefinitions(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['x' => 1, 'y' => 'z']);

        $doc = $subject->preparePayload($request, HttpMethod::POST, [ Arango::PAYLOAD => [] ] );
        $this->assertSame(['x' => 1, 'y' => 'z'], $doc);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testPreparePayloadMergesAllAndMethodAndTopLevelCompressTrue(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest([
            'name' => 'Café',
            'active' => null, // will be removed by compress
        ]);

        $init = [
            Arango::PAYLOAD =>
            [
                Arango::COMPRESS => true,
                HttpMethod::ALL  =>
                [
                    Prop::NAME => [ Arango::TYPE => AQLType::STRING ],
                    'active'   => [ Arango::TYPE => AQLType::BOOL   ],
                ],
                HttpMethod::POST => [
                    Prop::IDENTIFIER => [ Arango::VALUE => 'fixed-id' ],
                ],
            ],
        ];

        $relations = [];
        $doc = $subject->preparePayload($request, HttpMethod::POST, $init, $relations);

        $this->assertSame([
            Prop::NAME => 'Café',
            Prop::IDENTIFIER => 'fixed-id',
        ], $doc, 'compressed should drop null active and keep provided values');
        $this->assertSame([], $relations);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testPreparePayloadTopLevelCompressOnlyForSelectedMethods(): void
    {
        $subject = new PayloadsTraitSub();
        $request = $this->stubRequest(['a' => null, 'b' => 'ok']);

        $init =
        [
            Arango::PAYLOAD =>
            [
                Arango::COMPRESS => [ HttpMethod::PATCH ],
                HttpMethod::ALL  =>
                [
                    'a' => [ Arango::TYPE => AQLType::STRING ],
                    'b' => [ Arango::TYPE => AQLType::STRING ],
                ],
            ],
        ];

        $relations = [];
        $docPatch = $subject->preparePayload($request, HttpMethod::PATCH, $init, $relations);
        $this->assertSame(['b' => 'ok'], $docPatch, 'PATCH should compress');

        $relations = [];
        $docPut = $subject->preparePayload($request, HttpMethod::PUT, $init, $relations);
        $this->assertSame(['a' => null, 'b' => 'ok'], $docPut, 'PUT should NOT compress');
    }

    // -------------------- generatePayload --------------------

    /**
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function testGeneratePayloadHandlesCoreTypesAndNestedDocumentCompressionAndNameRemapping(): void
    {
        $subject = new PayloadsTraitSub();

        $request = $this->stubRequest
        ([
            'name'    => 'Béhuard',
            'age'     => 42,
            'ok'      => true,
            'i18n'    => [ 'fr' => 'Bonjour', 'en' => 'Hello', 'de' => 'Hallo' ],
            'address' =>
            [
                'street' => null, // should be removed if compressed
                'city'   => 'Angers',
            ],
        ]);

        $relations = [];
        $defs =
        [
            Prop::NAME    => [ Arango::TYPE => AQLType::STRING ],
            'age'         => [ Arango::TYPE => AQLType::INT ],
            'ok'          => [ Arango::TYPE => AQLType::BOOL ],
            'i18n'        => [ Arango::TYPE => AQLType::I18N, Arango::NAME => 'i18n' ],
            Prop::ADDRESS =>
            [
                Arango::TYPE     => AQLType::PAYLOAD ,
                Arango::COMPRESS => true,
                Arango::PAYLOAD  =>
                [
                    'street' => [ Arango::TYPE => AQLType::STRING, Arango::NAME => 'address.street' ],
                    'city'   => [ Arango::TYPE => AQLType::STRING, Arango::NAME => 'address.city' ],
                ],
            ],
        ];

        $doc = $subject->generatePayload( $request , $defs , [] , $relations);

        $this->assertSame
        ([
            Prop::NAME    => 'Béhuard' ,
            'age'         => 42,
            'ok'          => true,
            'i18n'        => ['fr' => 'Bonjour', 'en' => 'Hello'] , // filtered languages
            Prop::ADDRESS => [ 'city' => 'Angers' ] , // compressed -> street removed
        ]
        , $doc );

        $this->assertSame( [] , $relations ) ;
    }

    // -------------------- helpers --------------------

    /**
     * Create a Request stub that returns the given body for getParsedBody().
     * Use this when you don't need to verify method calls.
     */
    private function stubRequest( null|array|object $body = null ): Request
    {
        $req = $this->createStub(Request::class);
        $req->method('getParsedBody')->willReturn($body);
        return $req;
    }
}
