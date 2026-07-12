import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Blade + Alpine is the only supported frontend architecture (see CLAUDE.md).
// No React/Inertia entry point, no SSR build.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/tailadmin.css', 'resources/js/tailadmin/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
