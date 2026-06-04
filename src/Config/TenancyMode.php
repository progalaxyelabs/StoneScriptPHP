<?php

declare(strict_types=1);

namespace StoneScriptPHP\Config;

/**
 * Tenancy mode constants (AUTH-SPEC §T).
 *
 * T1 — Single-tenant: one tenant per deployment (e.g. a white-label install).
 *       No tenant_id in JWT or URL.
 *
 * T2 — JWT-tenant (default): tenant_id is stamped into the JWT at login via
 *       the token exchange step. All platform API calls carry the tenant context
 *       via the JWT claim. Used by webmeteor, progalaxy, etc.
 *
 * T3 — URL-tenant: tenant_id is NEVER in the JWT. User navigates to
 *       /stores/:storeId/* and every API call carries the storeId in the URL.
 *       The StoreAccessMiddleware validates the identity's membership in storeId
 *       and sets the GatewayClient tenant_id per-request.
 *       Used by medstoreapp, logisticsapp, restrantapp, instituteapp.
 */
class TenancyMode
{
    /** Single-tenant deployment — no multi-tenancy. */
    const T1 = 'single';

    /** JWT-tenant — tenant_id stamped into JWT at login (default). */
    const T2 = 'jwt';

    /** URL-tenant — tenant_id derived from :storeId URL parameter. */
    const T3 = 'url';
}
