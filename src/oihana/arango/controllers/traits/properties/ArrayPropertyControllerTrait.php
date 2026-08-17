<?php

namespace oihana\arango\controllers\traits\properties;

use Exception;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\enums\Arango;
use oihana\arango\models\Documents;

use oihana\enums\http\HttpMethod;
use oihana\enums\http\HttpStatusCode;
use oihana\exceptions\UnsupportedOperationException;

use org\schema\constants\Schema;

use function oihana\core\accessors\getKeyValue;

/**
 * Element-level operations on an **embedded array property** of a document, exposed
 * by {@see ArrayPropertyController} as REST sub-resources.
 *
 * The host controller must be a {@see PropertyController}
 * subclass (it relies on its wiring: `$model`, `$property`, `$owner`, `assertProperty()`,
 * `checkOwnerArguments()`, `success()`, `fail()`, and — for {@see self::RESPOND_WITH_OWNER} —
 * `beforeModelCall()`, `afterModelCall()`, `prepareLang()`, `prepareSkin()`). The targeted
 * `$property` must be a field declared in the model's `AQL::ARRAYS` option.
 *
 * Each method maps an HTTP verb to a model array operation and returns a standardized
 * response. Common error responses (built by every method through {@see runArrayOp()}):
 *
 * - **400 Bad Request** — the configured property is not a declared array field.
 * - **404 Not Found** — the owner document does not exist (or, for {@see hasItem()},
 *   the value is not present in the array).
 * - **422 Unprocessable Entity** — the operation does not exist on that property: a
 *   {@see moveItem()} or {@see reorderItems()} on a `sortedSet`, an {@see updateItem()}
 *   or {@see reorderItems()} on a property declaring no item key. The rule is stated
 *   once, by the model, which raises an `UnsupportedOperationException`; the shared
 *   skeleton turns every one of them into this single status.
 *
 * The element value is resolved from the `{value}` route placeholder when present,
 * otherwise from the request body (key `value`) — use the body for **complex** (object)
 * values that cannot travel in a URL.
 *
 * When the property declares an `Arango::ITEM_KEY`, that value is the **key** of the
 * element instead, which is what makes an object addressable from a URL. The two
 * operations that target an existing element — {@see moveItem()} and {@see updateItem()} —
 * then answer **404** when no element carries it: the model guards both into a no-op
 * (nothing merged, nothing reordered), so the document they return is enough to tell,
 * without a second query.
 *
 * **What a write answers** is the whole array property, never the single element —
 * an element edit renumbers its neighbours, so the array is the only truthful answer.
 * A controller whose owner document carries values **derived from** the array —
 * totals, a count, a weight — may switch that answer to the owner document itself
 * with {@see self::RESPOND_WITH_OWNER}, and recompute those values from
 * {@see self::afterArrayWrite()}. The two go together : the hook exists so the
 * recomputation lands **before** the response is built, and the option exists so the
 * response can carry what the recomputation produced.
 *
 * @see ArrayPropertyController
 * @see DocumentsArrayTrait
 *
 * @package oihana\arango\controllers\traits\properties
 */
trait ArrayPropertyControllerTrait
{
    /**
     * The init key deciding what a write answers : the array property (default), or
     * the **owner document** it belongs to.
     *
     * 🔑 **Reach for it through the consuming class**, never through this trait —
     * `ArrayPropertyController::RESPOND_WITH_OWNER`. PHP 8.2+ refuses a trait
     * constant accessed directly.
     */
    public const string RESPOND_WITH_OWNER = 'respondWithOwner' ;

    /**
     * Whether a write answers the owner document rather than the array property.
     *
     * Declared by the route that mounts the controller, never by a client : it is the
     * shape of a contract, not a per-request preference.
     */
    public bool $respondWithOwner = false ;

