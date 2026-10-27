# HELMET brand files

**The mark is an American football helmet in side view, facing right, with a gold face mask**, because HELMET is what
every app puts on before it ships. A white shell with a big round crown, a brow that juts forward, a jaw flap, one ear hole, and one gold detail:
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
| `helmet-favicon.svg` | The tile simplified for 32 and 64 px: a shorter top arm, two cross bars and a thicker stroke |
| `helmet-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel. The shell keeps the brow lip and jaw flap, the ear hole is a single pixel, and the cage is a 4 by 4 gold block with one dark slit, touching the shell front |
| `helmet-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `helmet-mark-1024.png`, `helmet-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced. The numbers below are in the units of the board's standalone mark
(a 470 by 400 crop), which is how the drawing was checked: the silhouette was overlaid on the board and matched to a few
pixels.
- **The shell:** a big round crown. The brow juts forward to a point, and a concave curve cuts back and down into the face
  opening. At the back the shell curls under at the nape, a small wave follows, and a jaw flap drops below the ear hole
  and reaches forward to a rounded tip under the face. The lower edge is not flat.
- **The ear hole:** a circle of radius 29, cut out of the shell.
- **The mask:** one gold stroke, 28 units thick with round ends. A rear upright runs parallel to the face-opening edge
  with an even gap from the shell. A top arm curls forward from it towards the brow, two bars cross to a front upright,
  and a bottom bar slants down to the front. The closed grid of uprights and bars is what makes it a cage and not a
  letter. In the one-colour version everything uses `currentColor`.

Rules that came out of earlier drafts. There is no symmetric dome with a vertical slot, because it read as the wrong
thing. The silhouette keeps the brow, the jaw flap and the uneven lower edge, because a plain half-dome with a flat base
drifts back toward the rejected dome. The mask is never open on one side with a single upright, because that reads as the
digit "3" or the letter "E". It has no clips poking back into the shell, because with the top arm they read as a "G" at
32 px.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape helmet-tile.svg --export-type=png --export-filename=helmet-avatar-512.png -w 512 -h 512`. The `.ico` was
built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
