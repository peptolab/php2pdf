# The fit engine

Everything placeable (images, templates, imported pages, SVG artwork) goes through one engine:

```php
$placement = $page->place($object, Box::fromMm(20, 200, 80, 60), Fit::meet(Position::topLeft()));
```

## Fit methods

| Method | Effect |
|--------|--------|
| `Fit::noFit()` | Natural size (times `scale`), positioned in the box; may overflow |
| `Fit::clip()` | As `noFit`, clipped to the box |
| `Fit::meet()` | Scaled proportionally to fit entirely inside the box (the default) |
| `Fit::slice()` | Scaled proportionally to cover the box, clipped |
| `Fit::entire()` | Stretched to the box, aspect ratio not kept |
| `Fit::auto()` | `noFit` when the object fits, `meet` otherwise |

`Fit::meet(Position::center(), rotate: 90)` rotates before fitting; `Fit::noFit(scale: 0.5)`
scales the natural size. `withDpi(300)` overrides an image's own resolution when computing its
natural size.

## Positions

`Position::topLeft()` … `Position::bottomRight()` are the nine anchor points;
`new Position(0.25, 0.8)` positions by fractions of the free space and `Position::percent(25, 80)`
by percentages of it (horizontal, vertical; a vertical of 1.0, or 100 %, is the top on both page
orientations).

## Placement

`place()` returns a `Placement`:

- `bounds`: the box the object occupies on the canvas,
- `matrix`: the transform from object space,
- `clip`: the clip box, when the method clips,
- `scaleX`, `scaleY`: the total scale applied to the natural size,
- `overflows($box)`: whether part of the object lies outside the box.

Use `FitEngine::place($width, $height, $box, $fit, $origin)` directly to compute a placement
without drawing.

## Artwork of your own

Anything implementing `PlaceableInterface` can be placed like an image or a template, in a box,
a table cell or a line of text. `naturalSize()` gives its size in points and `drawAt()` draws it
in its own space (origin bottom-left, y up) through the placement matrix:

```php
final class Swatch implements PlaceableInterface
{
    public function __construct(private ColorInterface $color) {}

    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
    {
        $canvas->save()->concat($matrix);
        $canvas->setFillColor($this->color)->fillRect(0, 0, $size->width, $size->height);
        $canvas->restore();
    }

    public function naturalSize(?float $dpi = null): Dimensions
    {
        return new Dimensions(20, 10);
    }
}

$page->place(new Swatch(Cmyk::fromPercent(0, 60, 100, 0)), $box, Fit::meet());
```
