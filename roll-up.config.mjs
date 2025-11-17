import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';

export default {
    input: 'src/js/starmus-integrator.js',

    output: {
        file: 'assets/js/starmus-app.bundle.min.js',
        format: 'iife',
        name: 'StarmusApp',
        sourcemap: false
    },

    plugins: [
        resolve(),
        commonjs(),
        terser()
    ]
};
