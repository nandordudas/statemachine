# Diagrams

State diagrams of the README example and of `examples/`, made with [Archify](https://github.com/tt-a1i/archify)
(`lifecycle` diagrams).

| Diagram | Source | Images |
|---|---|---|
| Video player (README) | `src/video.lifecycle.json` | `video.light.png`, `video.dark.png` |
| Document approval | `src/document.lifecycle.json` | `document.light.png`, `document.dark.png` |
| Card payment | `src/payment.lifecycle.json` | `payment.light.png`, `payment.dark.png` |
| Order status groups | `src/order-status.lifecycle.json` | `order-status.light.png`, `order-status.dark.png` |

The README shows the light or dark image by the reader's colour scheme. The order status diagram shows the
7 status groups of `GroupRules`, not all 31 statuses.

To regenerate after changing an example: render the source with Archify
(`archify deliver lifecycle src/<name>.lifecycle.json <name>.html --quality showcase`), take light and dark
screenshots of the drawn states at 2× scale, and keep only the PNGs (the HTML is not committed).
