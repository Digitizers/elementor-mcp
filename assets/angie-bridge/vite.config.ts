import { defineConfig } from 'vite';

// Single self-contained IIFE bundle; WordPress provides wp.apiFetch globally
// (the PHP enqueue declares the wp-api-fetch dependency).
export default defineConfig( {
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		sourcemap: false,
		minify: true,
		lib: {
			entry: 'src/index.ts',
			name: 'EmcpAngieBridge',
			formats: [ 'iife' ],
			fileName: () => 'angie-bridge.js',
		},
	},
} );
