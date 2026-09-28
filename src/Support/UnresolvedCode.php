<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\DesignElement;

class UnresolvedCode
{
    /**
     * A "Verification link" or "Dynamic value" code with nothing to encode
     * is skipped rather than rendered with placeholder data - a scannable
     * code that encodes the wrong thing is worse than a missing one.
     */
    public static function warning(DesignElement $element, string $data): ?string
    {
        if ($data !== '') {
            return null;
        }

        $label = $element->type === 'qrcode' ? 'QR code' : 'barcode';

        if ($element->isVerificationCode()) {
            return sprintf(
                "Element %s: this %s's content source is 'Verification link' but no value was supplied for it - "
                .'pass its element ID and the value to encode (e.g. a verification URL or code) in '
                .'$qrCodeOverrides. It was skipped.',
                $element->id,
                $label,
            );
        }

        if ($element->isDynamicCode()) {
            $variableName = trim((string) $element->property('variableName', ''));

            return $variableName === ''
                ? sprintf(
                    "Element %s: this %s's content source is 'Dynamic value' but no data column was ever set for it "
                    .'(properties.variableName is empty). It was skipped.',
                    $element->id,
                    $label,
                )
                : sprintf(
                    "Element %s: this %s's content source is 'Dynamic value' bound to column '%s', but the recipient "
                    .'record has no value for it. It was skipped.',
                    $element->id,
                    $label,
                    $variableName,
                );
        }

        return null;
    }
}
