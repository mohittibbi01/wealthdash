<?php
/**
 * WealthDash — PHP CS Fixer Config [t419]
 * File: .php-cs-fixer.php
 * Run: vendor/bin/php-cs-fixer fix --dry-run --diff
 */
$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/api', __DIR__ . '/includes', __DIR__ . '/config'])
    ->exclude(['vendor', 'node_modules', 'public'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                         => true,
        'array_syntax'                   => ['syntax' => 'short'],
        'declare_strict_types'           => true,
        'no_unused_imports'              => true,
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'single_quote'                   => true,
        'trailing_comma_in_multiline'    => true,
        'no_trailing_whitespace'         => true,
        'no_blank_lines_after_phpdoc'    => true,
        'binary_operator_spaces'         => ['default' => 'align_single_space_minimal'],
        'blank_line_before_statement'    => ['statements' => ['return', 'throw', 'try']],
        'cast_spaces'                    => ['space' => 'single'],
        'concat_space'                   => ['spacing' => 'one'],
        'function_typehint_space'        => true,
        'lowercase_cast'                 => true,
        'magic_constant_casing'          => true,
        'no_empty_comment'               => true,
        'no_short_bool_cast'             => true,
        'no_superfluous_phpdoc_tags'     => false,
        'object_operator_without_whitespace' => true,
        'phpdoc_align'                   => ['align' => 'vertical'],
        'return_type_declaration'        => ['space_before' => 'none'],
        'semicolon_after_instruction'    => true,
        'short_scalar_cast'              => true,
        'visibility_required'            => true,
    ])
    ->setFinder($finder)
    ->setIndent('    ')
    ->setLineEnding("\n");