    /**
     * Adds one or several values to the array property of a document.
     *
     * `POST /{collection}/{id}/{property}` — the value(s) are read from the request
     * body (key `value`); an optional `side` (`left`/`right`) controls the insertion end.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function addItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $document = $model->arrayInsert
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
                Arango::SIDE  => $init[ Arango::SIDE ] ?? $this->bodyParam( $request , Arango::SIDE ) ,
            ]) ;

            return $this->respondAfterWrite( $request , $response , $args , $init , $document ) ;
        }) ;
    }

    /**
     * Tests whether the array property of a document contains a value.
     *
     * `GET /{collection}/{id}/{property}/{value}` — the value is read from the `{value}`
     * placeholder (or the request body for complex values).
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed 200 when the value is present, 404 when it is absent (or 400/404 on guard failures).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function hasItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $exists = $model->arrayContains
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
            ]) ;

            return $exists
                 ? $this->success( $request , $response , true )
                 : $this->fail
                   (
                       request  : $request ,
                       response : $response ,
                       code     : HttpStatusCode::NOT_FOUND ,
                       details  : 'The value is not present in the array.' ,
                   ) ;
        }) ;
    }

    /**
     * Reads {@see self::RESPOND_WITH_OWNER} off the init, deciding what a write answers.
     *
     * @param array $init The controller init.
     *
     * @return static
     */
    public function initializeRespondWithOwner( array $init = [] ) :static
    {
        $this->respondWithOwner = (bool) ( $init[ self::RESPOND_WITH_OWNER ] ?? $this->respondWithOwner ) ;
        return $this ;
    }

    /**
     * Moves an existing value to a given position in the array property.
     *
     * `PATCH /{collection}/{id}/{property}/{value}` — the value comes from the `{value}`
     * placeholder (or body), the target index from the request body (key `position`).
     * Unsupported on a `sortedSet` property (the sort order overrides positions) → 422.
     *
     * On a property declaring an item key, `{value}` is that key and an unknown one
     * answers **404** — the model rewrites the array unchanged rather than inserting a
     * null, and the returned document carries the proof.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404/422).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function moveItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $value = $this->resolveItemValue( $request , $args ) ;

            $document = $model->arrayMove
            ([
                ...$init ,
                Arango::OWNER    => $owner ,
                Arango::FIELD    => $this->property ,
                Arango::VALUE    => $value ,
                Arango::POSITION => (int) ( $init[ Arango::POSITION ] ?? $this->bodyParam( $request , Arango::POSITION ) ?? 0 ) ,
            ]) ;

            return $this->respondWithItem( $request , $response , $args , $init , $document , $this->resolveItemKey( $model , $init ) , $value ) ;
        }) ;
    }

    /**
     * Removes one or several values from the array property of a document.
     *
     * `DELETE /{collection}/{id}/{property}/{value}` — the value comes from the `{value}`
     * placeholder (or the request body for complex values).
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function removeItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $document = $model->arrayRemove
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
            ]) ;

            return $this->respondAfterWrite( $request , $response , $args , $init , $document ) ;
        }) ;
    }

    /**
     * Reorders the array property from a list of item keys — the whole new order in a
     * single request, where {@see moveItem()} moves one element at a time.
     *
     * `PUT /{collection}/{id}/{property}` — the ordered keys are read from the request
     * body (key `value`), like {@see addItem()}, the other operation that targets the
     * property rather than one of its elements.
     *
     * A partial list reorders what it names and **keeps** the rest, appended after it;
     * unknown keys are skipped and an empty list changes nothing — a reorder never
     * deletes. Requires the property to declare an `Arango::ITEM_KEY`, and is
     * unsupported on a `sortedSet` property → **422** in both cases.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404/422).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function reorderItems( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $document = $model->arrayReorder
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $this->resolveItemValue( $request , $args ) ,
            ]) ;

            return $this->respondAfterWrite( $request , $response , $args , $init , $document ) ;
        }) ;
    }

    /**
     * Merges a partial patch into the element of the array property carrying the given
     * item key — an **in-place edit**, where {@see moveItem()} only reorders and
     * {@see removeItem()} only drops.
     *
     * `PUT /{collection}/{id}/{property}/{value}` — `{value}` is the item key, and the
     * **request body is the patch itself** (`{"rating":5}`, no envelope): the verb already
     * says the element is being edited, so nothing has to name it again. The merge is
     * partial — the attributes it carries overwrite theirs, the others are kept.
     *
     * Requires the property to declare an `Arango::ITEM_KEY` (or to receive one through
     * `$init`) → **422** otherwise: without a key an element could only be designated by
     * a byte-for-byte copy of itself, which the patch being applied invalidates. An
     * unknown key answers **404**.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`, `value`).
     * @param array $init Optional initialization options.
     *
     * @return mixed The updated array property on success (200), or an error response (400/404/422).
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateItem( ?Request $request = null , ?Response $response = null , array $args = [] , array $init = [] ) : mixed
    {
        return $this->runArrayOp( $request , $response , $args , $init , function( mixed $owner , Documents $model , array $init ) use ( $request , $response , $args )
        {
            $itemKey = $this->resolveItemKey( $model , $init ) ;
            $value   = $this->resolveItemValue( $request , $args ) ;

            $document = $model->arrayUpdate
            ([
                ...$init ,
                Arango::OWNER => $owner ,
                Arango::FIELD => $this->property ,
                Arango::VALUE => $value ,
                Arango::PATCH => $init[ Arango::PATCH ] ?? $request?->getParsedBody() ?? [] ,
            ]) ;

            return $this->respondWithItem( $request , $response , $args , $init , $document , $itemKey , $value ) ;
        }) ;
    }

    /**
     * Runs after an array write has touched the document, and **before** the response
     * is built. A no-op here, for a subclass to override.
     *
     * 🔑 **This is the seam the six operations lacked.** {@see ModelCallTrait::afterModelCall()}
     * is deliberately not invoked by {@see self::runArrayOp()} — the operations answer a
     * response rather than a document, so it would have no consistent result to receive.
     * This hook has one : the document the write returned.
     *
     * ⚠️ **It runs before the response, which is the whole point.** A controller whose
     * owner document carries values derived from the array — totals, a count, a weight —
     * recomputes them here, so that a response carrying the owner
     * ({@see self::RESPOND_WITH_OWNER}) states what the write really produced rather than
     * what stood one write ago.
     *
     * It does **not** run when the operation answered a failure : an item key matching no
     * element ({@see self::respondWithItem()}) touched nothing, so there is nothing to
     * recompute.
     *
     * 🚨 **The document it receives is the raw `RETURN NEW`** — hydrated by the model's
     * alters, but never passed through `AQL::FIELDS`. Read it for what the write changed ;
     * never hand it back as a response. That is what the reload behind
     * {@see self::RESPOND_WITH_OWNER} exists for.
     *
     * @param ?Request $request The current PSR-7 request (null in CLI / test contexts).
     * @param array $args Route placeholders (`id`).
     * @param array $init The enriched init of the operation.
     * @param ?object $document The document the write returned, or null when it matched nothing.
     *
     * @return void
     */
    protected function afterArrayWrite( ?Request $request , array $args , array $init , ?object $document ) : void
    {
        // no-op
    }

