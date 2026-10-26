{{-- Shared look of the gradebook PDFs (report cards, transcript) — Khmer shaping needs the Noto font, see receipt.blade.php. --}}
<style>
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: normal; src: url('{{ $khmerFontRegular }}'); }
    @font-face { font-family: 'Noto Sans Khmer'; font-weight: bold; src: url('{{ $khmerFontBold }}'); }
    * { box-sizing: border-box; }
    body { font-family: 'Noto Sans Khmer', DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .head { display: table; width: 100%; border-bottom: 2px solid #b45309; padding-bottom: 8px; }
    .head .logo { display: table-cell; width: 70px; vertical-align: middle; }
    .head .logo img { max-height: 60px; max-width: 64px; }
    .head .school { display: table-cell; vertical-align: middle; }
    .head .school h1 { font-size: 16px; margin: 0; }
    .head .school p { margin: 2px 0 0; color: #4b5563; }
    .title { text-align: center; margin: 14px 0 10px; }
    .title h2 { font-size: 17px; margin: 0; letter-spacing: 1px; color: #b45309; }
    .title p { margin: 2px 0 0; color: #4b5563; }
    .info { display: table; width: 100%; margin-bottom: 10px; }
    .info div { display: table-cell; padding: 2px 0; }
    .info .k { color: #6b7280; width: 18%; }
    .info .v { font-weight: bold; width: 32%; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 4px 6px; }
    table.grid th { background: #f3f4f6; font-weight: bold; text-align: center; }
    table.grid td.num { text-align: center; }
    table.grid tr.total td { font-weight: bold; background: #fffbeb; }
    .fail { color: #dc2626; }
    .muted { color: #9ca3af; }
    .summary { display: table; width: 100%; margin-top: 10px; }
    .summary div { display: table-cell; text-align: center; border: 1px solid #e5e7eb; padding: 6px; }
    .summary .k { display: block; color: #6b7280; font-size: 10px; }
    .summary .v { display: block; font-size: 15px; font-weight: bold; }
    .sign { display: table; width: 100%; margin-top: 34px; }
    .sign div { display: table-cell; width: 50%; text-align: center; vertical-align: bottom; }
    .sign .line { border-top: 1px solid #9ca3af; width: 60%; margin: 40px auto 4px; }
    .note { margin-top: 10px; color: #6b7280; font-size: 9.5px; }
    h3 { font-size: 13px; margin: 14px 0 2px; color: #92400e; }
</style>
