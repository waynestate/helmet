# HELMET brand files

**The mark is a helmet seen from the front: a dome with one vent slot, and a gold brim**, because HELMET is what every
app puts on before it ships. One slot, one brim, nothing else.

## Colours
| Role | Colour |
|---|---|
| Dome, tile | Wayne State green `#0C5449` |
| Brim (the only gold) | Wayne State gold `#FFCC33` |
| Dome on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Berry `#8B2145` (WayneUI `functional.nonproduction`; HELMET owns the environment ribbon) |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `helmet-mark.svg` | The mark on light backgrounds: green dome, gold brim |
| `helmet-mark-white.svg` | The mark on dark or green backgrounds: white dome, gold brim |
| `helmet-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `helmet-tile.svg` | The app icon: a green rounded tile with the white helmet |
| `helmet-favicon.svg` | The tile simplified for 32 and 64 px: a wider slot and a thicker brim |
| `helmet-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel. The slot is left out here: through the crown it turns the dome into two ears, and closed it looks like a window |
| `helmet-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `helmet-mark-1024.png`, `helmet-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced.
- **The dome:** the top half of an ellipse, 296 units wide and 172 tall, on a flat base.
- **The slot:** 30 units wide on the centre line, from the crown down to 46% of the dome's height, with a round end.
- **The brim:** 36 units tall with fully round ends, 20 below the dome and 8 wider than it on each side.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape helmet-tile.svg --export-type=png --export-filename=helmet-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
