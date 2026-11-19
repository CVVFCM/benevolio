/** @type {import("stylelint").Config} */
export default {
    extends: ['stylelint-config-standard'],
    ignoreFiles: ['assets/vendor/**'],
    rules: {
        'selector-class-pattern': [
            '^[a-z][a-z0-9-]*(__[a-z0-9][a-z0-9-]*)?(--[a-z0-9][a-z0-9-]*)?$',
            { message: 'Expected BEM lowercase-kebab pattern (block__element--modifier)' },
        ],
        'import-notation': 'string',
    },
};
