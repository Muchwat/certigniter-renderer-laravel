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

        .fit-contain {
            display: table;
            width: 100%;
            height: 100%;
        }

        .fit-contain-cell {
            display: table-cell;
            vertical-align: middle;
            text-align: center;
        }

        .fit-contain img {
            max-width: 100%;
            max-height: 100%;
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
                    $fontSizePt = ((float) $element->property('fontSize', 14.0)) * 0.75; // canvas px (96dpi) -> pt (72dpi)
                    $color = \Certigniter\CertificateRenderer\Support\ColorConverter::toCss(
                        $element->property('color'), $project->colorFormat, '#000000',
                    );
                    $textAlign = $element->property('textAlign', 'left');
                    $lineHeight = (float) $element->property('lineHeight', 1.2);
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
                @endphp
                <div class="element"
                    style="{{ $wrapperStyle }}
                        font-family: '{{ $fontFamily }}';
                        font-size: {{ $fontSizePt }}pt;
                        font-weight: {{ $isBold ? 700 : 400 }};
                        font-style: {{ $element->property('fontStyle', 'normal') === 'italic' ? 'italic' : 'normal' }};
                        color: {{ $color }};
                        text-align: {{ in_array($textAlign, ['left', 'center', 'right', 'justify'], true) ? $textAlign : 'left' }};
                        line-height: {{ $lineHeight }};
                        letter-spacing: {{ $letterSpacing }}{{ $unit }};
                        {{ $decorations ? 'text-decoration: '.implode(' ', $decorations).';' : '' }}
                        {{ $shadowCss }}
                        {{ $element->property('textResizeMode') === 'fixedSize' ? 'overflow: hidden;' : '' }}">
                    {!! nl2br(e((string) $element->property('text', ''))) !!}
                </div>
            @elseif ($element->type === 'image' && (!empty($element->property('imageData')) || !empty($element->property('path'))))
                @php
                    $src = $imageSources[$element->id] ?? null;
                    $fit = $element->property('fit') === 'fill' ? 'fill' : 'contain';
                @endphp
                @if ($src)
                    @if ($fit === 'fill')
                        <img src="{{ $src }}" class="element" style="{{ $wrapperStyle }} width: {{ $element->width }}{{ $unit }}; height: {{ $element->height }}{{ $unit }};">
                    @else
                        <div class="element fit-contain" style="{{ $wrapperStyle }}">
                            <div class="fit-contain-cell"><img src="{{ $src }}"></div>
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
