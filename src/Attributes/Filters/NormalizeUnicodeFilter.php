<?php

declare(strict_types=1);

namespace Pocta\DataMapper\Attributes\Filters;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class NormalizeUnicodeFilter implements FilterInterface
{
    /** @var 'NFC'|'NFD'|'NFKC'|'NFKD' */
    public readonly string $form;

    public function __construct(string $form = 'NFC')
    {
        if (!in_array($form, ['NFC', 'NFD', 'NFKC', 'NFKD'], true)) {
            $form = 'NFC';
        }
        $this->form = $form;
    }

    public function apply(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (!class_exists('Normalizer')) {
            return $value;
        }

        $constMap = [
            'NFC' => \Normalizer::FORM_C,
            'NFD' => \Normalizer::FORM_D,
            'NFKC' => \Normalizer::FORM_KC,
            'NFKD' => \Normalizer::FORM_KD,
        ];
        $formConst = $constMap[$this->form];
        $normalized = \Normalizer::normalize($value, $formConst);
        return $normalized === false ? $value : $normalized;
    }
}
