<?php

namespace App\Domains\Tenancy\Enums;

/**
 * Whether a property is still being set up or has gone live (GLOSSARY: go-live).
 * In setup it can be configured and take test reservations, but not check guests in.
 */
enum PropertyStatus: string
{
    case Setup = 'setup';
    case Live = 'live';
}
