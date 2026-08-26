<?php

declare(strict_types=1);
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Trusted Proxy Configuration
|--------------------------------------------------------------------------
|
| Trusted proxy IP addresses (comma-separated) or null to trust none.
| In production behind a load balancer or reverse proxy, set this to the
| proxy IP addresses (or CIDR ranges) that should be trusted for
| X-Forwarded-* headers.
|
| Set to '*' only in trusted environments (e.g. cloud behind single LB).
|
| Examples:
|   TRUSTED_PROXIES=10.0.0.1,172.16.0.0/12
|   TRUSTED_PROXIES=*
|
*/

return [
    /*
    |------------------------------------------------------------------
    | Trusted Proxies
    |------------------------------------------------------------------
    |
    | IP addresses (or CIDR ranges) of proxies that should be trusted
    | for X-Forwarded-For, X-Forwarded-Proto, etc. headers.
    |
    | Set to null (default) to trust no proxies — headers ignored.
    | Set to '*' to trust all proxies (only behind a single known LB).
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

    /*
    |------------------------------------------------------------------
    | Trusted Headers
    |------------------------------------------------------------------
    |
    | Bitmask of Symfony Request::HEADER_* constants controlling which
    | forwarded headers are honoured when a request arrives from a
    | trusted proxy.
    |
    | Default: trust all standard forwarded headers.
    |
    */

    'headers' => Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX,
];
