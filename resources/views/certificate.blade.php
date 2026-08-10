<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Certificate</title>
    <style>
        @page {
            margin: 0;
            /* Do NOT set a `size` here - the renderer configures paper size/orientation on the Dompdf instance itself. */
        }

        body {
            margin: 0;
            padding: 0;
        }

        .certificate-container {
            position: relative;
            width: {{ $project->width }}{{ $project->unit }};
            height: {{ $project->height }}{{ $project->unit }};
            overflow: hidden;
        }

        .element {
            position: absolute;
            box-sizing: border-box;
        }

        .text-content {
            position: absolute;
            /* Dompdf constrains an absolutely positioned auto-width inline
               box to the space between its anchor and the nearest edge. A
               centered (left: 50%) title therefore saw only half of its
               element width and wrapped. Table layout shrink-wraps to the
               text's intrinsic width before the positioning transform is
               applied, matching Flutter's aligned RenderParagraph. */
            display: table;
            box-sizing: border-box;
            max-width: 100%;
        }

    </style>
</head>
<body>
    <div class="certificate-container">
        @foreach ($elements as $element)
            @php
                $unit = $project->unit;
                $wrapperStyle = sprintf(
                    'left: %s%s; top: %s%s; width: %s%s; height: %s%s; opacity: %s;',
                    $element->x, $unit, $element->y, $unit, $element->width, $unit, $element->height, $unit,
                    $element->opacity(),
                );
                if (abs($element->rotation()) > 0.001) {
                    $wrapperStyle .= sprintf(
                        ' transform: rotate(%sdeg); transform-origin: center;',
                        $element->rotation(),
                    );
                }
            @endphp

            @if ($element->isTextLike())
                @php
                    $fontFamily = $fonts->resolveFamily($element->property('fontFamily'));
                    $rawWeight = (string) $element->property('fontWeight', 'normal');
                    $isBold = str_contains(strtolower($rawWeight), 'bold')
                        || (is_numeric(str_replace('w', '', $rawWeight)) && (int) str_replace('w', '', $rawWeight) >= 600);
                    // Certigniter's native PDF renderer passes fontSize
                    // straight to pw.TextStyle, where it is already points.
                    $fontSizePt = (float) $element->property('fontSize', 14.0);
                    // Dompdf's tableless line box places the glyph baseline
                    // lower than package:pdf by a stable fraction of the
                    // font size. Compensate inside the element box so both
                    // renderers share the same visual vertical center.
                    $baselineCorrectionPt = $fontSizePt * 0.0875;
                    $color = \Certigniter\CertificateRenderer\Support\ColorConverter::toCss(
                        $element->property('color'), $project->colorFormat, '#000000',
                    );
                    $textAlign = $element->property('textAlign', 'left');
                    $lineHeight = (float) $element->property('lineHeight', 1.2);
                    // pdf.TextStyle.lineSpacing is extra leading in points,
                    // not CSS's unitless multiplier.
                    $lineHeightPt = $fontSizePt + max(0, $lineHeight - 1);
                    $letterSpacing = (float) $element->property('letterSpacing', 0);
                    $decorations = [];
                    if ($element->property('underline', false)) {
                        $decorations[] = 'underline';
                    } elseif ($element->property('strikethrough', false)) {
                        $decorations[] = 'line-through';
                    }
                    $shadow = $element->property('shadow');
                    $shadowCss = '';
                    if (is_array($shadow)) {
                        $shadowColor = \Certigniter\CertificateRenderer\Support\ColorConverter::toCss(
                            $shadow['color'] ?? null, $project->colorFormat, '#00000080',
                        );
                        $shadowCss = sprintf(
                            'text-shadow: %s%s %s%s %s%s %s;',
                            $shadow['offsetX'] ?? 0, $unit, $shadow['offsetY'] ?? 0, $unit, $shadow['blur'] ?? 0, $unit, $shadowColor,
                        );
                    }
                    // Text gradients have no faithful dompdf equivalent (no
                    // background-clip:text support) - fall back to the
                    // gradient's first stop as a flat color rather than
                    // emitting CSS that silently renders nothing.
                    $gradient = $element->property('gradient');
                    if (is_array($gradient) && !empty($gradient['colors'][0])) {
                        $color = \Certigniter\CertificateRenderer\Support\ColorConverter::toCss(
                            $gradient['colors'][0], $project->colorFormat, $color,
                        );
                    }
                    [$contentX, $contentY] = $element->contentAlignmentFactors();
                    $contentPosition = $contentX === 0.0
                        ? 'left: 0;'
                        : ($contentX === 1.0 ? 'right: 0;' : 'left: 50%;');
                    $contentPosition .= $contentY === 0.0
                        ? ' top: 0;'
                        : ($contentY === 1.0 ? ' bottom: 0;' : ' top: 50%;');
                    $contentTransforms = [];
                    if ($contentX === 0.5) {
                        $contentTransforms[] = 'translateX(-50%)';
                    }
                    if ($contentY === 0.5) {
                        $contentTransforms[] = 'translateY(-50%)';
                    }
                    if ($contentTransforms) {
                        $contentPosition .= ' transform: '.implode(' ', $contentTransforms).';';
                    }
                    $bottomBorderEnabled = (bool) $element->property('bottomBorderEnabled', false);
                    $bottomBorderGap = max(0, (float) $element->property('bottomBorderGap', 1.5));
                    $bottomBorderWidth = max(0.1, (float) $element->property('bottomBorderWidth', 0.4));
                    $bottomBorderLeftPadding = max(0, (float) $element->property('bottomBorderLeftPadding', 0));
                    $bottomBorderRightPadding = max(0, (float) $element->property('bottomBorderRightPadding', 0));
                    $bottomBorderColor = \Certigniter\CertificateRenderer\Support\ColorConverter::toCss(
                        $element->property('bottomBorderColor', $element->property('color')), $project->colorFormat, $color,
                    );
                    $bottomBorderStyle = $bottomBorderEnabled
                        ? sprintf(
                            ' padding-left: %s%s; padding-right: %s%s; padding-bottom: %s%s; border-bottom: %s%s solid %s;',
                            $bottomBorderLeftPadding, $unit,
                            $bottomBorderRightPadding, $unit,
                            $bottomBorderGap, $unit,
                            $bottomBorderWidth, $unit,
                            $bottomBorderColor,
                        )
                        : '';
                @endphp
                <div class="element text-element"
                    style="{{ $wrapperStyle }}
                        font-family: '{{ $fontFamily }}';
                        font-size: {{ $fontSizePt }}pt;
                        font-weight: {{ $isBold ? 700 : 400 }};
                        font-style: {{ $element->property('fontStyle', 'normal') === 'italic' ? 'italic' : 'normal' }};
                        color: {{ $color }};
                        text-align: {{ in_array($textAlign, ['left', 'center', 'right', 'justify'], true) ? $textAlign : 'left' }};
                        line-height: {{ $lineHeightPt }}pt;
                        letter-spacing: {{ $letterSpacing }}pt;
                        {{ $decorations ? 'text-decoration: '.implode(' ', $decorations).';' : '' }}
                        {{ $shadowCss }}
                        {{ $element->property('textResizeMode') === 'fixedSize' ? 'overflow: hidden;' : '' }}">
                    <div class="text-content" style="{{ $contentPosition }} margin-top: -{{ $baselineCorrectionPt }}pt;{{ $bottomBorderStyle }}">{!! nl2br(e((string) $element->property('text', ''))) !!}</div>
                </div>
            @elseif ($element->type === 'shape')
                @php
                    $shape = \Certigniter\CertificateRenderer\Support\ShapeRenderer::render($element, $project->colorFormat);
                    // A shadow can need room beyond the element's own raw
                    // bounds (see ShapeRenderer) - offsetX/offsetY (zero, or
                    // negative) re-anchor the wrapper so the shape itself
                    // still lands on its original, unpadded position.
                    $shapeWrapperStyle = sprintf(
                        'left: %s%s; top: %s%s; width: %s%s; height: %s%s; opacity: %s;',
                        $element->x + $shape['offsetX'], $unit, $element->y + $shape['offsetY'], $unit,
                        $shape['width'], $unit, $shape['height'], $unit,
                        $element->opacity(),
                    );
                    if (abs($element->rotation()) > 0.001) {
                        $shapeWrapperStyle .= sprintf(
                            ' transform: rotate(%sdeg); transform-origin: center;',
                            $element->rotation(),
                        );
                    }
                @endphp
                <img src="{{ $shape['src'] }}" class="element" style="{{ $shapeWrapperStyle }}">
            @elseif ($element->type === 'image' && (!empty($element->property('imageData')) || !empty($element->property('path'))))
                @php
                    $image = $imageSources[$element->id] ?? null;
                    $fit = $element->property('fit') === 'fill' ? 'fill' : 'contain';
                @endphp
                @if ($image)
                    @if ($fit === 'fill')
                        <img src="{{ $image['src'] }}" class="element" style="{{ $wrapperStyle }} width: {{ $element->width }}{{ $unit }}; height: {{ $element->height }}{{ $unit }};">
                    @else
                        @php
                            $imageRatio = $image['aspectRatio'];
                            $boxRatio = $element->height > 0 ? $element->width / $element->height : null;
                            if ($imageRatio && $boxRatio && $imageRatio > $boxRatio) {
                                $fittedWidth = $element->width;
                                $fittedHeight = $element->width / $imageRatio;
                            } elseif ($imageRatio) {
                                $fittedHeight = $element->height;
                                $fittedWidth = $element->height * $imageRatio;
                            } else {
                                $fittedWidth = $element->width;
                                $fittedHeight = $element->height;
                            }
                            [$contentX, $contentY] = $element->contentAlignmentFactors();
                            $fittedLeft = ($element->width - $fittedWidth) * $contentX;
                            $fittedTop = ($element->height - $fittedHeight) * $contentY;
                        @endphp
                        <div class="element" style="{{ $wrapperStyle }}">
                            <img src="{{ $image['src'] }}" style="position: absolute; left: {{ $fittedLeft }}{{ $unit }}; top: {{ $fittedTop }}{{ $unit }}; width: {{ $fittedWidth }}{{ $unit }}; height: {{ $fittedHeight }}{{ $unit }};">
                        </div>
                    @endif
                @endif
            @elseif ($element->type === 'qrcode' && isset($codeSources[$element->id]))
                <img src="{{ $codeSources[$element->id] }}" class="element" style="{{ $wrapperStyle }}">
            @elseif ($element->type === 'barcode' && isset($codeSources[$element->id]))
                <img src="{{ $codeSources[$element->id] }}" class="element" style="{{ $wrapperStyle }} width: {{ $element->width }}{{ $unit }}; height: {{ $element->height }}{{ $unit }};">
            @endif
        @endforeach
    </div>
</body>
</html>
