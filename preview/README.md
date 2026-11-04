# Diagrams

State diagrams of the README example and of `examples/`, made with [Archify](https://github.com/tt-a1i/archify)
(`lifecycle` diagrams).

| Diagram | Source | Interactive |
|---|---|---|
| Video player (README) | `src/video.lifecycle.json` | `video.html` |
| Document approval | `src/document.lifecycle.json` | `document.html` |
| Card payment | `src/payment.lifecycle.json` | `payment.html` |
| Order status groups | `src/order-status.lifecycle.json` | `order-status.html` |

Each diagram has a `*.light.png` and a `*.dark.png`, cropped to the drawn states; the README picks one by the
reader's colour scheme. The order status diagram shows the 7 status groups of `GroupRules`, not all 31 statuses.

Regenerate after changing an example:

```
archify deliver lifecycle src/<name>.lifecycle.json <name>.html --quality showcase
archify visual-check <name>.html
```

then re-take the PNGs from the HTML (light and dark colour scheme, at 2× scale, cropped to the states and
transitions).
