/**
 * Whether a WordPress host is a local development host, for which the stdio
 * proxy accepts a self-signed TLS certificate.
 *
 * Only names that cannot be a public site qualify: loopback, and the reserved
 * .test, .localhost, .invalid (RFC 2606/6761) and .local (mDNS, RFC 6762).
 * Not .dev: it is a delegated public TLD (HSTS-preloaded), so example.dev is a
 * real site, and skipping verification there would expose the Application
 * Password the proxy sends to anyone in the middle.
 *
 * @param {string} hostname URL hostname.
 * @returns {boolean}
 */
export function isLocalDevHost(hostname) {
  return /\.(test|local|localhost|invalid)$/.test(hostname)
    || hostname === 'localhost'
    || hostname === '127.0.0.1';
}
