# HELMET brand files

**The mark is an American football helmet in side view, facing right, with a gold face mask**, because HELMET is what
every app puts on before it ships. A white shell, a clear face opening, a jaw guard, one ear hole, and one gold detail:
the mask, bolted to the shell. It suits Wayne State's Warriors green and gold.

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
| `helmet-favicon.svg` | The tile simplified for 32 and 64 px: a thicker mask, no bolt holes, a larger ear hole |
| `helmet-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel. The ear hole is a single pixel, and both gold bars touch the white shell |
| `helmet-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `helmet-mark-1024.png`, `helmet-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced.
- **The shell:** an ellipse 316 units wide and 292 tall, cut off by a flat lower edge with a small rounded nape. Under
  the brow the shell steps back to a straight face-opening edge, and a jaw guard reaches forward at the bottom.
- **The ear hole:** a circle of radius 19, cut out of the shell.
- **The mask:** one gold stroke, 34 units thick with round ends. The top bar leaves the brow and runs forward, a front
  upright bows gently and leans forward, and the bottom bar runs under the chin and sweeps back up into the jaw guard.
  Both ends sit inside the shell, each with a small bolt hole, so the mask reads as a cage fixed to the helmet and not as
  a letter. In the one-colour version everything uses `currentColor`.
- **The face opening:** one open bay. The brow underside sweeps back and the jaw guard comes forward again; nothing
  frames it, and the background shows through.

Three rules came out of earlier drafts. There is no symmetric dome with a vertical slot, because it read as the wrong
thing. The mask never floats free of the shell, and it is never three bars closed by an upright on the right, because
that reads as the digit "3" or the letter "E". And the opening has no panel behind the mask, because a boxed panel
frames the mask like a glyph.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape helmet-tile.svg --export-type=png --export-filename=helmet-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
