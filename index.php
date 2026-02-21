<?php
$downloadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'downloads';
$downloads = [];

if (is_dir($downloadRoot)) {
    foreach (scandir($downloadRoot) as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $downloadRoot . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) continue;
        $downloads[] = [
            'name' => $file,
            'size' => filesize($path) ?: 0,
            'mtime' => filemtime($path) ?: 0,
            'url' => 'downloads/' . rawurlencode($file),
        ];
    }
}

usort($downloads, function($a, $b) {
    return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
});

function h($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function fmt_size($bytes) {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $fmt = $i === 0 ? '%d %s' : '%.1f %s';
    return sprintf($fmt, $bytes, $units[$i]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Wi-Fi Video Transfer (Resumable)</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 640px; margin: 0 auto; padding: 1rem; background: #f7f7f7; }
  h1 { font-size: 1.4rem; text-align: center; margin: .5rem 0 1rem; }
  .card { background:#fff; padding:1rem; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.08); }
  input[type="file"]{ width:100%; margin:.75rem 0 1rem; }
  button { padding:.7rem 1rem; border:0; border-radius:8px; background:#4a90e2; color:#fff; font-size:1rem; }
  button[disabled]{ opacity:.6; }
  .row { display:flex; gap:.5rem; flex-wrap:wrap; }
  .bar { height:16px; background:#e9e9e9; border-radius:10px; overflow:hidden; margin:.75rem 0 .25rem; }
  .fill { height:100%; width:0%; background:linear-gradient(90deg,#4a90e2,#357ab8); transition:width .1s linear; }
  .muted { color:#666; font-size:.92rem; }
  #status { margin-top:.75rem; background:#fff; padding:.8rem; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.05); word-break:break-word; }
  .grid { display:grid; grid-template-columns: 1fr 1fr; gap:.25rem .75rem; font-size:.92rem; }
  .downloads-card { margin-top:1rem; }
  .downloads-card h2 { margin:0 0 .25rem; font-size:1.05rem; }
  .download-list { list-style:none; padding:0; margin:.5rem 0 0; display:flex; flex-direction:column; gap:.5rem; }
  .download-item { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.7rem .8rem; border:1px solid #e1e5ec; border-radius:10px; background:linear-gradient(135deg,#f8fbff,#f2f4f8); }
  .download-info a { color:#1f4b99; font-weight:600; text-decoration:none; }
  .download-info a:hover { text-decoration:underline; }
  .download-meta { font-size:.88rem; color:#555; }
  .pill { padding:.4rem .85rem; border-radius:999px; background:#1f7aec; color:#fff; font-weight:600; text-decoration:none; font-size:.92rem; white-space:nowrap; }
  .pill:hover { background:#1863c0; }
  @media (max-width:480px){ button{ flex:1; } .row{ flex-direction:column; } .grid{ grid-template-columns:1fr 1fr; } }
</style>
</head>
<body>
  <h1>Wi-Fi Video Transfer v4</h1>
  <div class="card">
    <input id="file" type="file" accept="video/*" />
    <div class="row">
      <button id="startBtn">Upload / Resume</button>
      <button id="cancelBtn" disabled>Cancel</button>
    </div>

    <!-- TOTAL progress -->
    <div class="bar"><div id="fill" class="fill"></div></div>
    <div class="muted">
      <span id="pct">0%</span> • <span id="speed">0 MB/s</span> • <span id="eta">ETA —</span>
    </div>

    <!-- CHUNK progress -->
    <div class="bar" style="margin-top:.75rem;"><div id="chunkFill" class="fill" style="width:0%"></div></div>
    <div class="muted">
      Chunk <span id="chunkIndex">0</span>/<span id="chunkTotal">0</span> •
      <span id="chunkPct">0%</span>
    </div>

    <div class="grid" style="margin-top:.75rem">
      <div class="muted">File:</div><div id="fName" class="muted">—</div>
      <div class="muted">Size:</div><div id="fSize" class="muted">—</div>
      <div class="muted">Uploaded:</div><div id="fUploaded" class="muted">—</div>
      <div class="muted">Remaining:</div><div id="fRemaining" class="muted">—</div>
    </div>
  </div>

  <div id="status"></div>

  <div class="card downloads-card">
    <h2>Downloads</h2>
    <div class="muted">Files placed into the <code>downloads</code> folder are listed here.</div>
    <?php if (empty($downloads)): ?>
      <p class="muted" style="margin:.75rem 0 0;">No files available yet.</p>
    <?php else: ?>
      <ul class="download-list">
        <?php foreach ($downloads as $file): ?>
          <li class="download-item">
            <div class="download-info">
              <a href="<?= h($file['url']) ?>" download><?= h($file['name']) ?></a>
              <div class="download-meta"><?= fmt_size($file['size']) ?> • <?= date('Y-m-d H:i', $file['mtime']) ?></div>
            </div>
            <a class="pill" href="<?= h($file['url']) ?>" download>Download</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

<script>
(() => {
  const CHUNK_SIZE = 10 * 1024 * 1024; // 10 MB

  const fileInput = document.getElementById('file');
  const startBtn = document.getElementById('startBtn');
  const cancelBtn = document.getElementById('cancelBtn');
  const fill = document.getElementById('fill');
  const pct = document.getElementById('pct');
  const speedEl = document.getElementById('speed');
  const etaEl = document.getElementById('eta');
  const chunkFill = document.getElementById('chunkFill');
  const chunkPct = document.getElementById('chunkPct');
  const chunkIndexEl = document.getElementById('chunkIndex');
  const chunkTotalEl = document.getElementById('chunkTotal');
  const status = document.getElementById('status');

  const fName = document.getElementById('fName');
  const fSize = document.getElementById('fSize');
  const fUploaded = document.getElementById('fUploaded');
  const fRemaining = document.getElementById('fRemaining');

  let abort = false;

  const fmtBytes = (b) => {
    const units = ['B','KB','MB','GB','TB'];
    let i = 0, n = b;
    while (n >= 1024 && i < units.length-1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
  };
  const fmtRate = (bps)=> (bps/1048576).toFixed(2) + ' MB/s';
  const fmtETA = (rem, bps)=>{
    if (!bps || !isFinite(bps) || bps<=0) return 'ETA —';
    const s = Math.max(0, Math.round(rem / bps));
    const m = Math.floor(s/60), sec = s%60;
    return `ETA ${m}m ${sec}s`;
  };

  // Deterministic fileId so we can resume after errors/reloads
  function stableId(file){
    return `${file.name}.${file.size}.${file.lastModified}`;
  }

  async function probeServer(fileId){
    const res = await fetch(`upload_chunk.php?fileId=${encodeURIComponent(fileId)}`, { cache: 'no-store' });
    if (!res.ok) throw new Error('Probe failed');
    const j = await res.json();
    if (!j.ok) throw new Error(j.error || 'Probe error');
    return new Set(j.have || []);
  }

  function sendChunk(fd, onProgress, retries = 3){
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', 'upload_chunk.php');
      xhr.upload.onprogress = (ev) => { if (ev.lengthComputable) onProgress(ev.loaded, ev.total); };
      xhr.onload = () => {
        if (xhr.status === 200) resolve(xhr.responseText);
        else if (retries > 0) {
          setTimeout(() => sendChunk(fd, onProgress, retries - 1).then(resolve, reject), 1000 * (4 - retries));
        } else reject(new Error(`HTTP ${xhr.status}`));
      };
      xhr.onerror = () => {
        if (retries > 0) {
          setTimeout(() => sendChunk(fd, onProgress, retries - 1).then(resolve, reject), 1000 * (4 - retries));
        } else reject(new Error('network error'));
      };
      xhr.send(fd);
    });
  }

  startBtn.addEventListener('click', async () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) { status.textContent = 'Please choose a file.'; return; }

    // UI init
    fName.textContent = file.name;
    fSize.textContent = fmtBytes(file.size);
    fUploaded.textContent = '0 B';
    fRemaining.textContent = fmtBytes(file.size);

    abort = false;
    startBtn.disabled = true;
    cancelBtn.disabled = false;
    status.textContent = '';
    fill.style.width = '0%'; pct.textContent = '0%';
    chunkFill.style.width = '0%'; chunkPct.textContent = '0%';

    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
    chunkTotalEl.textContent = String(totalChunks);

    // Deterministic ID + persist in localStorage to survive reloads
    const fileId = stableId(file);
    localStorage.setItem('lastUploadId', fileId);

    // Ask server which chunks we already have
    let have = new Set();
    try { have = await probeServer(fileId); } catch (e) { /* ignore on first run */ }

    let uploadedBytes = 0;
    have.forEach(idx => {
      uploadedBytes += Math.min(CHUNK_SIZE, file.size - idx*CHUNK_SIZE);
    });

    let lastUpdateTime = performance.now();
    let lastUploaded = uploadedBytes;

    // Update total UI after resume probe
    const percentResume = Math.round((uploadedBytes / file.size) * 100);
    fill.style.width = percentResume + '%'; pct.textContent = percentResume + '%';
    fUploaded.textContent = fmtBytes(uploadedBytes);
    fRemaining.textContent = fmtBytes(Math.max(0, file.size - uploadedBytes));

    for (let idx = 0; idx < totalChunks; idx++) {
      if (abort) { status.textContent = 'Upload canceled.'; break; }
      chunkIndexEl.textContent = String(idx + 1);

      const chunkStart = idx * CHUNK_SIZE;
      const chunkEnd = Math.min(chunkStart + CHUNK_SIZE, file.size);
      const thisChunkBytes = chunkEnd - chunkStart;

      if (have.has(idx)) {
        // Update "current chunk" UI as instantly complete
        chunkFill.style.width = '100%'; chunkPct.textContent = '100%';
        continue; // already counted in uploadedBytes above
      }

      let chunkLoaded = 0;
      const fd = new FormData();
      fd.append('fileId', fileId);
      fd.append('fileName', file.name); // used in server part filenames: "<name>.part{idx}"
      fd.append('totalChunks', String(totalChunks));
      fd.append('chunkIndex', String(idx));
      fd.append('chunk', file.slice(chunkStart, chunkEnd), `${file.name}.part${idx}`);

      try {
        await sendChunk(fd, (loaded, total) => {
          // Per-chunk progress bar
          chunkLoaded = loaded;
          const chunkPercent = Math.round((loaded / total) * 100);
          chunkFill.style.width = chunkPercent + '%';
          chunkPct.textContent = chunkPercent + '%';

          // Total progress estimation (uploadedBytes + current chunkLoaded)
          const now = performance.now();
          if (now - lastUpdateTime > 250) {
            const totalSoFar = uploadedBytes + chunkLoaded;
            const bps = (totalSoFar - lastUploaded) / ((now - lastUpdateTime)/1000);
            speedEl.textContent = fmtRate(bps);
            etaEl.textContent = fmtETA(file.size - totalSoFar, bps);
            lastUpdateTime = now; lastUploaded = totalSoFar;

            const percent = Math.round((totalSoFar / file.size) * 100);
            fill.style.width = percent + '%'; pct.textContent = percent + '%';
            fUploaded.textContent = fmtBytes(totalSoFar);
            fRemaining.textContent = fmtBytes(Math.max(0, file.size - totalSoFar));
          }
        });
      } catch (e) {
        startBtn.disabled = false;
        cancelBtn.disabled = true;
        status.textContent = '❌ ' + e.message + ' — you can tap “Upload / Resume” to continue later.';
        return;
      }

      // After chunk successfully uploaded, advance totals
      uploadedBytes += thisChunkBytes;
      have.add(idx);
      chunkFill.style.width = '100%'; chunkPct.textContent = '100%';

      const percent = Math.round((uploadedBytes / file.size) * 100);
      fill.style.width = percent + '%'; pct.textContent = percent + '%';
      fUploaded.textContent = fmtBytes(uploadedBytes);
      fRemaining.textContent = fmtBytes(Math.max(0, file.size - uploadedBytes));
    }

    if (!abort) {
      try {
        const res = await fetch(`upload_chunk.php?finalize=1&fileId=${encodeURIComponent(fileId)}&fileName=${encodeURIComponent(file.name)}&totalChunks=${totalChunks}`);
        const j = await res.json();
        if (j.ok) {
          status.innerHTML = `✅ Upload complete.<br>Saved as: <code>${j.name}</code><br><a href="${j.url}" target="_blank">Open file</a>`;
          localStorage.removeItem('lastUploadId');
        } else {
          status.textContent = '❌ ' + (j.error || 'Finalize failed.');
        }
      } catch {
        status.textContent = '❌ Finalize request failed — try again to resume.';
      }
    }

    startBtn.disabled = false;
    cancelBtn.disabled = true;
  });

  cancelBtn.addEventListener('click', () => { abort = true; });

  // If you want auto-resume on page load for the last file attempt, you could:
  // const last = localStorage.getItem('lastUploadId'); (we’d also need to restore a file ref; omitted)
})();
</script>
</body>
</html>
