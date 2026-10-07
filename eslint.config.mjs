import globals from 'globals';
import wordpress from '@wordpress/eslint-plugin';
import oxlint from 'eslint-plugin-oxlint';

export default [
  {
    ignores: [
      'build/**',
      'packages/*/build/**',
      'packages/*/build-module/**',
      'vendor/**',
      'node_modules/**',
      'dist/**',
      'tests/e2e/env/**',
      'scratch/**',
      'assets/**',
    ],
  },
  ...wordpress.configs.recommended,
  ...oxlint.configs['flat/recommended'],
  {
    languageOptions: {
      globals: {
        ...globals.browser,
        ...globals.jquery,
        wp: 'readonly',
        ajaxurl: 'readonly',
        gecx_admin_params: 'readonly',
        gecxStorefrontConfig: 'readonly',
      },
    },
    rules: {
      'prettier/prettier': 'off',
      // Closure Compiler annotations compatibility
      'jsdoc/require-returns-description': 'off',
      'jsdoc/check-line-alignment': 'off',
      'jsdoc/reject-any-type': 'off',
      'jsdoc/reject-function-type': 'off',
      'jsdoc/no-undefined-types': 'off',
      'jsdoc/valid-types': 'off',
      'jsdoc/require-param': 'off',
      camelcase: [
        'error',
        {
          properties: 'never',
          allow: ['gecx_admin_params'],
        },
      ],
      'no-alert': 'off',
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      'no-nested-ternary': 'off',
      'object-shorthand': 'off',
      'react-hooks/rules-of-hooks': 'off',
    },
  },
];
