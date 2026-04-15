<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', 'node_modules']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                          => true,
        'array_syntax'                    => ['syntax' => 'short'],
        'binary_operator_spaces'          => ['default' => 'single_space'],
        'blank_line_after_namespace'      => true,
        'blank_line_after_opening_tag'    => true,
        'cast_spaces'                     => ['space' => 'single'],
        'concat_space'                    => ['spacing' => 'one'],
        'indentation_type'                => true,
        'line_ending'                     => true,
        'no_trailing_whitespace'          => true,
        'no_unused_imports'               => true,
        'single_quote'                    => true,
        'ternary_operator_spaces'         => true,
        'trailing_comma_in_multiline'     => ['elements' => ['arrays']],
        'trim_array_spaces'               => true,
        'unary_operator_spaces'           => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
