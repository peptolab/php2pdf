# Canvas and colour

Every page and template has a `Canvas` that builds its content stream. Methods return the
canvas, so calls chain. Angles are in degrees.

## Graphics state

```php
$c = $page->canvas();
$c->save();                              // q
$c->translate(72, 72)->rotate(15)->scale(2)->skew(10, 0);
$c->concat(Matrix::rotation(45));        // any matrix
$c->setLineWidth(0.5)->setLineCap(LineCap::Round)->setLineJoin(LineJoin::Miter)->setMiterLimit(4);
$c->setDash([3, 2], 0);
$c->setOpacity(0.5);                     // fill and stroke alpha; setOpacity(fill, stroke) for both
$c->setBlendMode(BlendMode::Multiply);
$c->enableOverprint();                   // overprint fills and strokes, OPM 1
$c->restore();                           // Q
```

`ctm()` returns the current transformation and `origin()` the coordinate mode.

## Paths

```php
$c->moveTo(10, 10)->lineTo(100, 10)->curveTo(120, 30, 120, 60, 100, 80)->closePath();
$c->rect(x, y, w, h); $c->roundedRect(x, y, w, h, radius); $c->box($box);
$c->circle(cx, cy, r); $c->ellipse(cx, cy, rx, ry); $c->arc(cx, cy, r, 0, 90); $c->line(x1, y1, x2, y2);
$c->fill(); $c->fill(FillRule::EvenOdd); $c->stroke(); $c->closeAndStroke(); $c->fillAndStroke();
$c->clip(); $c->endPath();               // clip to the path, or discard it
$c->fillRect(x, y, w, h); $c->strokeRect(x, y, w, h);
$c->raw('0 0 m 10 10 l S');              // raw content-stream syntax when you need it
```

## Colour

```php
use PhpPdf\Color\{Gray, Rgb, Cmyk, Spot, IccProfile, IccColor};

$c->setFillColor(Gray::black());         // or new Gray(0.5)
$c->setStrokeColor(Rgb::fromHex('#c2185b'));         // Rgb::from255(194, 24, 91), new Rgb(0.76, 0.09, 0.36)
$c->setColor(Cmyk::fromPercent(100, 44, 0, 0));      // sets fill and stroke
$c->setFillColor(new Spot('PANTONE 281 U', Cmyk::fromPercent(100, 72, 0, 32), tint: 0.6));
$c->setFillColor(new IccColor(IccProfile::fromFile('sRGB.icc'), 0.2, 0.4, 0.6));
```

Spot colours become Separation colour spaces with a tint transform to the alternate; ICC
colours become ICCBased spaces. Both are written once per document.

## Gradients

```php
use PhpPdf\Color\{LinearGradient, RadialGradient, GradientStop};

$g = new LinearGradient(0, 0, 200, 0, [new GradientStop(0, Rgb::fromHex('#ff7043')), new GradientStop(1, Rgb::fromHex('#66bb6a'))]);
$r = RadialGradient::circle(100, 100, 80, $stops);   // or new RadialGradient(x0, y0, r0, x1, y1, r1, $stops)

$c->setFillGradient($g)->rect(0, 0, 200, 50)->fill();  // coordinates in the current user space
$c->rect(0, 0, 200, 50)->clip()->paintGradient($g);    // paint over the clip region instead
$c->setStrokeGradient($g);
```

All stops of a gradient share a colour space (Gray, Rgb or Cmyk). `extend: false` stops the
colour at the first and last stop instead of padding. A matrix passed as the second argument of
`setFillGradient()` maps gradient coordinates into user space, which is how the SVG converter
places gradients in object bounding box units.

A gradient can also repeat or reflect beyond its vector, as SVG's `spreadMethod` does. PDF
shadings only pad, so give the stretch of the vector to cover, in lengths of the vector:

```php
// The 200-point vector repeated from one length before its start to three lengths past it.
$g = new LinearGradient(0, 0, 200, 0, $stops, spread: GradientSpread::Repeat, from: -1, to: 4);
```

## Soft masks

A template's artwork can mask what is drawn after it, until `restore()`: by its luminosity, so
white shows and black hides, or by its opacity. The template lies in the current user space and
is made a transparency group; outside its artwork everything is hidden.

```php
$fade = $doc->template(new Dimensions(200, 100));
$fade->canvas()->setFillGradient(new LinearGradient(0, 0, 200, 0, [new GradientStop(0, Gray::white()), new GradientStop(1, Gray::black())]))->rect(0, 0, 200, 100)->fill();

$c->save()->setSoftMask($fade)->place($photo, new Box(0, 0, 200, 100), Fit::slice());
$c->restore();
```

macOS Preview places soft masks wrongly inside artwork that is itself placed scaled or moved (it
applies that placement twice); Acrobat, poppler and Ghostscript follow the PDF specification.

## Text at a position

```php
$c->drawText('Hello', $font, 12, 72, 700);                      // baseline at (72, 700)
$c->drawText('Hello', $font, 12, 72, 700, new TextOptions(tracking: 20, renderMode: TextRenderMode::Stroke));
```

See [fonts-and-text.md](fonts-and-text.md) for fonts, metrics and fitted lines, and
[textflow.md](textflow.md) for flowing text.
