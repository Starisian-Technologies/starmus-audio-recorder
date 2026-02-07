<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src'])
    ->exclude('vendor')
    ->exclude('node_modules')
    ->exclude('tests')
    ->name('*.php')
    ->notName('*.blade.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'not_operator_with_successor_space' => true,
        'spaces_inside_parentheses' => false, // Allow WordPress style ( ! condition )
        'no_spaces_inside_parenthesis' => false, // Deprecated but kept for safety
        'single_quote' => true,
        'no_unused_imports' => true,
        'no_trailing_whitespace' => true,
        'no_extra_blank_lines' => ['tokens' => ['extra', 'throw', 'use']],
        'align_multiline_comment' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => false, // WordPress compat
        'function_typehint_space' => true,
        'method_chaining_indentation' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'no_alternative_syntax' => true,
        'no_leading_namespace_whitespace' => true,
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_short_bool_cast' => true,
        'no_whitespace_before_comma_in_array' => true,
        'normalize_index_brace' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,
        'return_type_declaration' => true,
        'blank_lines_before_namespace' => [
            'min_line_breaks' => 1,
            'max_line_breaks' => 1,
        ],
        'single_line_after_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'trim_array_spaces' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
