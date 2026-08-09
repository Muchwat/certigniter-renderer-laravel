<?php

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * A group's translate/resize are baked into its children's own x/y/width/
 * height at edit time, but its rotation and opacity are NOT - they're a
 * live parent transform that Certigniter's own canvas composes on every
 * frame (design_element.dart), by rotating each child's stored center
 * around the group's center. A renderer that just flat-renders every
 * element with its raw stored values will draw a rotated or
 * partially-transparent group's children in the wrong place / at the
 * wrong opacity.
 *
 * (Certigniter's own bulk-CSV-issuance pipeline - batch_pdf_generator.dart
 * - has a known bug where it skips this composition; set
 * config('certigniter.compose_group_transforms') to false only if you
 * specifically need byte-parity with PDFs that pipeline already issued.)
 */
class GroupComposer
{
    /**
     * @return DesignElement[] flat, render-ready list in original z-order:
     *  group elements themselves removed (they have no visual), grouped
     *  children replaced with their composed effective x/y/rotation/
     *  opacity, everything else untouched.
     */
    public static function resolve(CertificateProject $project, bool $compose = true): array
    {
        $groupsByChildId = [];
        $hiddenGroupChildIds = [];

        foreach ($project->elements as $element) {
            if (!$element->isGroup()) {
                continue;
            }

            foreach ($project->children($element) as $child) {
                $groupsByChildId[$child->id] = $element;

                if (!$element->isVisible()) {
                    // Certigniter's bulk export doesn't propagate a hidden
                    // group's visibility to its children (a gap in that
                    // pipeline, not a deliberate feature) - a renderer
                    // starting fresh has no reason to keep that gap, so a
                    // hidden group hides its children here regardless of
                    // $compose.
                    $hiddenGroupChildIds[$child->id] = true;
                }
            }
        }

        $resolved = [];

        foreach ($project->elements as $element) {
            if ($element->isGroup()) {
                continue;
            }

            if (isset($hiddenGroupChildIds[$element->id])) {
                continue;
            }

            $group = $groupsByChildId[$element->id] ?? null;

            if ($group === null || !$compose) {
                $resolved[] = $element;

                continue;
            }

            $resolved[] = self::composeChild($element, $group);
        }

        return $resolved;
    }

    private static function composeChild(DesignElement $child, DesignElement $group): DesignElement
    {
        $thetaRad = deg2rad($group->rotation());

        $groupCenterX = $group->centerX();
        $groupCenterY = $group->centerY();

        $relX = $child->centerX() - $groupCenterX;
        $relY = $child->centerY() - $groupCenterY;

        $rotatedX = $relX * cos($thetaRad) - $relY * sin($thetaRad);
        $rotatedY = $relX * sin($thetaRad) + $relY * cos($thetaRad);

        $effectiveCenterX = $groupCenterX + $rotatedX;
        $effectiveCenterY = $groupCenterY + $rotatedY;

        return $child->withEffectiveTransform(
            x: $effectiveCenterX - $child->width / 2,
            y: $effectiveCenterY - $child->height / 2,
            rotation: $child->rotation() + $group->rotation(),
            opacity: $child->opacity() * $group->opacity(),
        );
    }
}
