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
               applied, so aligned text is placed by its real width. */
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
                    $curvedGlyphs = \Certigniter\CertificateRenderer\Support\TextElementStyle::curvedGlyphBoxes($element, $text, $fonts);
                @endphp
                @if ($curvedGlyphs !== [])
                {{-- Text on a curve: each glyph its own line box, turned onto the circle. No decoration or bottom border. --}}
                <div class="element text-element"
                    style="{{ $wrapperStyle }}
                        font-family: '{{ $text['fontFamily'] }}';
                        font-size: {{ $text['fontSizePt'] }}pt;
                        font-weight: {{ $text['fontWeight'] }};
                        font-style: {{ $text['fontStyle'] }};
                        color: {{ $text['color'] }};
                        line-height: {{ $text['lineHeightPt'] }}pt;
                        ">
                    @foreach ($text['shadowLayers'] as $layer)
                        <div style="position: absolute; left: {{ $layer['dx'] }}{{ $unit }}; top: {{ $layer['dy'] }}{{ $unit }}; width: 100%; height: 100%; color: {{ $layer['color'] }};">
                            @foreach ($curvedGlyphs as $glyph)
                                <div style="position: absolute; left: {{ $glyph['left'] }}mm; top: {{ $glyph['top'] }}mm; width: {{ $glyph['width'] }}mm; height: {{ $glyph['height'] }}mm; white-space: nowrap; transform: rotate({{ $glyph['rotation'] }}deg); transform-origin: center;">{{ $glyph['text'] }}</div>
                            @endforeach
                        </div>
                    @endforeach
                    @foreach ($curvedGlyphs as $glyph)
                        <div style="position: absolute; left: {{ $glyph['left'] }}mm; top: {{ $glyph['top'] }}mm; width: {{ $glyph['width'] }}mm; height: {{ $glyph['height'] }}mm; white-space: nowrap; transform: rotate({{ $glyph['rotation'] }}deg); transform-origin: center;">{{ $glyph['text'] }}</div>
                    @endforeach
                </div>
                @else
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
                        {{ $text['overflowCss'] }}">
                    @foreach ($text['shadowLayers'] as $layer)
                        {{-- The shadow covers the text and its underline, not the bottom border, whose padding still places the text. --}}
                        <div style="position: absolute; left: {{ $layer['dx'] }}{{ $unit }}; top: {{ $layer['dy'] }}{{ $unit }}; width: 100%; height: 100%; color: {{ $layer['color'] }};">
                            <div class="text-content" style="{{ $text['contentPositionCss'] }} margin-top: -{{ $text['baselineCorrectionPt'] }}pt;"><span style="{{ $text['bottomBorderSpanCss'] }}{{ $text['bottomBorderSpanCss'] !== '' ? ' border-bottom-color: transparent;' : '' }}">{!! nl2br(e((string) $element->property('text', ''))) !!}</span></div>
                        </div>
                    @endforeach
                    <div class="text-content" style="{{ $text['contentPositionCss'] }} margin-top: -{{ $text['baselineCorrectionPt'] }}pt;"><span style="{{ $text['bottomBorderSpanCss'] }}">{!! nl2br(e((string) $element->property('text', ''))) !!}</span></div>
                </div>
                @endif
            @elseif ($element->type === 'shape')
                @php
                    $shape = \Certigniter\CertificateRenderer\Support\ShapeRenderer::render($element, $project->colorFormat, \Certigniter\CertificateRenderer\Support\Units::pixelsPer($unit));
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
                    $mask = \Certigniter\CertificateRenderer\Support\ImageElementLayout::mask($element);
                @endphp
                @if ($image)
                    @php
                        $fitted = \Certigniter\CertificateRenderer\Support\ImageElementLayout::fit($element, $image['aspectRatio']);
                        $cropped = \Certigniter\CertificateRenderer\Support\ImageElementLayout::cropPlacement($element, $fitted);
                    @endphp
                    {{-- A picture is clipped to its box (a cover fit runs past it), then to its mask. --}}
                    <div class="element" style="{{ $wrapperStyle }} overflow: hidden;">
                        @if ($mask)
                            <div style="position: absolute; overflow: hidden; left: {{ $mask['left'] }}{{ $unit }}; top: {{ $mask['top'] }}{{ $unit }}; width: {{ $mask['width'] }}{{ $unit }}; height: {{ $mask['height'] }}{{ $unit }}; border-radius: {{ $mask['radius'] }}{{ $unit }};">
                            <div style="position: absolute; left: {{ -$mask['left'] }}{{ $unit }}; top: {{ -$mask['top'] }}{{ $unit }}; width: {{ $element->width }}{{ $unit }}; height: {{ $element->height }}{{ $unit }};">
                        @endif
                        @if ($cropped)
                            {{-- The whole picture, offset inside a box that clips it to the crop. --}}
                            <div style="position: absolute; overflow: hidden; left: {{ $fitted['left'] }}{{ $unit }}; top: {{ $fitted['top'] }}{{ $unit }}; width: {{ $fitted['width'] }}{{ $unit }}; height: {{ $fitted['height'] }}{{ $unit }};">
                                <img src="{{ $image['src'] }}" style="position: absolute; max-width: none; left: {{ $cropped['left'] }}{{ $unit }}; top: {{ $cropped['top'] }}{{ $unit }}; width: {{ $cropped['width'] }}{{ $unit }}; height: {{ $cropped['height'] }}{{ $unit }};">
                            </div>
                        @else
                            <img src="{{ $image['src'] }}" style="position: absolute; max-width: none; left: {{ $fitted['left'] }}{{ $unit }}; top: {{ $fitted['top'] }}{{ $unit }}; width: {{ $fitted['width'] }}{{ $unit }}; height: {{ $fitted['height'] }}{{ $unit }};">
                        @endif
                        @if ($mask)
                            </div>
                            </div>
                        @endif
                    </div>
                @endif
            @elseif ($element->type === 'qrcode' && isset($codeSources[$element->id]))
                <img src="{{ $codeSources[$element->id]['src'] }}" class="element" style="{{ $wrapperStyle }}">
            @elseif ($element->type === 'barcode' && isset($codeSources[$element->id]))
                @php($code = $codeSources[$element->id])
                <div class="element" style="{{ $wrapperStyle }}">
                    <img src="{{ $code['src'] }}" style="position: absolute; left: 0; top: 0; width: {{ $element->width }}{{ $unit }}; height: {{ $element->height }}{{ $unit }};">
                    @if ($code['caption'] !== null)
                        {{-- The caption's em box sits with its bottom on the box's bottom edge. --}}
                        <div style="position: absolute; left: 0; bottom: 0; width: {{ $element->width }}{{ $unit }}; text-align: center; white-space: nowrap; font-family: '{{ $fonts->resolveFamily('Roboto') }}'; font-weight: 400; font-size: {{ $code['fontSize'] }}{{ $unit }}; line-height: {{ $code['fontSize'] }}{{ $unit }}; color: {{ $code['color'] }};">{{ $code['caption'] }}</div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</body>
</html>
