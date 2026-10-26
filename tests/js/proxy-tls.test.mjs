// bin/mcp-proxy.mjs sends a WordPress Application Password, and skips TLS
// certificate verification for local development hosts only. These tests run
// the shipped file on its own — copied alone into an empty directory, the way
// the documented standalone install uses it — against a local HTTPS server
// with a self-signed certificate. Every hostname resolves to that server.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:https';
import { mkdtempSync, copyFileSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const PROXY = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'bin', 'mcp-proxy.mjs');
const MARKER = 'self-signed-server-answered';
let dir, server, port;

before(async () => {
	dir = mkdtempSync(join(tmpdir(), 'emcp-proxy-tls-'));
	execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1', '-subj', '/CN=self-signed',
		'-keyout', join(dir, 'key.pem'), '-out', join(dir, 'cert.pem')], { stdio: 'ignore' });
	server = createServer({ key: readFileSync(join(dir, 'key.pem')), cert: readFileSync(join(dir, 'cert.pem')) }, (req, res) => {
		let body = '';
		req.on('data', (c) => { body += c; });
		req.on('end', () => {
			let id = null;
			try { id = JSON.parse(body).id ?? null; } catch { /* the HEAD probe has no body */ }
			res.writeHead(200, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ jsonrpc: '2.0', id, result: { serverInfo: { name: MARKER } } }));
		});
	});
	await new Promise((r) => server.listen(0, '127.0.0.1', r));
	port = server.address().port;
	// Every lookup answers 127.0.0.1, so example.dev reaches the local server.
	writeFileSync(join(dir, 'resolve-local.mjs'),
		"import dns from 'node:dns';\n" +
		"dns.lookup = (host, opts, cb) => { if (typeof opts === 'function') { cb = opts; opts = {}; }\n" +
		"  if (opts && opts.all) return cb(null, [{ address: '127.0.0.1', family: 4 }]);\n" +
		"  cb(null, '127.0.0.1', 4); };\n");
	copyFileSync(PROXY, join(dir, 'mcp-proxy.mjs'));
});

after(() => {
	server?.close();
	if (dir) rmSync(dir, { recursive: true, force: true });
});

function initialize(host) {
	return new Promise((resolve) => {
		const child = spawn(process.execPath, ['--import', join(dir, 'resolve-local.mjs'), join(dir, 'mcp-proxy.mjs')], {
			cwd: dir,
			env: { ...process.env, WP_URL: `https://${host}:${port}`, WP_USERNAME: 'u', WP_APP_PASSWORD: 'not-a-real-password' },
		});
		let out = '';
		let err = '';
		const done = () => { child.kill(); resolve({ out, err }); };
		const timer = setTimeout(done, 8000);
		child.stderr.on('data', (d) => { err += d; });
		child.stdout.on('data', (d) => {
			out += d;
			if (out.includes('"id":1')) { clearTimeout(timer); done(); }
		});
		child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize',
			params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 't', version: '1' } } }) + '\n');
	});
}

test('the proxy runs as a single file, and a .test host accepts the self-signed certificate', async () => {
	const { out, err } = await initialize('mysite.test');
	assert.ok(!err.includes('ERR_MODULE_NOT_FOUND'), err);
	assert.ok(out.includes(MARKER), `expected the server's answer, got: ${out} ${err}`);
});

test('a .dev host is a public site: the self-signed certificate is refused', async () => {
	const { out } = await initialize('example.dev');
	assert.ok(!out.includes(MARKER), 'the proxy talked to a .dev host over an unverified TLS connection');
});
