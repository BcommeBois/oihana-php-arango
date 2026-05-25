<?php

namespace oihana\arango\clients\document ;

use org\schema\constants\Schema ;

/**
 * Immutable value object wrapping a single ArangoDB edge document.
 *
 * Extends {@see Document} with accessors for the two reserved edge
 * attributes (`_from`, `_to`), which point to the source and target
 * vertex identifiers respectively.
 *
 * Example:
 * ```php
 * $edge = new Edge
 * (
 *     [
 *         Schema::_KEY  => 'e1' ,
 *         Schema::_ID   => 'follows/e1' ,
 *         Schema::_FROM => 'users/alice' ,
 *         Schema::_TO   => 'users/bob'   ,
 *         'since'       => '2026-01-01'  ,
 *     ]
 * ) ;
 *
 * $edge->getFrom() ; // 'users/alice'
 * $edge->getTo()   ; // 'users/bob'
 * $edge->get( 'since' ) ; // '2026-01-01'
 * ```
 *
 * @package oihana\arango\clients\document
 * @author  Marc Alcaraz (ekameleon)
 * @since   1.0.0
 */
readonly class Edge extends Document
{
    /**
     * Returns the `_from` document identifier (source vertex), or null when not set.
     *
     * @return string|null
     */
    public function getFrom() : ?string
    {
        return isset( $this->data[ Schema::_FROM ] ) ? (string) $this->data[ Schema::_FROM ] : null ;
    }

    /**
     * Returns the `_to` document identifier (target vertex), or null when not set.
     *
     * @return string|null
     */
    public function getTo() : ?string
    {
        return isset( $this->data[ Schema::_TO ] ) ? (string) $this->data[ Schema::_TO ] : null ;
    }
}
