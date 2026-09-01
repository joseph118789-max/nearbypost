<style>
  .brainnav { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 20px; }
  .brainnav a {
    padding:7px 13px; border-radius:20px; font-size:0.84rem; text-decoration:none;
    background:#fff; border:1px solid #e2edf6; color:#1f5679;
  }
  .brainnav a.on { background:#1c5a7f; border-color:#1c5a7f; color:#fff; font-weight:600; }
  .brainnav a:hover { border-color:#9fc4dc; }
  /* A quiet label rather than a divider: ten pills in a row is a wall, and the
     grouping is the argument this section makes. */
  .brainnav .navgroup {
    font-size:0.66rem; text-transform:uppercase; letter-spacing:0.09em; color:#9db4c5;
    align-self:center; padding-left:10px; margin-left:2px; border-left:1px solid #e2edf6;
  }
  @media (max-width:700px) {
    .brainnav .navgroup { flex-basis:100%; border-left:0; padding-left:0; margin:6px 0 0; }
  }

  .card { background:#fff; border:1px solid #e2edf6; border-radius:18px; padding:18px 20px; margin-bottom:14px; }

  /* The pipeline, said once at the top. The rest of the page only makes sense
     against it. */
  .pipeline { display:flex; align-items:stretch; gap:10px; flex-wrap:wrap; margin:0 0 26px; }
  .pipeline .stage {
    flex:1 1 210px; background:#fff; border:1px solid #e2edf6; border-radius:14px;
    padding:12px 14px; display:flex; flex-direction:column; gap:2px;
  }
  .pipeline .stage .num {
    display:inline-flex; align-items:center; justify-content:center;
    width:20px; height:20px; border-radius:50%; background:#1c5a7f; color:#fff;
    font-size:0.7rem; font-weight:700; margin-bottom:4px;
  }
  .pipeline .stage strong { color:#123c55; font-size:0.95rem; }
  .pipeline .stage .what { font-size:0.8rem; color:#6b8ba3; line-height:1.45; }
  .pipeline .arrow { align-self:center; color:#9db4c5; font-size:1.2rem; }
  @media (max-width:700px) { .pipeline .arrow { display:none; } }

  .stagehead {
    display:flex; align-items:center; gap:9px;
    font-size:1.15rem; color:#123c55; margin:30px 0 4px;
  }
  .stagehead .num {
    display:inline-flex; align-items:center; justify-content:center;
    width:24px; height:24px; border-radius:50%; background:#1c5a7f; color:#fff;
    font-size:0.75rem; font-weight:700;
  }
  .stagelede { font-size:0.87rem; color:#5f7f9a; line-height:1.6; max-width:60rem; margin-bottom:14px; }
  .card h2 { font-size:1.02rem; color:#123c55; margin-bottom:6px; }
  .card .sub { font-size:0.83rem; color:#6b8ba3; margin-bottom:12px; }

  .grid2 { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; }

  .stat { display:flex; align-items:baseline; gap:8px; }
  .stat .n { font-size:1.7rem; font-weight:700; color:#123c55; font-variant-numeric:tabular-nums; }
  .stat .of { font-size:0.82rem; color:#7f9bb0; }

  .barline { display:flex; height:24px; border-radius:5px; overflow:hidden; border:1px solid #e2edf6; margin:10px 0 12px; }
  .barline span { display:block; height:100%; }
  .o-code{background:#b04a26}.o-playbook{background:#1c5a7f}.o-rules{background:#3d84ad}
  .o-cases{background:#6fb0d2}.o-briefing{background:#a7cfe4}.o-taxonomy{background:#d5e5ef}

  .legend { display:grid; gap:3px; font-size:0.84rem; }
  .legend div { display:grid; grid-template-columns:12px 1fr auto auto; gap:10px; align-items:center;
                padding:5px 0; border-bottom:1px solid #f0f5f9; }
  .legend div:last-child { border-bottom:0; }
  .legend i { width:10px; height:10px; border-radius:2px; display:block; }
  .legend .n { font-variant-numeric:tabular-nums; color:#6b8ba3; font-size:0.8rem; }
  .legend .p { font-variant-numeric:tabular-nums; font-weight:600; min-width:3rem; text-align:right; }

  .part { border:1px solid #e2edf6; border-radius:12px; margin-bottom:10px; overflow:hidden; }
  .part > summary {
    cursor:pointer; padding:11px 14px; background:#f8fafc; display:flex;
    justify-content:space-between; gap:12px; align-items:center; font-size:0.9rem;
  }
  .part > summary strong { color:#123c55; }
  .part pre {
    margin:0; padding:14px; background:#fff; font-size:0.78rem; line-height:1.6;
    white-space:pre-wrap; word-break:break-word; color:#2c3f4c; border-top:1px solid #eef4f8;
  }
  .tag { font-size:0.7rem; padding:2px 8px; border-radius:20px; white-space:nowrap; }
  .tag.editable { background:#e6f2e6; color:#276b3a; }
  .tag.locked { background:#fbeee7; color:#a1481f; }
  .tag.derived { background:#eef4f8; color:#4a6b80; }

  .scoregrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; }
  .score { background:#f8fafc; border:1px solid #e2edf6; border-radius:12px; padding:12px 14px; }
  .score .lbl { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em; color:#7f9bb0; }
  .score .v { font-size:1.5rem; font-weight:700; color:#123c55; font-variant-numeric:tabular-nums; }
  .score .v.bad { color:#b04a26; }

  table.tidy { width:100%; border-collapse:collapse; font-size:0.85rem; }
  table.tidy th { text-align:left; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.06em;
                  color:#7f9bb0; padding:6px 8px 6px 0; border-bottom:1px solid #e2edf6; }
  table.tidy td { padding:8px 8px 8px 0; border-bottom:1px solid #f0f5f9; vertical-align:top; }
  table.tidy tr:last-child td { border-bottom:0; }

  .mini { font-size:0.78rem; color:#7f9bb0; }
  .pill { font-size:0.72rem; padding:2px 8px; border-radius:20px; background:#eef4f8; color:#4a6b80; white-space:nowrap; }
  .pill.nowhere { background:#fbeee7; color:#a1481f; }

  .btn-sm { font-size:0.78rem; padding:5px 11px; border-radius:8px; border:1px solid #cfe0ec;
            background:#fff; color:#1f5679; cursor:pointer; text-decoration:none; display:inline-block; }
  .btn-sm.go { background:#1c5a7f; border-color:#1c5a7f; color:#fff; }
  .btn-sm.warn { border-color:#e6c4b4; color:#a1481f; }

  /* The bulk bar states what will happen and to how many, so a batch action is
     never taken blind. */
  .bulkbar {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    padding:10px 12px; background:#f8fafc; border:1px solid #e2edf6;
    border-radius:12px; margin-bottom:12px;
  }
  .bulkbar .count { font-size:0.84rem; color:#4a6b80; margin-right:auto; }
  .bulkbar .count strong { color:#123c55; }
  .tickcol { width:34px; }
  .tickcol input { width:16px; height:16px; cursor:pointer; }

  .storylink { color:#123c55; text-decoration:none; font-weight:500; }
  .storylink:hover { color:#1c5a7f; text-decoration:underline; }
  /* The text the model was given, quoted rather than paraphrased. */
  .readtext {
    margin:6px 0 0; padding:8px 10px; background:#f8fafc; border-left:2px solid #cfe0ec;
    border-radius:0 8px 8px 0; font-size:0.78rem; line-height:1.55; color:#4a6b80;
  }
  .readtext.none { border-left-color:#e6c4b4; color:#a1481f; }

  .fixform { display:none; margin-top:8px; padding:10px; background:#f8fafc; border-radius:10px; }
  .fixform.open { display:block; }
  .fixform select, .fixform input { font-size:0.8rem; padding:5px 8px; border:1px solid #cfe0ec; border-radius:7px; }
  .fixform input { width:100%; margin-top:6px; }
</style>
