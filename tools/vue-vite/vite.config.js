import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';

// The exact setup README.md documents under "Raw PHP with a Vue (Vite) build":
// Vite writes HELMET's placeholder wherever a nonce belongs (script and link
// tags, plus a <meta property="csp-nonce"> its preload helper reads for the
// chunks it loads later), and the server swaps in the response nonce with
// helmet_csp_nonce_swap(). Same helmet every request, fresh strap.
export default defineConfig({
    plugins: [vue()],
    html: {
        cspNonce: 'CSP_NONCE_SLOT_FOR_HELMET',
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
    },
});
