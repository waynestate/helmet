import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

// Two builds of one small Vue app, one per way HELMET hands Vite its nonce.
//
// `npm run build` is the raw PHP recipe README.md documents under "Raw PHP
// with a Vue (Vite) build": Vite writes HELMET's placeholder wherever a nonce
// belongs (script and link tags, plus a <meta property="csp-nonce"> its
// preload helper reads for the chunks it loads later), and the server swaps
// in the response nonce with helmet_csp_nonce_swap().
//
// `npm run build:laravel` is the Laravel (and Inertia) recipe: a manifest
// under dist-laravel/build that Laravel's @vite reads, as a WELD app builds
// into public/build. HELMET's middleware gives @vite the nonce and injects
// the meta tag, so the build itself knows nothing about nonces.
//
// Same helmet every request, fresh strap.
export default defineConfig(({ mode }) => {
    if (mode === 'laravel') {
        return {
            plugins: [vue()],
            base: '/build/',
            publicDir: false,
            build: {
                outDir: 'dist-laravel/build',
                emptyOutDir: true,
                manifest: 'manifest.json',
                rollupOptions: {
                    input: 'src/main.js',
                },
            },
        };
    }

    return {
        plugins: [vue()],
        html: {
            cspNonce: 'CSP_NONCE_SLOT_FOR_HELMET',
        },
        build: {
            outDir: 'dist',
            emptyOutDir: true,
        },
    };
});