    /**
     * Reads a single parameter from the parsed request body.
     *
     * @param ?Request $request
     * @param string   $key
     *
     * @return mixed The body value, or null when absent.
     */
    protected function bodyParam( ?Request $request , string $key ) : mixed
    {
        return ( (array) ( $request?->getParsedBody() ?? [] ) )[ $key ] ?? null ;
    }

    /**
     * Resolves the item key of the array property — the attribute carried by each element
     * that identifies it — honouring an `$init` override then the model configuration.
     *
     * Mirrors the model's own resolution, so the controller and the query it triggers
     * always agree on what `{value}` designates. A null result means the property is
     * targeted **by value**.
     *
     * @param Documents $model
     * @param array     $init
     *
     * @return string|null
     */
    protected function resolveItemKey( Documents $model , array $init = [] ) : ?string
    {
        return $init[ Arango::ITEM_KEY ] ?? $model->arrays[ $this->property ][ Arango::ITEM_KEY ] ?? null ;
    }

    /**
     * Resolves the array element value from the `{value}` route placeholder, falling
     * back to the request body (key `value`) for complex values that cannot be in a URL.
     *
     * @param ?Request $request
     * @param array    $args
     *
     * @return mixed
     */
    protected function resolveItemValue( ?Request $request , array $args ) : mixed
    {
        return $args[ Arango::VALUE ] ?? $this->bodyParam( $request , Arango::VALUE ) ;
    }

    /**
     * Tells whether one of the given elements carries `value` under the `itemKey`
     * attribute (a dotted path is supported, like the model side).
     *
     * The comparison is **strict**, which is what AQL's `==` does on a document
     * attribute: a numeric key requested as the string `"1"` matches nothing there
     * either, so both sides agree on what « found » means.
     *
     * @param mixed  $items   The array property as returned by the write.
     * @param string $itemKey The identifying attribute.
     * @param mixed  $value   The requested key.
     *
     * @return bool
     */
    private function containsItemKey( mixed $items , string $itemKey , mixed $value ) : bool
    {
        if ( !is_array( $items ) )
        {
            return false ;
        }

        foreach ( $items as $item )
        {
            // A scalar element carries no attribute at all: it can never match a key.
            if ( ( is_array( $item ) || is_object( $item ) ) && getKeyValue( $item , $itemKey ) === $value )
            {
                return true ;
            }
        }

        return false ;
    }

