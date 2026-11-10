<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
// Small enough for Vite to inline as a data: URI, which img-src allows.
import avatar from '../../../site/brand/favicon-16.png';

// A separate chunk, loaded after the page is up: the dynamic import() runs
// under 'strict-dynamic', and Vite's preload helper adds the chunk's
// stylesheet with the nonce it reads from <meta property="csp-nonce">.
const LazyPanel = defineAsyncComponent(() => import('./LazyPanel.vue'));

const presses = ref(0);
// A bound style is set through the CSSOM, which no style-src directive
// governs; it still works with style_src_attr => 'none'.
const bar = computed(() => ({ borderLeftColor: presses.value > 0 ? 'rgb(255, 204, 51)' : 'rgb(12, 84, 72)' }));
</script>

<template>
    <main class="fixture">
        <h1 data-check="mounted">mounted</h1>
        <p class="scoped" data-check="scoped">Scoped styles came in through a nonced stylesheet.</p>
        <img data-check="inlined" :src="avatar" alt="" width="16" height="16">
        <button type="button" data-check="count" @click="presses++">Pressed {{ presses }} times</button>
        <p class="bar" data-check="bound-style" :style="bar">A bound style.</p>
        <LazyPanel />
    </main>
</template>

<style scoped>
.fixture {
    font-family: system-ui, sans-serif;
    max-width: 40rem;
    margin: 2rem auto;
}

.scoped {
    color: rgb(12, 84, 72);
}

.bar {
    border-left: 4px solid;
    padding-left: 0.5rem;
}
</style>
