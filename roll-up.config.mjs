import { resolve } from 'node:path';
import nodeResolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import { terser } from '@rollup/plugin-terser';

export default {
  input: resolve('assets/js/src/starmus-integrator.js'),
  output: {
    file: resolve('assets/js/dist/starmus.bundle.js'),
    format: 'esm',
    sourcemap: true
  },
  plugins: [
    nodeResolve({ browser: true }),
    commonjs(),
    terser()
  ]
};
