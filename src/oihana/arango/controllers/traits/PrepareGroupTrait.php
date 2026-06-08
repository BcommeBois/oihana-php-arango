<?php

namespace oihana\arango\controllers\traits;

use Psr\Http\Message\ServerRequestInterface as Request;

use oihana\arango\controllers\enums\GroupParam;
use oihana\arango\enums\Arango;
use oihana\arango\models\enums\Group;
use oihana\controllers\enums\ControllerParam;
use oihana\enums\Char;

use function oihana\controllers\helpers\getQueryParam;

/**
 * Prepares the high-level grouping spec ({@see Arango::GROUP}) from the HTTP
 * request, the controller counterpart of {@see \oihana\arango\models\traits\aql\GroupTrait}.
 *
 * Two query parameters feed it:
 * - `?group={...}` — the full JSON spec using the short {@see GroupParam} keys
 *   (`by` / `agg` / `count` / `sort` / `alt`), mapped onto the {@see Group} vocabulary.
 * - `?groupBy=field[,field]` — the CSV shortcut for `by`, which also implies a
 *   per-group count (the common faceted-list intent) unless the JSON spec already
 *   defines aggregates or a count.
 *
 * A predefined spec under `$args[Arango::GROUP]` is used as the base and merged.
 *
 * @package oihana\arango\controllers\traits
 * @since   1.0.0
 * @author  Marc Alcaraz
 */
trait PrepareGroupTrait
{
    /**
     * Resolves the {@see Arango::GROUP} spec for a list query.
     *
     * @param Request|null $request The HTTP request.
     * @param array        $args    Predefined options (`$args[Arango::GROUP]` as base).
     * @param array|null   $params  Echoed query params, populated by reference.
     *
     * @return array|null The Group spec, or null when no grouping is requested.
     */
    protected function prepareGroup( ?Request $request , array $args = [] , ?array &$params = null ) :?array
    {
        $group = $args[ Arango::GROUP ] ?? [] ;
        if ( !is_array( $group ) )
        {
            $group = [] ;
        }

        if ( isset( $request ) )
        {
            // ?group={...} — full JSON spec with short keys.
            $json = getQueryParam( $request , GroupParam::GROUP ) ;
            if ( is_string( $json ) && json_validate( $json ) )
            {
                $params[ GroupParam::GROUP ] = urlencode( $json ) ;
                $decoded = json_decode( $json , true ) ;
                if ( is_array( $decoded ) )
                {
                    $group = [ ...$group , ...$this->mapGroupParams( $decoded ) ] ;
                }
            }

            // ?groupBy=field[,field] — CSV shortcut, implies a per-group count.
            $by = getQueryParam( $request , ControllerParam::GROUP_BY ) ;
            if ( is_string( $by ) && $by !== Char::EMPTY )
            {
                $params[ ControllerParam::GROUP_BY ] = $by ;
                $group[ Group::BY ] = $by ;

                if ( !array_key_exists( Group::AGG , $group ) && !array_key_exists( Group::COUNT , $group ) )
                {
                    $group[ Group::COUNT ] = true ;
                }
            }
        }

        return empty( $group ) ? null : $group ;
    }

    /**
     * Maps the short HTTP {@see GroupParam} keys onto the {@see Group} vocabulary.
     *
     * @param array $decoded The decoded `?group=` JSON object.
     *
     * @return array The translated spec keyed by `Group::*`.
     */
    private function mapGroupParams( array $decoded ) :array
    {
        $map =
        [
            GroupParam::AGG   => Group::AGG ,
            GroupParam::ALT   => Group::ALT ,
            GroupParam::BY    => Group::BY ,
            GroupParam::COUNT => Group::COUNT ,
            GroupParam::SORT  => Group::SORT ,
        ] ;

        $out = [] ;
        foreach ( $decoded as $key => $value )
        {
            if ( isset( $map[ $key ] ) )
            {
                $out[ $map[ $key ] ] = $value ;
            }
        }

        return $out ;
    }
}
