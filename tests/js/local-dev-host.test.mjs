// The stdio proxy turns off TLS verification for local development hosts only,
// because it sends a WordPress Application Password over that connection.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isLocalDevHost } from '../../bin/local-dev-host.mjs';

test('reserved and loopback names are local development hosts', () => {
	for (const host of ['localhost', '127.0.0.1', 'mysite.test', 'mysite.local', 'mysite.localhost', 'mysite.invalid']) {
		assert.equal(isLocalDevHost(host), true, host);
	}
});

test('.dev is a public TLD and keeps TLS verification', () => {
	// .dev is delegated (Google, HSTS-preloaded): example.dev is a real site.
	for (const host of ['example.dev', 'staging.client.dev', 'dev']) {
		assert.equal(isLocalDevHost(host), false, host);
	}
});

test('ordinary public hosts keep TLS verification', () => {
	for (const host of ['example.com', 'test.example.com', 'local.example.com', 'localhost.example.com', 'mytest']) {
		assert.equal(isLocalDevHost(host), false, host);
	}
});
