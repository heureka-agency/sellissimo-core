<style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #202124; background: #f6f6f5; font: 1rem/1.5 system-ui, sans-serif; }
        main { max-width: 48rem; margin: 2rem auto; padding: clamp(1.25rem, 5vw, 3.5rem); background: white; border-radius: .75rem; box-shadow: 0 1px 6px #0001; }
        header, .delivery { display: flex; justify-content: space-between; gap: 1.5rem; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 1.75rem; line-height: 1.2; }
        h2 { font-size: .9rem; margin: 0 0 .4rem; }
        p { margin: .25rem 0; }
        address { font-style: normal; }
        button { align-self: start; background: white; border: 1px solid #999; border-radius: .4rem; padding: .5rem .85rem; font: inherit; cursor: pointer; }
        .delivery { margin: 2rem 0; }
        td, th { padding: .7rem .35rem; text-align: left; vertical-align: top; overflow-wrap: anywhere; border-bottom: 1px solid #ccc; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f5f6f7; font-size: .85rem; }
        td small { display: block; color: #565b62; }
        th:last-child, td:last-child { text-align: end; font-variant-numeric: tabular-nums; }
        td:last-child { font-weight: 650; }
        .pick-location { margin-block: 1.75rem; }
        .pick-location h2 { font-size: 1.1rem; break-after: avoid; }
        .pick-location td:first-child { font-family: ui-monospace, monospace; }
        table { table-layout: auto; }
        .print-actions { max-width: 48rem; margin: 1.5rem auto -1rem; padding-inline: 1rem; text-align: end; }
        button:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
        @media (max-width: 36rem) {
            main { margin: 0; padding: 1rem; }
            th, td { font-size: .85rem; padding: .5rem .25rem; }
        }
        @page { margin: 16mm; }
        @media print {
            body, main { background: white; }
            main { border-radius: 0; box-shadow: none; max-width: none; padding: 0; margin: 0; }
            main + main { break-before: page; }
            button, .print-actions { display: none; }
            tr { break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
