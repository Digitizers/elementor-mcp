/**
 * Angie bridge — registers the Aura Design Engine's read-only tool surface
 * with Elementor's Angie assistant via the public @elementor/angie-sdk.
 *
 * The MCP server here is a thin browser-side shim: tool listing and execution
 * both go to the plugin's REST routes (emcp/angie/v1), where the allowlist,
 * the read-only invariant, and each ability's own permission callback are
 * enforced server-side. Nothing in this bundle grants authority.
 */
import { AngieMcpSdk } from '@elementor/angie-sdk';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import {
	CallToolRequestSchema,
	ListToolsRequestSchema,
	type CallToolResult,
	type Tool,
} from '@modelcontextprotocol/sdk/types.js';

type BridgeConfig = {
	toolsEndpoint: string;
	executeBase: string;
	nonce: string;
	version: string;
	serverName: string;
	serverLabel: string;
};

declare global {
	interface Window {
		emcpAngieBridge?: BridgeConfig;
		__emcpAngieBridgeInit?: boolean;
	}
}

declare const wp: {
	apiFetch: ( options: {
		url: string;
		method?: string;
		data?: unknown;
		headers?: Record< string, string >;
	} ) => Promise< unknown >;
};

function getConfig(): BridgeConfig | null {
	return typeof window !== 'undefined' && window.emcpAngieBridge
		? window.emcpAngieBridge
		: null;
}

async function fetchTools( config: BridgeConfig ): Promise< Tool[] > {
	const response = ( await wp.apiFetch( {
		url: config.toolsEndpoint,
		method: 'GET',
		headers: { 'X-WP-Nonce': config.nonce },
	} ) ) as { tools?: Tool[] };

	return Array.isArray( response.tools ) ? response.tools : [];
}

async function executeTool(
	config: BridgeConfig,
	name: string,
	args: Record< string, unknown >
): Promise< CallToolResult > {
	try {
		return ( await wp.apiFetch( {
			url: config.executeBase + encodeURIComponent( name ),
			method: 'POST',
			data: args,
			headers: { 'X-WP-Nonce': config.nonce },
		} ) ) as CallToolResult;
	} catch ( error ) {
		const message =
			error instanceof Error
				? error.message
				: 'Aura Design Engine tool execution failed.';
		return {
			content: [ { type: 'text', text: message } ],
			isError: true,
		};
	}
}

async function init(): Promise< void > {
	if ( window.__emcpAngieBridgeInit ) {
		return;
	}

	const config = getConfig();
	if ( ! config || typeof wp === 'undefined' || typeof wp.apiFetch !== 'function' ) {
		return;
	}

	window.__emcpAngieBridgeInit = true;

	// Do NOT call sdk.waitForReady() here: in SDK 1.5.0 it blocks on
	// module-local sidebar state (sidebarV2BootPromise / appState.iframe) that
	// only exists when THIS SDK instance loaded the sidebar. Angie's own plugin
	// loads the sidebar with its own SDK copy, so that state never populates in
	// this bundle and the wait would spin forever. registerServer() queues the
	// registration internally and the SDK processes the queue when its detector
	// sees Angie become ready (postMessage-based, cross-instance safe).
	const sdk = new AngieMcpSdk();

	const server = new McpServer(
		{ name: config.serverName, version: config.version },
		{ capabilities: { tools: { listChanged: true } } }
	);

	server.server.setRequestHandler( ListToolsRequestSchema, async () => ( {
		tools: await fetchTools( config ),
	} ) );

	server.server.setRequestHandler( CallToolRequestSchema, async ( request ) =>
		executeTool(
			config,
			request.params.name,
			( request.params.arguments as Record< string, unknown > | undefined ) ?? {}
		)
	);

	await sdk.registerServer( {
		name: config.serverName,
		version: config.version,
		description:
			'Aura Design Engine (read-only): inspect Elementor pages, widgets, schemas, global classes, and variables. Write tools are not exposed here — mutations run through the governed MCP connection with snapshot-before-write and rollback.',
		server,
	} );
}

function safeInit(): void {
	init().catch( ( err: unknown ) => {
		// Angie absent, SDK not ready, or a capabilities race — stay silent.
		const message = err instanceof Error ? err.message : String( err );
		// eslint-disable-next-line no-console
		console.debug?.( '[EMCP] Angie bridge init skipped:', message );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', safeInit );
} else {
	safeInit();
}
