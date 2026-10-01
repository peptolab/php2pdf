# Textflow

A `Textflow` is styled text that flows through chained boxes. Each fit places as much as fits
and remembers where it stopped; you decide where the rest goes: another column, the next page,
a different template.

```php
$flow = $doc->textflow($plain, 'body');                       // plain text (default)
$flow = $doc->textflow($markup, 'body', TextFormat::Markup);   // inline markup
do {
    $page   = $doc->addPage(PageSize::A4);
    $result = $page->fitTextflow($flow, Box::fromMm(15, 20, 85, 250));
    if (FlowResult::BoxFull === $result) {
        $result = $page->fitTextflow($flow, Box::fromMm(110, 20, 85, 250));
    }
} while (FlowResult::Done !== $result);
```

`Document::flow($flow, $boxes, $makePage)` runs that loop for you (see
[tables.md](tables.md)).

Plain text is printed exactly as given. Markup is opt-in: put application data into markup with
`Markup::escape()`, so a name like `Tom <Jerry>` or `R&amp;D` is not read as a tag or an entity.
`TextFormat::Markup` drops tags it does not know; `TextFormat::StrictMarkup` throws instead.

```php
$flow = $doc->textflow('<p style="h2">' . Markup::escape($client->name) . '</p>', 'body', TextFormat::Markup);
```

## Styles

`TextStyle` is a sheet of optional properties; unset ones inherit from the style it is layered
on, and finally from the defaults (10 pt, 120 % leading, black, left aligned, no hyphenation).

```php
$styles = $doc->styles();
$styles->define('body', new TextStyle(font: $sans, size: 9.5, leading: Leading::pt(13.5), bold: $bold, italic: $italic));
$styles->define('h2', new TextStyle(font: $bold, size: 14, spaceAfter: 6, keepWithNext: true), basedOn: 'body');
$styles->define('quote', new TextStyle(leftIndent: 20, rightIndent: 20, alignment: Alignment::Justify), basedOn: 'body');
```

Properties: `font` (a `Font` or a registry name), `size`, `leading` (`Leading::pt()` or
`Leading::percent()`), `color`, `tracking`, `charSpacing`, `wordSpacing`, `horizontalScale`,
`rise`, `renderMode`, `underline`, `strikeout`, `kerning`, `bold`/`italic`/`boldItalic` (the
faces `<b>` and `<i>` switch to), `alignment` (Left, Right, Center, Justify),
`lastLineAlignment`, `firstLineIndent`, `leftIndent`, `rightIndent`, `spaceBefore`,
`spaceAfter`, `hyphenate`, `language`, `minHyphenatedWordLength`, `hyphenLeftMin`,
`hyphenRightMin`, `tabs` (a list of `TabStop::left/right/center/decimal($position)`, each with
an optional leader),
`defaultTabWidth`, `keepWithNext`, `keepTogether`, `orphans`, `widows`, `fallback` (fonts for
characters the font lacks), `ligatures` and `features` (OpenType features by tag; see
[fonts-and-text.md](fonts-and-text.md)).

A tab stop can carry a leader, repeated across the space the tab jumps: dots to a page number
in a contents list, for example. Its repeats sit on a grid of their own width from the start of
the line, so the dots on successive lines line up.

```php
$styles->define('toc', new TextStyle(tabs: [TabStop::left(20), TabStop::right(260, leader: '.')]), basedOn: 'body');
$flow = $doc->textflow("1\tIntroduction\t1\n2\tGetting started\t4", 'toc');
```

## Markup

A small tag set, with `style="name"` on `<p>` and `<span>` referring to named styles:

| Tag | Meaning |
|-----|---------|
| `<p>` … `</p>`, `<p style="h2">` | A paragraph; text outside `<p>` forms paragraphs separated by blank lines |
| `<span style="x">`, `<b>`, `<i>`, `<u>`, `<s>`, `<sup>`, `<sub>` | Inline style changes |
| `<a href="https://…" title="tip">`, `<a href="#name">` | A link to a URL or a named destination, with an optional tooltip (see below) |
| `<span match="name">` | Text whose areas `lastFit()->matchboxes['name']` reports |
| `<br>` | Line break |
| `<tab>` | Tab to the next tab stop |
| `<columnbreak>` | Stop filling this box: the fit returns `BoxFull` |
| `<pagebreak>` | Stop and ask for a new page: the fit returns `NextPage` |
| `<img src="name" width height raise>` | Artwork defined with `$doc->inlines()->define()`, set in the text (see below) |

`&amp;`, `&lt;`, `&gt;` and numeric entities are decoded. Soft hyphens (U+00AD) mark allowed
breaks; non-breaking spaces (U+00A0, U+202F narrow and U+2007 figure) never break or stretch.

