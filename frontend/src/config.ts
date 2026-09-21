/**
 * The shared ERP login domain — one login for every school on the platform.
 * A school's own domain (its public website) redirects `/admin`, `/login`,
 * etc. here instead of serving them itself; see the nginx config
 * (`docker/nginx/prod.conf`) and the backend's `TENANCY_CENTRAL_DOMAINS`
 * (`config/tenancy.php`), which must both list this same hostname.
 *
 * A subdomain of one tenant's own domain (`newtechkh.com`, the one domain
 * actually owned/DNS+TLS-configured today) rather than the platform's own
 * `ntcsweb.com` — that domain is only ever a config default/placeholder in
 * this codebase and has never been confirmed to actually resolve anywhere.
 */
export const ERP_HOST = 'erp.newtechkh.com'

/**
 * Every hostname that resolves no single school (mirrors the backend's
 * `TENANCY_CENTRAL_DOMAINS` and the nginx `map $host $is_central_domain`
 * block — all three must be kept in sync by hand). Used only by the
 * router's client-side backstop redirect (see router/index.ts); nginx is the
 * primary mechanism and already excludes exactly this list.
 */
export const CENTRAL_HOSTS = ['localhost', '127.0.0.1', 'admin.ntcsweb.com', ERP_HOST]
