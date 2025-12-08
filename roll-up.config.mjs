// ============================
// ROLLUP CONFIG (WITH BABEL)
// ============================

import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import terser from '@rollup/plugin-terser';
import babel from '@rollup/plugin-babel'; // <-- MUST BE INSTALLED

export default {
    input: 'src/js/starmus-main.js', 

    output: {
        file: 'assets/js/starmus-audio-recorder-script.bundle.min.js',
        format: 'esm', // <-- CHANGE TO ES MODULE FORMAT
        // name: 'StarmusApp', // Remove name property for ES module
        sourcemap: false,
        globals: {
            'tus-js-client': 'tus',
            'peaks.js': 'Peaks'
        },
        // REMOVE THE ENTIRE 'intro' BLOCK
        intro: undefined 
    },

  plugins: [
      resolve({
          browser: true,
          preferBuiltins: false    
      }),
      commonjs({
          include: /node_modules/  
      }),
      // 🔥 CRITICAL FIX: BABEL PLUGIN ADDED HERE
      babel({
          babelHelpers: 'bundled',
          exclude: 'node_modules/**', // Only transpile *your* source code
          presets: [
              ['@babel/preset-env', { 
                  targets: { 
                      'ie': '11', 
                      'android': '4.4',
                      'safari': '10' 
                  },
                  useBuiltIns: 'usage',
                  corejs: 3 
              }]
          ]
      }),
      terser() // Terser runs last to minify
  ]
};