# SVG

```php
$art = $doc->svg('logo.svg');                                     // native converter
$art = $doc->svg(SvgFile::fromData($svgString), new NativeConverter(fallbackFont: $sans));
$art = $doc->svg('figure.svg', new RsvgConverter());             // librsvg, when rsvg-convert is installed
$page->place($art, Box::fromMm(20, 200, 80, 60), Fit::meet());
```

The result is placeable like any template. The same file converts once per converter.
`SvgFile::size()` gives the intrinsic size in points (CSS pixels at 96 dpi, so 0.75 pt each).

## The native converter

Pure PHP, no extensions. It supports:

- paths with every command, rectangles (rounded), circles, ellipses, lines, polylines, polygons;
- transforms, `viewBox` with `preserveAspectRatio`, nested `<svg>` viewports, `<symbol>` and `<use>`;
- fill and stroke colours: named, `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `hsl()`
  and `hsla()` in comma or space syntax, with any alpha applied as opacity; `fill-rule`,
  `fill-opacity`, `stroke-opacity`,
  line width, caps, joins, miter limit, dashes;
- linear and radial gradients in object bounding box or user space units, `gradientTransform`,
  `href` inheritance, `spreadMethod` pad, repeat and reflect;
- clip paths (user space and object bounding box), including nested ones;
- group `opacity` through transparency groups; `display` and `visibility`;
- presentation attributes, `style` attributes and `<style>` sheets with type, class, id and
  compound selectors (no combinators or pseudo-classes);
- `<image>` with PNG or JPEG data URIs or files inside the SVG's directory (absolute paths and
  references leading out of it are refused with a warning, and SVG read from a string reads no
  files unless `SvgFile::fromData()` is given a directory);
- `<text>` and `<tspan>` with positions, `dx`/`dy`, `text-anchor`, `letter-spacing`, weight and
  style, set through the font engine so the text stays searchable. Font families are looked up
  in the document's registry (`$doc->fonts()->addSearchPath(...)`); generic and unknown families
  use the converter's fallback font, and without one such text is skipped.

Masks (`<mask>`, by luminance or `mask-type="alpha"`, with `maskUnits` and `maskContentUnits`)
become soft masks; macOS Preview draws them in the wrong place in placed artwork (see
[canvas.md](canvas.md)). Filters, patterns, markers, `<textPath>`, `<foreignObject>` and stroked text
are skipped. `NativeConverter::warnings()` lists what the last conversion left out.

## The rsvg-convert adapter

`RsvgConverter` runs `rsvg-convert --format=pdf` and imports the resulting page through the PDF
importer, giving librsvg's full fidelity including masks and filters. `isAvailable()` tells
whether the binary can be run; pass a path to the constructor for a non-standard location.
