<?php

declare(strict_types=1);

namespace Certigniter\CertificateRenderer\Support;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;

/**
 * Resolves groups into a flat, render-ready element list.
 *
 * A group's position and size are already baked into its children's own
 * x/y/width/height, but its rotation and opacity are not: they are a
 * parent transform, applied by rotating each child's stored centre around
 * the group's centre and multiplying the opacities. Rendering children
 * with their raw stored values would draw a rotated or translucent group's
 * children in the wrong place or at the wrong opacity.
 *
 * Pass `$compose = false` (the `compose_group_transforms` config option)
 * only when you must reproduce, exactly, PDFs issued by a tool that did
 * not apply this composition.
 */
class GroupComposer
{
    /**
     * @return DesignElement[] flat, render-ready list in original z-order:
     *                         group elements themselves removed (they have no visual), grouped
     *                         children replaced with their composed effective x/y/rotation/
     *                         opacity, everything else untouched.
     */
    public static function resolve(CertificateProject $project, bool $compose = true): array
    {
        $groupsByChildId = [];
        $hiddenGroupChildIds = [];

        foreach ($project->elements as $element) {
            if (! $element->isGroup()) {
                continue;
            }

            foreach ($project->children($element) as $child) {
                $groupsByChildId[$child->id] = $element;

                if (! $element->isVisible()) {
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

            if ($group === null || ! $compose) {
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
