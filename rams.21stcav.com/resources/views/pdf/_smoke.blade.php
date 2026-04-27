<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Smoke Test</title>
<style>
  @page { size: A4; margin: 20mm; }
  body { font-family: sans-serif; }
  h1 { color: #007B8A; }
</style></head><body>
<h1>PDF Smoke Test OK</h1>
<p>Generated {{ now()->toIso8601String() }} by Browsershot via chrome-headless-shell.</p>
<p>If you can read this PDF, the pipeline is alive.</p>
</body></html>
