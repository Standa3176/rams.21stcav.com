# draw.io vendored bundle

**Source:** https://github.com/jgraph/drawio
**Version (tag):** v29.7.12
**Downloaded:** 2026-05-09
**Downloaded by:** Quick task 260509-ibx (draw.io embed spike)
**License:** Apache 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
**License caveat:** mxGraph (the underlying library) is also Apache 2.0,
but JGraph's commercial library has a clause restricting use in
"competing diagram editor products". Internal AV-tool use is fine; flag
if 21CAV ever spins out a tools-as-product business.

## Update procedure

Manual review + replace, NEVER auto-update.

1. Read the upstream CHANGELOG between the current pinned tag and the new
   tag, paying attention to any breaking changes in mxGraph stencil
   schema, postMessage protocol, or embed.html query parameters.
2. Re-run the spike's stencil pack through the new version locally before
   replacing on the server.
3. Replace the contents of public/vendor/drawio/ with the new bundle.
4. Update Version + Downloaded + Downloaded by in this file.
5. Smoke-test the spike route in a fresh browser session (cache busted).

## What's in this folder

- embed.html — entry point loaded by the iframe in
  resources/views/admin/drawings/draw-io-spike.blade.php (copy of
  index.html — recent draw.io versions no longer ship a separate
  embed.html file; the same entry handles `?embed=1&proto=json`)
- index.html — original entry (kept alongside embed.html for parity with
  upstream)
- js/, styles/, images/, img/, resources/, shapes/, stencils/, mxgraph/,
  connect/, plugins/ — supporting assets the entry needs at runtime.
- favicon.ico, open.html, export3.html, PreConfig.js, PostConfig.js —
  ancillary entries used by some assets.

## What's NOT in this folder

- Server-side Java sources (war/, etc/) from the upstream `WEB-INF` /
  `META-INF` trees — the iframe-embed flow has no need for them.
- Build tooling (Gruntfile, package.json from upstream).
- Documentation, tests, screenshots from the upstream repo.
- `js/mermaid/` — separate Mermaid diagram-tool integration (~3 MB);
  not used by AV schematic flows. Drop is documented here so a future
  upgrade adding Mermaid features back will know to re-vendor it.
- `math4/` — separate maths-rendering helper. Not used by AV flows.

The intent is to ship the smallest possible runtime subset that lets
`/vendor/drawio/embed.html` load fully in an iframe.

## Bundle size

~132 MB on disk. ~70% of that is the `js/diagramly/` unminified source
tree (which `app.min.js` re-bundles minified) — kept intact to avoid
subtle runtime 404s if any code path falls back to the unminified files.
A future v2.0 hardening could trim this further by tracing exactly which
files `app.min.js` depends on at runtime.

Repo-size hit is intentional per D-LOCK-1 in CONTEXT.md (downloaded
once, committed to repo, reproducibility wins forever).

## Embed mode usage

```
<iframe src="/vendor/drawio/embed.html?embed=1&proto=json&libraries=1"></iframe>
```

The parent page sends mxGraph XML via postMessage; the iframe sends
edit / save / export events back. See:
https://www.drawio.com/doc/faq/embed-mode

## Spike scope

This bundle was vendored as part of a 2-week build-vs-buy spike (quick
task 260509-ibx) to evaluate draw.io / mxGraph as the engine for the
v2.0 engineering-grade drawings milestone. If the spike succeeds, this
folder graduates to a permanent dependency; if it fails, the folder is
deleted and the v2.0 milestone falls back to evaluating Lucidchart API
or XTEN-AV.
