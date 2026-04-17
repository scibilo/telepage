/**
 * TELEPAGE — api.js
 * Centralised wrapper around fetch() for the admin API.
 *
 * Why this exists:
 *  - Every admin mutation MUST send the X-CSRF-Token header.
 *  - Doing that at every call site is error-prone: one missed spot is
 *    a CSRF vulnerability or a broken button.
 *  - This wrapper reads the token once from <meta name="csrf"> and
 *    injects it automatically on all non-GET requests.
 *
 * Usage:
 *     const res = await tpApi('delete_content', {
 *         method: 'POST',
 *         body: { id: 42 }
 *     });
 *     if (!res.ok) showError(res.error);
 *
 * Return value: the parsed JSON response, which is always:
 *     { ok: true, data: ... }         on success
 *     { ok: false, error: '...' }     on failure
 *
 * Network / parse errors are converted to { ok: false, error: '...' }
 * so callers can keep a single error-handling branch.
 */
(function (global) {
    'use strict';

    const API_ENDPOINT = 'api/admin.php';

    /**
     * Reads the CSRF token from the <meta> tag injected by admin pages.
     * Returns empty string if not present (e.g. public pages) — the server
     * will then reject the request, which is the correct behaviour.
     */
    function readCsrfToken() {
        const meta = document.querySelector('meta[name="csrf"]');
        return meta ? meta.content : '';
    }

    /**
     * Resolves the admin API endpoint relative to the current page.
     *
     * Admin pages live under /admin/, so they need '../api/admin.php'.
     * The public frontend lives at /, so it needs 'api/admin.php'.
     * We detect by looking at the current path.
     */
    function resolveEndpoint() {
        // Normalise: strip trailing file name, keep directory
        const path = global.location.pathname;
        if (path.includes('/admin/')) {
            return '../' + API_ENDPOINT;
        }
        return API_ENDPOINT;
    }

    /**
     * Main wrapper. Signature kept close to fetch() for familiarity.
     *
     * @param {string} action  The value of the ?action= query parameter.
     * @param {Object} [opts]  Options:
     *   - method:  HTTP method (default 'GET')
     *   - body:    Plain object; will be JSON-encoded. For file uploads,
     *              pass a FormData directly in `formData` instead.
     *   - formData: FormData instance for multipart uploads.
     *   - query:   Extra query parameters as a plain object.
     * @returns {Promise<Object>} Parsed JSON response.
     */
    async function tpApi(action, opts) {
        opts = opts || {};
        const method = (opts.method || 'GET').toUpperCase();

        // Build URL with query string
        const params = new URLSearchParams({ action: action });
        if (opts.query) {
            for (const k in opts.query) {
                if (Object.prototype.hasOwnProperty.call(opts.query, k)) {
                    params.set(k, opts.query[k]);
                }
            }
        }
        const url = resolveEndpoint() + '?' + params.toString();

        // Build headers: always include CSRF on write methods
        const headers = {};
        const isWrite = method !== 'GET' && method !== 'HEAD';
        if (isWrite) {
            headers['X-CSRF-Token'] = readCsrfToken();
        }

        // Build body
        let body = null;
        if (opts.formData) {
            // Browser sets Content-Type with boundary automatically
            body = opts.formData;
        } else if (opts.body != null && isWrite) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(opts.body);
        }

        let response;
        try {
            response = await fetch(url, {
                method: method,
                headers: headers,
                body: body,
                credentials: 'same-origin'
            });
        } catch (err) {
            return { ok: false, error: 'Network error: ' + err.message };
        }

        // Expect JSON; if the server returned HTML (e.g. a redirect to
        // login because the session expired), surface a clean error.
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            if (response.status === 401) {
                return { ok: false, error: 'Session expired. Please log in again.' };
            }
            return { ok: false, error: 'Unexpected server response (HTTP ' + response.status + ')' };
        }

        try {
            return await response.json();
        } catch (err) {
            return { ok: false, error: 'Invalid JSON response' };
        }
    }

    // Expose on window for non-module scripts (matches existing project style)
    global.tpApi = tpApi;
})(window);