Lines also break after a hard hyphen, U+2010 hyphen, en dash or slash (unless a digit follows,
so "10-12" stays whole) and on either side of an em dash, adding no hyphen. En, em, thin, hair
and the other fixed spaces (U+2002–U+2006, U+2008–U+200A) keep their width when a line is
justified, allow a break after them, and are dropped at the end of a line; a font without the
glyph sets a word space in its place. Pattern hyphenation looks past punctuation around a word,
so "(typography," hyphenates.

Plain text (`TextFormat::Plain`) treats blank lines as paragraph breaks, single line ends as
line breaks, and tabs as tabs.

## The builder

```php
$flow = Textflow::build($doc, 'body')
    ->paragraph('h2')->text('Heading')
    ->paragraph()->text('Body with ')->bold('bold')->text(' and ')->italic('italic')->text('.')
    ->lineBreak()->text('Second line')->tab()->text('after a tab')
    ->pageBreak()
    ->build();
```

## Fitting

`fitTextflow($flow, $box, ?FlowOptions)` returns `FlowResult::Done`, `BoxFull` (more text
remains), `BoxEmpty` (not even one line fitted) or `NextPage` (a page break was reached).

`FlowOptions` sets the first baseline (`FirstBaseline::Ascender` by default; `CapHeight`,
`XHeight` or `Leading`), the vertical alignment of a flow that fits entirely (`Top`, `Center`,
`Bottom`), and copy fitting: `FlowOptions::shrinkToFit(0.6)` reduces the type size (with leading,
indents and spacing) uniformly, down to 60 % of the original, until the remaining text fits.

Widows and orphans, `keepWithNext` and `keepTogether` decide where a paragraph may be cut
between boxes.

## Queries

```php
$flow->isDone();              $flow->remainingText();         $flow->reset();
$flow->measure($width);       // height the remaining text would take at a width, without placing it
$info = $flow->lastFit();     // FitInfo: result, height, lineCount, firstBaseline, lastBaseline, scale, textEnd, matchboxes, overflowed()
```

`textEnd` is the canvas point where the last placed line's text stops, on its baseline (PDFlib's
`textendx`/`textendy`), for continuing inline, such as placing an icon after the text:

```php
[$x, $y] = $flow->lastFit()->textEnd;
$page->place($arrow, new Box($x + 2, $y, 8, 8), Fit::meet());
```

## Links and matched text

Linked text becomes a link over each line's piece of it when the flow is fitted on a page, from
the fonts' descenders to their ascenders. A link in artwork (a template) is not made, as PDF
artwork cannot carry links; under PDF/X links must lie outside the printed area, so linked text
there is refused when the page is finished. Style links as you like; they draw nothing.

```php
$flow = $doc->textflow('<p>See <a href="https://example.com/" title="Opens a browser">our site</a> or <a href="#prices">the prices</a>.</p>', 'body', TextFormat::Markup);
$flow = Textflow::build($doc, 'body')->text('See ')->link('https://example.com/', 'our site')->build();
```

Text marked with a match name reports its areas after each fit (PDFlib's matchboxes), one box
per line it is set on, in canvas coordinates, to draw a highlight or a frame or to add a link
of your own:

```php
$flow = $doc->textflow('<p>Total <span match="total">€ 1,250</span></p>', 'body', TextFormat::Markup);
$page->fitTextflow($flow, $box);
foreach ($flow->lastFit()->matchboxes['total'] ?? [] as $area) {
    $page->canvas()->setStrokeColor(Rgb::fromHex('#c2185b'))->rect($area->x, $area->y, $area->width, $area->height)->stroke();
}
```

The `link`, `linkTooltip` and `match` properties of `TextStyle` do the same for a whole style.

## Artwork in the text

Images, templates and SVG artwork can sit in a line like a word, their bottom edge on the
baseline:

```php
$doc->inlines()->define('phone', $doc->image('phone.png'));
$flow = $doc->textflow('Call us <img src="phone" height="9"> any day', 'body', TextFormat::Markup);

Textflow::build($doc, 'body')->text('Call us ')->inline($phone, height: 9.0)->text(' any day');
```

`width` and `height` are in points; give one and the other keeps the artwork's proportions, give
both to set each, or neither for one em high. `raise` moves it up (or down, negative) from the
baseline. Artwork attaches to the words around it unless spaces separate them, never splits, and
goes on a line of its own when wider than the line. Artwork rising above the text pushes its line
down by the difference, as auto leading does, and copy fitting scales it with the text.

## Hyphenation

Pattern files are not bundled. Load TeX (`\patterns{...}`) or hunspell (`hyph_*.dic`) files
and pass hyphenators by language code; styles with `hyphenate: true` and a matching `language`
use them:

```php
$en   = Hyphenator::fromFile('hyph-en-gb.tex', 'en');
$flow = $doc->textflow($text, 'body', hyphenators: ['en' => $en]);
```

`Hyphenator::hyphenate('hyphenation')` returns break positions and `syllables()` the pieces.
