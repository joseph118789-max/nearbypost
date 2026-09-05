<style>
  body { background: #eef2f5; font-family: Inter, system-ui, sans-serif; color: #0a2a3b; }
  .srcpage { max-width: 1400px; margin: 0 auto; padding: 20px 16px 60px; }
  .srcpage h1 { font-size: 1.5rem; font-weight: 700; color: #1c5a7f; margin-bottom: 4px; }
  .srcpage h2 { font-size: 1.1rem; color: #1c5a7f; margin: 28px 0 10px; }
  .lede { color: #5f7f9a; font-size: 0.9rem; line-height: 1.6; margin-bottom: 18px; }
  .back { display: inline-block; margin-bottom: 12px; font-size: 0.82rem; font-weight: 600;
          color: #5f7f9a; text-decoration: none; }
  .back:hover { color: #1c5a7f; }
  .flash { background: #e0f5e9; color: #1f7840; padding: 12px 18px; border-radius: 14px;
           margin-bottom: 18px; font-size: 0.9rem; }
  .warn { background: #fff3f0; color: #bc4e2c; padding: 12px 18px; border-radius: 14px;
          margin-bottom: 18px; font-size: 0.9rem; }

  .srctable { width: 100%; border-collapse: collapse; background: #fff; border-radius: 18px;
              overflow: hidden; border: 1px solid #e2edf6; font-size: 0.85rem; }
  .srctable th { text-align: left; padding: 12px 14px; background: #f8fafc; color: #5f7f9a;
                 font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
  .srctable td { padding: 14px; border-top: 1px solid #eff3f9; vertical-align: top; }
  .srctable tr.off { opacity: 0.55; }
  .srctable td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .srcname { font-weight: 700; color: #1c5a7f; text-decoration: none; font-size: 0.95rem; }
  .srcname:hover { text-decoration: underline; }
  .srcurl { font-size: 0.74rem; color: #8aa4b8; word-break: break-all; margin-top: 3px; }
  .srcurl.link { color: #1f5679; text-decoration: none; }
  .srcurl.link:hover { text-decoration: underline; }
  .dim { color: #8aa4b8; }
  .tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
  .badge { background: #e9f0f6; padding: 3px 10px; border-radius: 30px; font-size: 0.68rem;
           font-weight: 600; color: #1f5679; white-space: nowrap; }
  .badge-ok { background: #e0f5e9; color: #1f7840; }
  .badge-warn { background: #fef3c7; color: #78350f; }
  .badge-bad, .badge-off { background: #fff3f0; color: #bc4e2c; }
  .dimbadge { opacity: 0.6; }

  .srccard { background: #fff; border: 1px solid #e2edf6; border-radius: 20px; padding: 20px;
             margin-bottom: 14px; }
  .srccard-head { display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap;
                  margin-bottom: 10px; }
  .srccard h3 { font-size: 1.02rem; color: #1c5a7f; }
  .srcmeta { font-size: 0.76rem; color: #8aa4b8; margin-bottom: 14px; line-height: 1.5; }
  .srcmeta code { background: #f1f5f9; padding: 1px 6px; border-radius: 6px; font-size: 0.9em; }
  .lbl { display: block; font-size: 0.8rem; font-weight: 700; color: #34505f; margin-top: 12px; }
  .hint { font-size: 0.74rem; color: #8aa4b8; margin: 3px 0 6px; line-height: 1.5; }
  .ta { width: 100%; border: 1px solid #d4e2ef; border-radius: 14px; padding: 10px 14px;
        font-size: 0.84rem; font-family: inherit; line-height: 1.6; resize: vertical; }
  .srcrow { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-end; margin-top: 16px; }
  .srcrow > div { flex: 1 1 200px; }
  .inp { width: 100%; border: 1px solid #d4e2ef; border-radius: 40px; padding: 9px 16px;
         font-size: 0.84rem; font-family: inherit; background: #fff; }
  .onoff { display: flex; align-items: center; gap: 14px; flex: 0 0 auto; }
  .chk { display: flex; align-items: center; gap: 7px; font-size: 0.84rem; color: #34505f; }
  .btn { border: none; font-weight: 600; padding: 9px 22px; border-radius: 40px; cursor: pointer;
         background: #f0f4f9; color: #1f5679; font-size: 0.84rem; font-family: inherit; }
  .btn-primary { background: #1c5a7f; color: #fff; }

  .reason { border:1px solid #d4e2ef;border-radius:40px;padding:9px 16px;font-size:0.84rem;
            font-family:inherit; }
</style>
