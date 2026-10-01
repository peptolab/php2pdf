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
`Position::percent(0.25, 0.8)` positions by fractions of the free space (horizontal, vertical;
vertical 1.0 is the top on both page orientations).

## Placement

`place()` returns a `Placement`:

- `bounds`: the box the object occupies on the canvas,
- `matrix`: the transform from object space,
- `clip`: the clip box, when the method clips,
- `scaleX`, `scaleY`: the total scale applied to the natural size,
- `overflows($box)`: whether part of the object lies outside the box.

Use `FitEngine::place($width, $height, $box, $fit, $origin)` directly to compute a placement
without drawing.
