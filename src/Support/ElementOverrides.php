<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Per-render replacements keyed by stable element ID. Each returns cloned
 * elements, never mutating the parsed project.
 */
class ElementOverrides
{
    /**
     * Replace embedded image bytes by stable canvas element ID without
     * mutating the parsed marketplace template object.
     *
     * @param  DesignElement[]  $elements
     * @param  array<string, string>  $overrides
     * @return DesignElement[]
     */
    public static function images(array $elements, array $overrides): array
    {
        if ($overrides === []) {
            return $elements;
        }

        return array_map(function (DesignElement $element) use ($overrides): DesignElement {
            $replacement = $overrides[$element->id] ?? null;
            if ($element->type !== 'image' || ! is_string($replacement) || trim($replacement) === '') {
                return $element;
            }

            $clone = clone $element;
            $clone->properties['imageData'] = preg_replace('#^data:image/[^;]+;base64,#i', '', trim($replacement));
            unset($clone->properties['path']);

            return $clone;
        }, $elements);
    }

    /**
     * Replace a qrcode or barcode element's encoded payload by stable canvas
     * element ID: the way the calling application injects a value it
     * generates itself (e.g. a per-recipient verification URL or code) that
     * has no corresponding column in the recipient record. This is the only
     * way to give a "Verification link" code (see
     * DesignElement::isVerificationCode()) any data at all, since that
     * element always stores empty `data`. It also works on
     * ordinary "Static"/"Dynamic value" codes, where it simply wins over the
     * static/merged value.
     *
     * @param  DesignElement[]  $elements
     * @param  array<string, string>  $overrides  qrcode/barcode element ID => literal payload
     * @return DesignElement[]
     */
    public static function codes(array $elements, array $overrides): array
    {
        if ($overrides === []) {
            return $elements;
        }

        return array_map(function (DesignElement $element) use ($overrides): DesignElement {
            $replacement = $overrides[$element->id] ?? null;
            if (! $element->isCode() || ! is_string($replacement) || $replacement === '') {
                return $element;
            }

            $clone = clone $element;
            $clone->properties['data'] = $replacement;

            return $clone;
        }, $elements);
    }
}
