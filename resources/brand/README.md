# Brand assets

`worktrack-lockup.png` (1024×1536) is the master artwork. Everything the app serves is
derived from it, so it lives here rather than in `public/` — nothing renders this file
directly.

## Why there are two marks

The mascot is 725×989 of fine detail: arms, legs, a face, three checklist rows and a map
pin. Rendered at 16px it is an indistinct blue smudge, and a browser tab strip renders a
favicon at 16px. So small sizes crop to the **clipboard head**, which still reads as a
clipboard with a face at 16px, and the **full figure** is kept for the one place with room
for it — the signed-out pages.

| File | Mark | Used by |
| --- | --- | --- |
| `public/images/worktrack-mark.png` | full mascot, transparent | sign-in / pending / break-glass header |
| `public/images/worktrack-icon.png` | clipboard head, transparent | the app header chip (28px) |
| `public/favicon.ico` | clipboard head on a plate, 16/32/48 | browser tabs, bookmarks |
| `public/favicon-96.png` | clipboard head on a plate, 96px | browsers that prefer a PNG |
| `public/apple-touch-icon.png` | clipboard head on a plate, 180px | iOS home screen |

The favicons sit on a `bone-50` (`#fdfbf8`) plate rather than on transparency. The mascot's
limbs are near-black navy, which disappears against a dark tab bar; the plate guarantees
contrast in both browser themes and matches the app's own canvas.

## Regenerating

Source geometry, measured from the master by scanning for pixels that differ from the
cream background (`#fefbf6`) by more than 40 across RGB:

- wordmark — `x 65–979, y 212–365`
- mascot — `x 156–880, y 399–1387`
- clipboard head — `x 305–755, y 399–960`

The background must be cleared by flood-filling inward from the four corners, **not** by a
global transparent-paint: the clipboard's paper is `(252,252,247)` against a `(254,251,246)`
background, so any fuzz wide enough to catch the background edge also erases the mascot's
face. The paper is fully enclosed by the clipboard's blue frame, so a border flood fill
never reaches it.

The wordmark is currently unused — the app sets "Worktrack" as live text in Instrument Sans,
which stays crisp at any zoom. It is measured here in case a raster lockup is ever wanted.
