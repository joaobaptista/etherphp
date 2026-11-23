<?php

// Proxies trusted to send X-Forwarded-* (HTTPS ending at a load balancer, Cloudflare, Traefik...).
// Without this, Laravel behind a proxy thinks it runs on http:// and builds wrong links.
// TRUSTED_PROXIES: empty = none (direct access, local), "*" = the proxy that calls the app, or a list of IPs/CIDRs.
$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' ? null : $proxies,
];
