<?php

namespace oihana\arango\routes;

use oihana\routes\http\GetRoute;

/**
 * Declares a single read-only `GET` route bound to a controller's `search`
 * action — a generic search endpoint, reusable by any controller exposing a
 * `search()` method (e.g. {@see \oihana\arango\controllers\FederatedSearchController}).
 *
 * Given a `route` of `/search`, it registers:
 *
 * | Verb  | Path      | Controller method |
 * |-------|-----------|-------------------|
 * | `GET` | `/search` | `search`          |
 *
 * It is a thin {@see GetRoute} that only redefines the default controller
 * method, so the controller / method resolution, the container guard and the
 * Slim registration are inherited verbatim. The bound method can still be
 * overridden through {@see GetRoute::METHOD}.
 *
 * ```php
 * // definitions/routes.php
 * Routes::SEARCH => fn( Container $c ) => new SearchRoute( $c,
 * [
 *     Route::CONTROLLER_ID => Controllers::SEARCH , // any controller with a search() action
 *     Route::ROUTE         => '/search'           ,
 * ]) ,
 * ```
 *
 * @package oihana\arango\routes
 *
 * @see \oihana\arango\controllers\FederatedSearchController
 */
class SearchRoute extends GetRoute
{
    /**
     * By convention, a search route calls the `search` method on the
     * controller, unless specified otherwise in `$init`.
     */
    public const string INTERNAL_METHOD = 'search' ;
}
