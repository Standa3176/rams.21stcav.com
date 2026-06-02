<?php

/*
|--------------------------------------------------------------------------
| Engineer Reference Files (quick task 260601-r4c)
|--------------------------------------------------------------------------
|
| Configurable allowlist / denylist for ProjectReferenceFileService::store.
| Centralised here so tests can override via config() without monkey-
| patching the service.
|
| - allowed_mimes: finfo-sniffed MIME types accepted on upload. Some
|   formats (DWG/DXF, CSV) are commonly mis-sniffed; the service
|   compensates via the extension allowlist when MIME is octet-stream
|   or text/plain.
| - allowed_extensions: case-insensitive extension allowlist. An
|   extension OUTSIDE this list is rejected even if the MIME passes.
| - deny_extensions: defence-in-depth blocklist that WINS over the
|   allowlist + MIME — any file whose extension is in this set is
|   rejected regardless of how it sniffs.
| - max_size_bytes: per-file upload cap (default 20 MB).
|
*/

return [

    'allowed_mimes' => [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/webp',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
        'application/vnd.ms-excel', // xls
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
        'application/msword', // doc
        'text/csv',
        'text/plain', // CSV is often sniffed as text/plain
        'application/octet-stream', // DWG/DXF commonly sniff as octet-stream — extension-gated in service
        'application/zip', // XLSX/DOCX sometimes sniff as bare zip — extension-gated in service
        'image/vnd.dwg',
        'application/acad',
        'application/dxf',
    ],

    'allowed_extensions' => [
        'pdf', 'png', 'jpg', 'jpeg', 'webp',
        'dwg', 'dxf',
        'xlsx', 'xls',
        'docx', 'doc',
        'csv',
    ],

    'deny_extensions' => [
        // Web markup / scripting
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml',
        'js', 'mjs', 'cjs',
        // Server-side / shell
        'php', 'phtml', 'phar',
        'sh', 'ps1', 'bat', 'cmd',
        // Executables / installers
        'exe', 'msi', 'com', 'scr', 'vbs', 'jar',
        // Generic-script
        'py', 'rb', 'pl',
    ],

    'max_size_bytes' => 20 * 1024 * 1024, // 20 MB

];
