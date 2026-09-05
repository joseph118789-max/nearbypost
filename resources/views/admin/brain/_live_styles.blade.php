{{-- Shared by the Live screen and the day it opens, so the two tables cannot
     drift apart visually while claiming to show the same numbers. --}}
<style>
  /* Numbers in columns only read as columns if the digits line up. */
  .daytable { width:100%; border-collapse:collapse; font-size:0.86rem; }
  .daytable th, .daytable td { padding:9px 10px; border-bottom:1px solid #eff3f9; }
  .daytable thead th {
    font-size:0.68rem; text-transform:uppercase; letter-spacing:0.07em;
    color:#8aa4b8; font-weight:700; text-align:right; vertical-align:bottom;
    border-bottom:1px solid #dde8f2; white-space:nowrap;
  }
  .daytable thead th.day, .daytable thead th.text { text-align:left; }
  .daytable td { text-align:right; font-variant-numeric:tabular-nums; color:#123c55; }
  .daytable td.day, .daytable td.text { text-align:left; }
  .daytable td.day { white-space:nowrap; font-weight:600; }
  .daytable td.day .dow { display:block; font-weight:400; font-size:0.72rem; color:#8aa4b8; }
  .daytable tbody tr:hover { background:#f7fafd; }
  .daytable tfoot td { font-weight:700; border-top:2px solid #dde8f2; border-bottom:none; }
  .daytable tfoot td.day { color:#5f7f9a; font-weight:600; }

  /* A count is a link. The underline only appears on hover so the table still
     reads as a table rather than a page of blue text. */
  .daytable td a {
    color:inherit; text-decoration:none; border-bottom:1px solid transparent;
  }
  .daytable td a:hover { color:#1c5a7f; border-bottom-color:#9fc4dc; }
  .daytable td.day a { color:#1f5679; }

  /* The published column is the answer; everything else is how it got there. */
  .daytable .lead { background:#f4f9fd; font-weight:700; }
  .daytable thead th.lead { background:#f4f9fd; color:#1c5a7f; }

  /* A group boundary, so "where the rest went" reads apart from "where the
     published ones are". */
  .daytable .sep { border-left:1px solid #e2edf6; }
  .daytable .zero { color:#c2d0dc; }

  /* Held and No pin are remainders, not buckets. When they are not zero they
     should be the thing the eye lands on. */
  .daytable td.held.nonzero, .daytable td.held a { color:#a8501e; font-weight:700; }

  .daytable td.money { white-space:nowrap; }
  .daytable td.money .calls {
    display:block; font-size:0.7rem; color:#a3b6c6; font-weight:400;
  }

  /* Thirteen columns do not fit a laptop. The table scrolls inside its card
     rather than the page scrolling sideways. */
  .table-scroll { overflow-x:auto; }

  .legend { font-size:0.8rem; color:#5f7f9a; line-height:1.65; margin:14px 0 0; }
  .legend b { color:#123c55; }

  /* ── the day drill-down ─────────────────────────────────────────────── */
  .crumb { font-size:0.84rem; color:#5f7f9a; margin:0 0 6px; }
  .crumb a { color:#1f5679; }

  .storytitle { display:block; color:#123c55; text-decoration:none; font-weight:600; }
  .storytitle:hover { color:#1c5a7f; text-decoration:underline; }
  .storymeta { font-size:0.74rem; color:#8aa4b8; }

  .tag {
    display:inline-block; padding:2px 8px; border-radius:20px; font-size:0.7rem;
    background:#f2f7fb; border:1px solid #e2edf6; color:#5f7f9a; white-space:nowrap;
  }
  .tag.pin  { background:#eef7f0; border-color:#cfe6d5; color:#3d7350; }
  .tag.none { background:#fdf2ec; border-color:#f0d8c8; color:#a8501e; }

  /* A story served at three places lists three places. Run together on one
     line they read as a single long address. */
  .pinlist { display: block; margin-top: 3px; }
  .pinrow {
    display: block; font-size: 0.74rem; color: #8aa4b8; line-height: 1.55;
    text-decoration: none;
  }
  .pinrow:hover { color: #1c5a7f; text-decoration: underline; }
  .pinrow:hover .pingo { opacity: 1; }
  /* Present but quiet until the row is hovered - thirty of these down a page
     would otherwise read as decoration. */
  .pingo { opacity: 0.35; font-size: 0.7rem; }

  .bucketbar { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 16px; }
  .bucketbar a {
    padding:6px 12px; border-radius:20px; font-size:0.8rem; text-decoration:none;
    background:#fff; border:1px solid #e2edf6; color:#1f5679; white-space:nowrap;
  }
  .bucketbar a.on { background:#1c5a7f; border-color:#1c5a7f; color:#fff; font-weight:600; }
  .bucketbar a .n { color:#8aa4b8; font-variant-numeric:tabular-nums; }
  .bucketbar a.on .n { color:#cfe2ef; }
</style>
<style>
  /* The fetch alarm. Loud enough to notice, quiet enough to live above a table
     that is read every day. */
  .fetchalarm { margin:10px 0 16px; padding:12px 14px; border:1px solid #e0a3a3;
    border-left:4px solid #c0392b; background:#fdf3f2; border-radius:6px; font-size:13px; }
  .fetchalarm table { width:100%; margin:8px 0 4px; border-collapse:collapse; }
  .fetchalarm th { text-align:left; font-size:11px; text-transform:uppercase;
    letter-spacing:.04em; color:#7c5a5a; padding:4px 8px 4px 0; }
  .fetchalarm td { padding:3px 8px 3px 0; border-top:1px solid #f0dcda; vertical-align:top; }
  .fetchalarm code { background:#fff; padding:1px 5px; border-radius:3px; border:1px solid #e6d2d0; }
  .fetchalarm .mini { margin:6px 0 0; color:#6b5252; }
</style>
