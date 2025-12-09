import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import babel from '@rollup/plugin-babel';

export default {
  input: 'src/js/starmus-main.js',

  output: {
    file: 'assets/js/starmus-audio-recorder-script.bundle.min.js',
    format: 'iife',
    name: 'StarmusBundle',
    sourcemap: false,
    globals: {
      'peaks.js': 'Peaks'
    }
  },

  external: [
    'peaks.js'
  ],

  plugins: [
    resolve({
      browser: true,
      preferBuiltins: false
    }),

    commonjs({
      include: /node_modules/
    }),

    babel({
      babelHelpers: 'bundled',
      exclude: 'node_modules/**',
      presets: [
        [
          '@babel/preset-env',
          {
            targets: {
              ie: '11',
              android: '4.4',
              safari: '10'
            },
            useBuiltIns: 'usage',
            corejs: 3
          }
        ]
      ]
    }),

    terser()
  ]
};
