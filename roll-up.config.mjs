import { resolve } from 'node:path';
import nodeResolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import { terser } from '@rollup/plugin-terser';

export default {
  input: resolve('src/js/starmus-integrator.js'),      // ES module entry
  output: {
    file: resolve('assets/js/starmus-app.esm.js'),     // intermediate bundle
    format: 'esm',
    sourcemap: true
  },
  plugins: [
    nodeResolve({ browser: true }),
    commonjs(),
    terser()
  ],
  treeshake: { moduleSideEffects: false }
};
