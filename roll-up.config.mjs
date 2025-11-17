import { resolve } from 'node:path';
import nodeResolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import { terser } from '@rollup/plugin-terser';

export default {
  input: resolve('src/js/starmus-integrator.js'),
  output: {
    file: resolve('assets/js/starmus-app.bundle.min.js'),
    format: 'esm',
    sourcemap: true
  },
  plugins: [
    nodeResolve({ browser: true }),
    commonjs(),
    terser({
      compress: { drop_console: true },
      mangle: true
    })
  ],
  treeshake: { moduleSideEffects: false }
};