    /**
     * Re-reads the owner document a write has just changed, **through the projection**.
     *
     * 🚨 **The document a write returns is not the one a `GET` serves**, and handing it
     * back would be a quiet lie. An array write ends on `RETURN NEW` : the stored
     * document, hydrated by the model's alters, but never passed through `AQL::FIELDS`.
     * It therefore carries no rebuilt `url`, ignores `Filter::TRANSLATE`, exposes stored
     * attributes the projection filters out, and — the one that bites — **walks past the
     * `Field::REQUIRES` gates**. That last failure is not hypothetical : it is the very
     * incident {@see ReloadWrittenDocumentTrait} was written for, on the document writes.
     *
     * So the owner is read again, the way {@see PropertyControllerGetTrait::get()} reads
     * it : `beforeModelCall()` poses the request-scoped authorizer and whatever scope a
     * subclass adds, the model projects, `afterModelCall()` post-processes. **The answer
     * is identical to a `GET` by construction, because it is the same call.**
     *
     * ⚠️ **The skin is the one this controller's own `get()` would use** — not a fixed
     * one. A surface serving its array only in a wider skin must declare that skin, or
     * the response will come back without the very property that was just written.
     *
     * @param ?Request $request
     * @param array $args Route placeholders (`id`).
     * @param array $init The enriched init of the operation.
     *
     * @return object|null The projected owner document, or null when it reads back as nothing.
     */
    private function reloadOwner( ?Request $request , array $args , array $init ) : ?object
    {
        $modelInit =
        [
            Arango::ARGS       => $args ,
            Arango::VALUE      => $args[ Arango::ID ] ?? null ,
            Arango::CONDITIONS => $init[ Arango::CONDITIONS ] ?? [] ,
            Arango::KEY        => $init[ Arango::KEY ] ?? Schema::_KEY ,
            Arango::LANG       => $this->prepareLang( $request , $init ) ,
            Arango::SKIN       => $this->prepareSkin( $request , $init , method: HttpMethod::get ) ,
        ] ;

        $this->beforeModelCall( $request , $modelInit ) ;
        $document = $this->model->get( $modelInit ) ;
        $this->afterModelCall( $request , $modelInit , $document ) ;

        return is_object( $document ) ? $document : null ;
    }

    /**
     * Builds the response of every array write : the hook first, the body second.
     *
     * The order is the reason this method exists. {@see self::afterArrayWrite()} may
     * write to the owner document — recomputed totals, a refreshed count — and a
     * response built before it would state the values of one write ago. Every write of
     * this trait therefore ends here, and nowhere else.
     *
     * Two shapes, decided once by the route rather than per request :
     *
     * - by default, **the array property** — what an element write has always answered ;
     * - under {@see self::RESPOND_WITH_OWNER}, **the owner document**, re-read through
     *   the projection ({@see self::reloadOwner()}). One rule then holds across the
     *   surface : a write answers the new truth of the whole document, exactly as the
     *   document `PATCH` already does.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`).
     * @param array $init The enriched init of the operation.
     * @param ?object $document The document the write returned (`RETURN NEW`).
     *
     * @return mixed
     */
    private function respondAfterWrite( ?Request $request , ?Response $response , array $args , array $init , ?object $document ) : mixed
    {
        $this->afterArrayWrite( $request , $args , $init , $document ) ;

        return $this->success
        (
            $request ,
            $response ,
            $this->respondWithOwner
                ? $this->reloadOwner( $request , $args , $init )
                : $document?->{ $this->property } ?? null
        ) ;
    }

