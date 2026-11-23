<?php

namespace App\Domains\Tenancy\Enums;

/**
 * Whether a property is still being set up or has gone live (GLOSSARY: go-live).
 * Setup normally blocks check-in; explicit local practice permits test stays
 * without changing this status or enabling integration delivery.
 */
enum PropertyStatus: string
{
    case Setup = 'setup';
    case Live = 'live';
}
