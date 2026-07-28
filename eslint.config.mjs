import js from "@eslint/js";
import globals from "globals";
import {defineConfig} from "eslint/config";

export default defineConfig(
    [
        {
            ignores: ["assets/vendor/**"],
        },
        {
            files: ["assets/**/*.js"],
            plugins: {js},
            extends: ["js/recommended"],
            languageOptions: {globals: globals.browser},
        },
    ]
);