    /**
     * Builds the response of an operation targeting an **existing** element: the updated
     * array property, or a 404 when no element carries the requested item key.
     *
     * The write has already run — it is guarded into a no-op on both sides (nothing
     * merged by `arrayUpdate()`, nothing reordered by `arrayMove()`) — so the document it
     * returned is enough to tell, at no extra query cost. A property targeted **by value**
     * passes a null `itemKey` and skips the check entirely.
     *
     * 🔑 **The 404 is decided before {@see self::respondAfterWrite()} is reached**, so a
     * key matching nothing neither fires {@see self::afterArrayWrite()} nor reloads the
     * owner. The write touched no element : there is nothing to recompute, and nothing
     * to read back.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args Route placeholders (`id`).
     * @param array $init The enriched init of the operation.
     * @param ?object $document The document returned by the write (`RETURN NEW`).
     * @param ?string $itemKey The resolved item key, or null when the property is targeted by value.
     * @param mixed $value The requested item key.
     *
     * @return mixed
     */
    private function respondWithItem( ?Request $request , ?Response $response , array $args , array $init , ?object $document , ?string $itemKey , mixed $value ) : mixed
    {
        if ( $itemKey !== null && !$this->containsItemKey( $document?->{ $this->property } ?? null , $itemKey , $value ) )
        {
            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : HttpStatusCode::NOT_FOUND ,
                details  : sprintf( 'No element of the property "%s" carries the requested item key.' , $this->property ?? 'undefined' ) ,
            ) ;
        }

        return $this->respondAfterWrite( $request , $response , $args , $init , $document ) ;
    }

    /**
     * Shared skeleton for the array operations: asserts the property is configured and
     * declared as an array field, enriches the init through
     * {@see \oihana\controllers\traits\ModelCallTrait::beforeModelCall()}, verifies the
     * owner document exists, then runs the given operation. Maps thrown exceptions to a
     * standardized failure response.
     *
     * **The existence guard is the gate.** The array queries build their own `FILTER`
     * and do not read `Arango::CONDITIONS` — enriching their init would change nothing.
     * `exist()` does read it ({@see \oihana\arango\models\traits\queries\ExistQueryTrait}),
     * so an owner document outside the scope answers 404 here and the operation is never
     * reached. That is why the guard runs for **every** operation, reads included: a
     * membership answer on a document the caller may not see is itself a disclosure.
     *
     * The enriched init is handed to the operation as its third argument rather than
     * captured by the closure — a closure created at the call site captures `$init` by
     * value *before* this method runs, so a captured copy would never see the enrichment.
     *
     * `afterModelCall()` is deliberately not invoked here: the operations return a
     * response, not a document, so the hook would have no consistent result to receive.
     * Post-processing a read belongs to {@see PropertyControllerGetTrait::get()}.
     *
     * @param ?Request $request
     * @param ?Response $response
     * @param array $args
     * @param array $init
     * @param callable $operation fn(mixed $owner, Documents $model, array $init): mixed — performs the model call and returns the response.
     *
     * @return mixed
     *
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function runArrayOp( ?Request $request , ?Response $response , array $args , array $init , callable $operation ) : mixed
    {
        try
        {
            $this->assertProperty() ;
            $this->checkOwnerArguments( $args ) ;

            /** @var Documents $model The configured array-capable model (declares the array* methods and the `arrays` config). */
            $model = $this->model ;

            if ( !is_array( $model->arrays[ $this->property ] ?? null ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::BAD_REQUEST ,
                    details  : sprintf( 'The property "%s" is not a declared array field.' , $this->property ?? 'undefined' ) ,
                ) ;
            }

            $owner = $args[ Schema::ID ] ?? null ;

            // The single seam every array operation passes through: enriching the
            // init here is what puts the six of them behind the same scope as
            // get() and patch(). It must run *before* the existence guard — that
            // guard is where a scope actually bites (see the method docblock).
            $this->beforeModelCall( $request , $init ) ;

            if ( !$model->exist( [ ...$init , Arango::VALUE => $owner ] ) )
            {
                return $this->fail
                (
                    request  : $request ,
                    response : $response ,
                    code     : HttpStatusCode::NOT_FOUND ,
                    details  : sprintf( 'The document "%s" does not exist' , $owner ?? 'undefined' ) ,
                ) ;
            }

            return $operation( $owner , $model , $init ) ;
        }
        catch ( Exception $e )
        {
            // "This operation does not exist on this field" is a request the property
            // cannot satisfy, not a server failure: the model states the rule once, and
            // every operation refusing it answers alike.
            $code = $e instanceof UnsupportedOperationException
                  ? HttpStatusCode::UNPROCESSABLE_ENTITY
                  : HttpStatusCode::fromException( $e ) ;

            return $this->fail
            (
                request  : $request ,
                response : $response ,
                code     : $code ,
                details  : $e->getMessage() ,
            ) ;
        }
    }
}
