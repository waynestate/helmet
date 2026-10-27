# HELMET brand files

**The mark is an American football helmet in side view, facing right, with a gold face mask**, because HELMET is what
every app puts on before it ships. A white shell, a clear face opening, a jaw guard, one ear hole, and one gold detail:
the mask. It suits Wayne State's Warriors green and gold.

## Colours
| Role | Colour |
|---|---|
| Shell on a light background, tile | Wayne State green `#0C5449` |
| Face mask (the only gold) | Wayne State gold `#FFCC33` |
| Shell on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Berry `#8B2145` (WayneUI `functional.nonproduction`; HELMET owns the environment ribbon) |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `helmet-mark.svg` | The mark on light backgrounds: green shell, gold mask |
| `helmet-mark-white.svg` | The mark on dark or green backgrounds: white shell, gold mask |
| `helmet-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `helmet-tile.svg` | The app icon: a green rounded tile with the white helmet |
| `helmet-favicon.svg` | The tile simplified for 32 and 64 px: two mask bars instead of three, and thicker bars |
| `helmet-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel. The ear hole is a single pixel and the mask keeps one clear column between it and the shell |
| `helmet-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `helmet-mark-1024.png`, `helmet-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced.
- **The shell:** an ellipse 316 units wide and 292 tall, cut off by a flat lower edge with a small rounded nape. Under
  the brow the shell steps back to a straight face-opening edge, and a jaw guard reaches forward at the bottom.
- **The ear hole:** a circle of radius 19, cut out of the shell.
- **The mask:** three horizontal bars, 20 units thick with round ends, joined by a front upright that bows gently and
  leans forward at the bottom. The bars tilt 9 units down towards the front. The mask is the only gold, and it stays
  about 10 units clear of the shell so the two shapes never merge.
- **The mask is drawn with strokes** (round caps and joins) so the bars stay an even thickness. In the one-colour
  version both shapes use `currentColor`; the gap keeps them apart.

Two rules came out of earlier drafts. There is no symmetric dome with a vertical slot, because it read as the wrong
thing. And the front upright of the mask is never bent into a character shape such as a "3": it stays a gentle curve,
with the bars clearly horizontal.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape helmet-tile.svg --export-type=png --export-filename=helmet-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
