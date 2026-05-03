{{-- Shared CSS for the site-survey PDF Blade views (summary, blank, field-form). --}}
<style>
    /* PDF margins (and the running page-number footer) are set by
       Browsershot in SurveyPdfService — Chromium only partially supports
       CSS Paged Media's `@page { @bottom-right { content: counter(page) }}`,
       so we use Puppeteer's footerHtml with `<span class="pageNumber">`
       instead. */
    @page { size: A4; }
    body  { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; font-size: 9pt; color: #222; margin: 0; padding: 0; }
    h1    { color: #007B8A; font-size: 16pt; margin-bottom: 2pt; }
    h2    { color: #007B8A; font-size: 11pt; border-bottom: 1.5pt solid #007B8A; padding-bottom: 3pt; margin-top: 12pt; margin-bottom: 5pt; }
    h3    { font-size: 9pt; color: #007B8A; margin: 6pt 0 2pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4pt; border-bottom: 0.5pt solid #007B8A; padding-bottom: 1.5pt; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 6pt; font-size: 8pt; }
    th    { background: #007B8A; color: #fff; padding: 3pt 5pt; text-align: left; font-size: 7.5pt; }
    td    { padding: 3pt 5pt; border: 0.5pt solid #ccc; vertical-align: top; }
    tr:nth-child(even) td { background: #f0fbfc; }
    .label     { font-weight: bold; width: 30%; background: #f5f5f5; }
    .label-sm  { font-weight: bold; width: 22%; background: #f5f5f5; }
    .meta      { font-size: 8pt; color: #666666; margin-bottom: 10pt; }
    .page-break { page-break-before: always; }
    .field-box   { border: 0.5pt solid #bbb; min-height: 30pt; padding: 4pt; margin-bottom: 6pt; font-size: 8pt; color: #888; }
    .sketch-box  { border: 0.5pt solid #888; height: 140pt; padding: 4pt; margin-bottom: 8pt; font-size: 7.5pt; color: #999;
                   background-image: linear-gradient(#eee 1px, transparent 1px), linear-gradient(90deg, #eee 1px, transparent 1px);
                   background-size: 12pt 12pt; }
    .tick-list   { margin: 0; padding-left: 14pt; line-height: 1.35; }
    .tick-list li{ margin-bottom: 2pt; }
    .checkbox    { font-family: "DejaVu Sans"; }
    .inline-field{ display: inline-block; border-bottom: 0.5pt solid #999; min-width: 70pt; padding: 0 4pt; color: #333; }
    /* `.footer` previously used CSS Paged Media `position: running(footer)`
       which dompdf honoured but Chromium does not. The running footer is
       now passed to Browsershot via SurveyPdfService::buildXxx options. */
    .badge-yes   { color: #155724; font-weight: bold; }
    .badge-no    { color: #721c24; }
</style>
