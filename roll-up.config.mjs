// ============================
// ROLLUP CONFIG
// ============================
// Bundles app code + vendor libraries (tus-js-client, peaks.js)
// into a single IIFE for WordPress script loading.

import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';

export default {
    input: 'src/js/starmus-integrator.js',

    output: {
        file: 'assets/js/starmus-app.bundle.min.js',
        format: 'iife',
        name: 'StarmusApp',
        sourcemap: false,
        // Expose tus and Peaks globally for WordPress compatibility
        globals: {
            'tus-js-client': 'tus',
            'peaks.js': 'Peaks'
        }
    },

    plugins: [
        resolve({ browser: true }),
        commonjs(),
        terser()
    ]
};
