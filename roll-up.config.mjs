import resolve from "@rollup/plugin-node-resolve";
import commonjs from "@rollup/plugin-commonjs";
import terser from "@rollup/plugin-terser";
import babel from "@rollup/plugin-babel";

const sharedPlugins = [
    resolve({
        browser: true,
        preferBuiltins: false,
    }),

    commonjs({
        include: /node_modules/,
    }),

    babel({
        babelHelpers: "bundled",
        exclude: "node_modules/**",
        presets: [
            [
                "@babel/preset-env",
                {
                    targets: {
                        ie: "11",
                        android: "4.4",
                        safari: "10",
                    },
                    useBuiltIns: "usage",
                    corejs: 3,
                },
            ],
        ],
    }),

    terser(),
];

export default [
    // Main Bundle
    {
        input: "src/js/starmus-main.js",

        output: {
            file: "assets/js/starmus-audio-recorder-script.bundle.min.js",
            format: "iife",
            name: "StarmusBundle",
            sourcemap: false,
        },

        external: [],

        plugins: sharedPlugins,
    },
    // Prosody Engine (Standalone)
    {
        input: "src/js/prosody/starmus-prosody-engine.js",

        output: {
            file: "assets/js/starmus-prosody-engine.min.js",
            format: "iife",
            name: "StarmusProsodyEngine",
            sourcemap: false,
        },

        external: [],

        plugins: sharedPlugins,
    },
    // App Mode (Standalone)
    {
        input: "src/js/appmode/sparxstar-app-mode.js",

        output: {
            file: "assets/js/sparxstar-app-mode.min.js",
            format: "iife",
            name: "SparxstarAppMode",
            sourcemap: false,
        },

        external: [],

        plugins: sharedPlugins,
    },
    // Consent Legal (Standalone)
    {
        input: "src/js/consent/starmus-legal.js",

        output: {
            file: "assets/js/starmus-legal.min.js",
            format: "iife",
            name: "StarmusConsentLegal",
            sourcemap: false,
        },

        external: [],

        plugins: sharedPlugins,
    },
];
