import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import babel from '@rollup/plugin-babel';

export default {
  input: 'src/js/starmus-main.js',

  output: {
    file: 'assets/js/starmus-audio-recorder-script.bundle.min.js',
    format: 'esm',
    sourcemap: false,
    globals: {
      'tus-js-client': 'tus',
      'peaks.js': 'Peaks'
    }
  },

  plugins: [
    resolve({
      browser: true,
      preferBuiltins: false
    }),

    commonjs(),

    babel({
      babelHelpers: 'bundled',
      exclude: /node_modules/,
      presets: [
        ['@babel/preset-env', {
          targets: '> 0.25%, not dead',
          useBuiltIns: false,
          corejs: false
        }]
      ]
    }),

    terser()
  ]
};
