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
                $transformParts = [];
                if (abs($element->rotation()) > 0.001) {
                    $transformParts[] = sprintf('rotate(%sdeg)', $element->rotation());
                }
                if ($element->mirrorHorizontal() || $element->mirrorVertical()) {
                    $transformParts[] = sprintf(
                        'scale(%s,%s)',
                        $element->mirrorHorizontal() ? -1 : 1,
                        $element->mirrorVertical() ? -1 : 1,
                    );
                }
                $elementTransformStyle = $transformParts
                    ? ' transform: '.implode(' ', $transformParts).'; transform-origin: center;'
                    : '';
                $wrapperStyle .= $elementTransformStyle;
            @endphp

            @if ($element->isTextLike())
                @php
                    $text = \Certigniter\CertificateRenderer\Support\TextElementStyle::describe(
                        $element, $project->colorFormat, $unit, $fonts,
                    );
                @endphp
                <div class="element text-element"
                    style="{{ $wrapperStyle }}
                        font-family: '{{ $text['fontFamily'] }}';
                        font-size: {{ $text['fontSizePt'] }}pt;
                        font-weight: {{ $text['fontWeight'] }};
                        font-style: {{ $text['fontStyle'] }};
                        color: {{ $text['color'] }};
                        text-align: {{ $text['textAlign'] }};
                        line-height: {{ $text['lineHeightPt'] }}pt;
                        letter-spacing: {{ $text['letterSpacing'] }}pt;
                        {{ $text['decorationCss'] }}
                        {{ $text['shadowCss'] }}
                        {{ $text['overflowCss'] }}">
                    <div class="text-content" style="{{ $text['contentPositionCss'] }} margin-top: -{{ $text['baselineCorrectionPt'] }}pt;"><span style="{{ $text['bottomBorderSpanCss'] }}">{!! nl2br(e((string) $element->property('text', ''))) !!}</span></div>
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
                    $shapeWrapperStyle .= $elementTransformStyle;
                @endphp
                <img src="{{ $shape['src'] }}" class="element" style="{{ $shapeWrapperStyle }}">
            @elseif ($element->type === 'image' && (!empty($element->property('imageData')) || !empty($element->property('path'))))
                @php
                    $image = $imageSources[$element->id] ?? null;
                    $maskStyle = \Certigniter\CertificateRenderer\Support\ImageElementLayout::maskCss($element, $unit);
                @endphp
                @if ($image)
                    @php
                        $fitted = \Certigniter\CertificateRenderer\Support\ImageElementLayout::fit($element, $image['aspectRatio']);
                    @endphp
                    <div class="element" style="{{ $wrapperStyle }}{{ $maskStyle }}">
                        <img src="{{ $image['src'] }}" style="position: absolute; left: {{ $fitted['left'] }}{{ $unit }}; top: {{ $fitted['top'] }}{{ $unit }}; width: {{ $fitted['width'] }}{{ $unit }}; height: {{ $fitted['height'] }}{{ $unit }};">
                    </div>
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
