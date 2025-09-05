<?php
// create.php — Single-file UI + API
// - Fixed local base C:\edms (browser permission once)
// - Parent-folder migration (ZIP+upload)
// - Server storage browser + preview (confined to /public_html/storage)
// - Durable logs stored in /public_html/storage/_migrations.json

/* -------------------------
   Helpers (server-side)
------------------------- */

function storage_root(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage';
}

function public_href_for(string $absPath): string {
    // Build a web path relative to document root (so links are clickable).
    // Example: /storage/COM-2025-0001/A4/scan.pdf
    $abs = str_replace('\\', '/', $absPath);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    if (strpos($abs, $docRoot) === 0) {
        return substr($abs, strlen($docRoot)); // already starts with /
    }
    // Fallback: relative to this script
    $base = rtrim(str_replace('\\', '/', __DIR__), '/');
    if (strpos($abs, $base) === 0) {
        return substr($abs, strlen($base)); // starts without /; add it
    }
    return '/storage'; // safe fallback
}

function safe_join_under_storage(string $sub): string {
    $root = storage_root();
    $path = $root . DIRECTORY_SEPARATOR . $sub;
    $realRoot = realpath($root);
    $realPath = realpath($path) ?: $path; // allow non-existing (for mkdir/list)
    // Normalize separators
    $realRootNorm = rtrim(str_replace('\\','/',$realRoot), '/');
    $realPathNorm = rtrim(str_replace('\\','/',$realPath), '/');
    if (strpos($realPathNorm, $realRootNorm) !== 0) {
        // Prevent traversal outside storage
        return $realRoot;
    }
    return $realPath;
}

function list_dir_for_api(string $dir): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $items = scandir($dir);
    if (!$items) return $out;
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        $isDir = is_dir($full);
        $item = [
            'name' => $name,
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? null : filesize($full),
            'mtime'=> @filemtime($full) ?: null,
            'href' => $isDir ? null : public_href_for($full),
        ];
        $out[] = $item;
    }
    // sort: directories first, then files, both alpha
    usort($out, function($a,$b){
        if ($a['type'] === $b['type']) return strcasecmp($a['name'],$b['name']);
        return $a['type']==='dir' ? -1 : 1;
    });
    return $out;
}

function logs_path(): string {
    return storage_root() . DIRECTORY_SEPARATOR . '_migrations.json';
}

function read_logs(): array {
    $p = logs_path();
    if (!is_file($p)) return [];
    $raw = file_get_contents($p);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function append_log(array $entry): void {
    $logs = read_logs();
    array_unshift($logs, $entry);
    @file_put_contents(logs_path(), json_encode($logs, JSON_PRETTY_PRINT));
}

/* -------------------------
   API endpoints
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'];

    // Ensure storage root exists
    $root = storage_root();
    if (!is_dir($root)) { @mkdir($root, 0775, true); }

    if ($api === 'list') {
        // Lists files/folders under /storage (confined)
        $sub = isset($_GET['path']) ? trim($_GET['path'], "/\\") : '';
        // disallow traversal tokens
        if (strpos($sub, '..') !== false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad path']); exit; }
        $dir = safe_join_under_storage($sub);
        $items = list_dir_for_api($dir);

        // Build breadcrumb
        $parts = $sub === '' ? [] : explode('/', str_replace('\\','/',$sub));
        $crumbs = [];
        $acc = '';
        foreach ($parts as $i=>$p) {
            $acc .= ($i===0 ? '' : '/') . $p;
            $crumbs[] = ['name'=>$p, 'path'=>$acc];
        }

        echo json_encode([
            'ok'=>true,
            'root'=> public_href_for($root),
            'sub'=> $sub,
            'crumbs'=>$crumbs,
            'items'=>$items
        ]);
        exit;
    }

    if ($api === 'logs') {
        echo json_encode(['ok'=>true, 'logs'=> read_logs()]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Unknown API']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api'])) {
    header('Content-Type: application/json');

    try {
        if (empty($_POST['folderName']) || !isset($_FILES['zip'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing folderName or zip']);
            exit;
        }
        $folderName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $_POST['folderName']); // sanitize
        $upload = $_FILES['zip'];
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload failed (php code '.$upload['error'].')']);
            exit;
        }

        // Make sure /storage exists
        $root = storage_root();
        if (!is_dir($root)) {
            if (!mkdir($root, 0775, true)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Could not create storage root']);
                exit;
            }
        }

        // Final path /storage/[FolderName]
        $target = $root . DIRECTORY_SEPARATOR . $folderName;
        if (is_dir($target)) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'Folder already exists on server']);
            exit;
        }
        if (!mkdir($target, 0775, true)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not create target folder']);
            exit;
        }

        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'PHP ZipArchive not installed. Enable the zip extension.']);
            exit;
        }

        $tmpZip = $upload['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid ZIP']);
            exit;
        }

        // Safety: disallow traversal or absolute paths (PHP 7 compatible)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, '..') !== false || preg_match('#^[/\\\\]#', $name)) {
                $zip->close();
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unsafe path in ZIP']);
                exit;
            }
        }

        if (!$zip->extractTo($target)) {
            $zip->close();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to extract ZIP']);
            exit;
        }
        $zip->close();

        // Persist log inside /storage/_migrations.json
        $serverPathWeb = public_href_for($target); // e.g. /storage/COM-2025-0001
        $log = [
            'when' => date('Y-m-d H:i:s'),
            'folder' => $folderName,
            'serverPath' => $serverPathWeb,
            'items' => null
        ];
        append_log($log);

        echo json_encode([
            'ok' => true,
            'message' => 'Migrated successfully',
            'serverPath' => $serverPathWeb
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/* -------------------------
   Page (GET)
------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>EDMS — Local Scan → Parent Folder Migration</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
  <style>
    body { background:#ffffff; color:#0f172a; }
    .text-muted-foreground { color:#64748b; }
    .bg-card { background:#ffffff; }
    .bg-muted { background:#f1f5f9; }
    .border { border-color:#e2e8f0; }
    .progress-bar { transition: width .4s ease; }
    .kbd{font-family:ui-monospace,Menlo,Consolas,monospace;border:1px solid #e2e8f0;background:#f8fafc;padding:.05rem .35rem;border-radius:.25rem}
    code { background:#f8fafc; padding:0 .25rem; border:1px solid #e2e8f0; border-radius:.25rem }
    .row:hover { background:#f8fafc; }
    .pill{font-size:11px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:.15rem .5rem}
    .pill-ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
    .pill-warn{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
    .btn { @apply border bg-white hover:bg-muted py-2 px-3 rounded-md text-sm; }
    
  </style>
</head>
<body class="min-h-screen">
  <div class="container mx-auto py-6 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-3xl font-bold">Blind Scanning Workflow</h1>
        <p class="text-muted-foreground">
          Local base is <code>C:\edms</code>. Create locally → Scan manually → pick a
          <b>parent folder</b> (e.g., <code>COM-2025-0001</code>) to migrate. Migration includes the parent and all subfolders/files.
        </p>
      </div>
      <div class="text-sm text-slate-700">
        Server destination: <span class="font-mono">/public_html/storage/[ParentFolder]</span>
      </div>
    </div>

    <div class="border rounded-lg p-6 bg-white">
      <div class="flex items-start gap-3">
        <i class="fa-solid fa-circle-info text-blue-600 mt-0.5"></i>
        <div>
          <h4 class="font-medium text-blue-800">Notes</h4>
          <ul class="mt-2 text-sm text-slate-700 list-disc pl-5 space-y-1">
            <li>Use Chrome/Edge over HTTPS or <code>http://localhost</code> to grant access to <code>C:\edms</code>.</li>
            <li>First time, click <b>Initialize C:\edms</b> and choose <b>C:\</b> (or existing <b>edms</b>) once.</li>
            <li>This page does <b>not</b> upload individual scans; you scan into local folders yourself.</li>
            <li><b>Migrate</b> zips the selected parent folder and uploads it here; the server refuses if it already exists.</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- LEFT: Quick create -->
      <div class="lg:col-span-1">
        <div class="bg-card rounded-lg border">
          <div class="p-6">
            <h3 class="text-xl font-bold flex items-center gap-2"><i class="fa-regular fa-file-lines"></i>Quick Create (File No)</h3>
            <p class="text-muted-foreground">Always creates <code>[FileNo]\A4</code> and <code>A3</code> inside <code>C:\edms</code>.</p>
          </div>
          <div class="p-6 pt-0 space-y-4">
            <div class="relative">
              <i class="fa-solid fa-search absolute left-2.5 top-[10px] text-muted-foreground"></i>
              <input id="search-input" type="text" placeholder="Search file numbers..."
                     class="w-full pl-8 bg-white border rounded-md py-2 px-3"/>
            </div>
            <div id="file-list" class="space-y-2 max-h-96 overflow-y-auto"></div>

            <div class="flex flex-col gap-2">
              <button id="init-edms-btn" class="w-full border bg-white hover:bg-muted py-2 px-4 rounded-md flex items-center justify-center">
                <i class="fa-solid fa-plug mr-2"></i>Initialize C:\edms (grant access)
              </button>
              <button id="create-local-btn" class="w-full bg-blue-600 hover:bg-blue-500 text-white py-2 px-4 rounded-md flex items-center justify-center disabled:opacity-50" disabled>
                <i class="fa-solid fa-folder-plus mr-2"></i>Create in C:\edms
              </button>
            </div>

            <div id="selected-file-info" class="p-3 bg-blue-50 border border-blue-200 rounded-lg hidden">
              <h4 class="font-medium text-sm text-blue-800">Selected File</h4>
              <p id="selected-file-no" class="text-sm font-mono"></p>
              <p id="selected-file-name" class="text-xs text-blue-700"></p>
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT: Explorer -->
      <div class="lg:col-span-2">
        <div class="bg-card rounded-lg border">
          <div class="p-6 flex items-center justify-between">
            <div>
              <h3 class="text-xl font-bold">Local Explorer</h3>
              <p class="text-muted-foreground text-sm">
                Root is always <code>C:\edms</code>. We list only the <b>parent folders</b> there (e.g., <code>COM-2025-0001</code>).
                Selecting one migrates the entire parent recursively.
              </p>
            </div>
            <div class="flex gap-2">
              <button id="refresh-all-btn" class="border bg-white hover:bg-muted py-2 px-3 rounded-md text-sm">
                <i class="fa-solid fa-rotate mr-2"></i>Refresh
              </button>
              <button id="migrate-selected-btn" class="bg-indigo-600 hover:bg-indigo-500 text-white py-2 px-3 rounded-md text-sm disabled:opacity-50" disabled>
                <i class="fa-solid fa-cloud-arrow-up mr-2"></i>Migrate Selected Parent
              </button>
            </div>
          </div>

          <div class="px-6 pb-6">
            <div class="mb-3">
              <div id="root-status" class="text-sm">
                <span class="mr-2">Root:</span>
                <span id="root-path" class="font-mono text-slate-800">Not initialized</span>
              </div>
            </div>
            <div id="explorer" class="border rounded-lg overflow-hidden">
              <table class="w-full text-sm">
                <thead class="bg-muted">
                  <tr class="border-b">
                    <th class="text-left p-3 w-10"></th>
                    <th class="text-left p-3">Parent Folder</th>
                    <th class="text-left p-3">Quick Check</th>
                    <th class="text-left p-3">Actions</th>
                  </tr>
                </thead>
                <tbody id="explorer-rows"></tbody>
              </table>
            </div>
            <p class="text-xs text-muted-foreground mt-2">
              Extraction lands inside <code>/public_html/storage/[Parent]</code> — subfolders/files included.
            </p>
          </div>
        </div>

        <!-- Logs + Server Browser -->
        <div class="bg-card rounded-lg border mt-6">
          <div class="p-6 pb-0">
            <h3 class="text-xl font-bold">Server Storage & Logs</h3>
            <p class="text-muted-foreground">Browse <code>/public_html/storage</code>, preview files, and review migration logs (persisted in <code>_migrations.json</code>).</p>
          </div>

          <div class="px-6 pt-4">
            <div class="flex gap-2">
              <button id="tab-server" class="border bg-white hover:bg-muted py-2 px-3 rounded-md text-sm">Server Browser</button>
              <button id="tab-logs" class="border bg-white hover:bg-muted py-2 px-3 rounded-md text-sm">Migration Logs</button>
            </div>
          </div>

          <!-- Server Browser -->
          <div id="server-panel" class="p-6">
            <div class="flex items-center justify-between mb-3">
              <div>
                <div class="text-sm"><span class="mr-2">Path:</span><span id="srv-path" class="font-mono">/storage</span></div>
                <div id="srv-crumbs" class="text-xs text-muted-foreground mt-1"></div>
              </div>
              <button id="srv-refresh" class="border bg-white hover:bg-muted py-2 px-3 rounded-md text-sm"><i class="fa-solid fa-rotate mr-2"></i>Refresh</button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
              <div class="lg:col-span-2 border rounded-lg overflow-hidden">
                <table class="w-full text-sm">
                  <thead class="bg-muted">
                    <tr class="border-b">
                      <th class="text-left p-3 w-10"></th>
                      <th class="text-left p-3">Name</th>
                      <th class="text-left p-3">Type</th>
                      <th class="text-left p-3">Size</th>
                      <th class="text-left p-3">Modified</th>
                      <th class="text-left p-3">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="srv-rows"></tbody>
                </table>
              </div>
              <div class="lg:col-span-1">
                <div class="border rounded-lg p-3">
                  <div class="font-medium mb-2">Preview</div>
                  <div id="preview-box" class="text-sm text-muted-foreground">
                    Select a PDF or image to preview.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Logs Panel -->
          <div id="logs-panel" class="p-6 hidden">
            <div id="logs-content"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Progress Modal -->
    <div id="progress-modal" class="fixed inset-0 bg-black/30 hidden items-center justify-center">
      <div class="bg-white border rounded-lg w-full max-w-md mx-4">
        <div class="p-6">
          <h3 id="progress-title" class="text-xl font-bold">Working...</h3>
          <p id="progress-sub" class="text-muted-foreground">Please wait</p>
        </div>
        <div class="px-6 pb-6">
          <div class="space-y-4">
            <div class="w-full bg-muted rounded-full h-2"><div class="progress-bar bg-blue-600 h-2 rounded-full" style="width:0%"></div></div>
            <div id="progress-text" class="text-sm text-center text-muted-foreground">0%</div>
            <div id="progress-done" class="flex items-center justify-center gap-2 text-green-700 hidden">
              <i class="fa-solid fa-circle-check"></i><span>Done!</span>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /container -->

  <script>
    // ====== CONFIG ======
    const MIGRATE_ENDPOINT = window.location.pathname; // same PHP file
    const API_LIST = window.location.pathname + '?api=list';
    const API_LOGS = window.location.pathname + '?api=logs';
    const DEST_LABEL = '/public_html/storage';
    const FIXED_ROOT_LABEL = 'C:\\\\edms';

    // ====== DEMO DATA ======
    const availableFiles = [
      { fileNo:"RES-2025-0001", fileName:"Certificate of Occupancy - Alhaji Ibrahim Dantata", dateCreated:"2025-01-15", type:"Certificate" },
      { fileNo:"RES-2025-0002", fileName:"Site Plan - Hajiya Amina Yusuf", dateCreated:"2025-01-14", type:"Site Plan" },
      { fileNo:"COM-2025-0001", fileName:"Building Plan Approval - Kano Business Center", dateCreated:"2025-01-13", type:"Building Plan" },
      { fileNo:"IND-2025-0001", fileName:"Industrial Plot Allocation - Manufacturing Zone", dateCreated:"2025-01-12", type:"Allocation" },
      { fileNo:"RES-2025-0003", fileName:"Deed of Assignment - Malam Sani Ahmed", dateCreated:"2025-01-11", type:"Deed" },
    ];

    // ====== STATE ======
    const state = {
      selectedFile: null,
      edmsRoot: null,        // {id, name:'edms', handle}
      roots: [],
      selectedFolderId: null,
      logs: [],
      // server browser
      srvSubPath: '',        // '' means /storage root
      srvItems: [],
    };
    let idSeq = 1;

    // ====== Permission helpers (local FS) ======
    async function verifyPerm(handle, mode = 'readwrite') {
      if (!handle) return false;
      if ('requestPermission' in handle) {
        const opts = { mode };
        let perm = await handle.queryPermission(opts);
        if (perm === 'granted') return true;
        if (perm === 'prompt') perm = await handle.requestPermission(opts);
        return perm === 'granted';
      }
      return false;
    }

    // ====== EDMS ROOT setup (one-time) ======
    async function initEdmsRoot() {
      if (!('showDirectoryPicker' in window)) {
        throw new Error('Use Chrome/Edge over HTTPS or localhost for file system access.');
      }
      const picked = await window.showDirectoryPicker({ mode: 'readwrite', startIn: 'desktop' });
      let edmsDirHandle;
      if (picked.name.toLowerCase() === 'edms') {
        edmsDirHandle = picked;
      } else {
        edmsDirHandle = await picked.getDirectoryHandle('edms', { create: true });
      }
      const ok = await verifyPerm(edmsDirHandle, 'readwrite');
      if (!ok) throw new Error('Permission to C:\\edms was not granted.');

      const id = 'root-' + (idSeq++);
      state.edmsRoot = { id, name: 'edms', handle: edmsDirHandle };
      state.roots = [ state.edmsRoot ];
      await refreshRoot(id);
      renderExplorer();
      renderRootStatus();
    }

    function renderRootStatus() {
      const pathEl = document.getElementById('root-path');
      pathEl.textContent = state.edmsRoot ? FIXED_ROOT_LABEL : 'Not initialized';
      document.getElementById('create-local-btn').disabled = !(state.selectedFile && state.edmsRoot);
    }

    // ====== UI helpers ======
    function showToast(title, desc, variant="default"){
      const t=document.createElement('div');
      t.className=`fixed bottom-4 right-4 p-4 rounded-lg border z-50 ${variant==="default" ? "bg-green-50 border-green-200 text-green-800":"bg-red-50 border-red-200 text-red-800"}`;
      t.innerHTML=`<h4 class="font-medium">${title}</h4><p class="text-sm">${desc}</p>`;
      document.body.appendChild(t); setTimeout(()=>t.remove(),3500);
    }
    function openProgress(title, sub, pct=0){
      const m=document.getElementById('progress-modal');
      document.getElementById('progress-title').textContent=title;
      document.getElementById('progress-sub').textContent=sub||'';
      document.querySelector('#progress-modal .progress-bar').style.width=pct+'%';
      document.getElementById('progress-text').textContent=pct+'%';
      document.getElementById('progress-done').classList.add('hidden');
      m.classList.remove('hidden'); m.classList.add('flex');
    }
    function setProgress(pct){
      document.querySelector('#progress-modal .progress-bar').style.width=pct+'%';
      document.getElementById('progress-text').textContent=pct+'%';
    }
    function markProgressDone(){ document.getElementById('progress-done').classList.remove('hidden'); }
    function closeProgress(){ const m=document.getElementById('progress-modal'); m.classList.add('hidden'); m.classList.remove('flex'); }

    // ====== Local FS helpers ======
    async function ensureDir(handle,name){ return handle.getDirectoryHandle(name,{create:true}); }
    async function listChildren(dirHandle){
      const items = [];
      for await (const [name, entry] of dirHandle.entries()) {
        if (entry.kind === 'directory') items.push({ name, kind: entry.kind, handle: entry });
      }
      items.sort((a,b)=> a.name.localeCompare(b.name));
      return items;
    }
    async function countFilesRecursive(dirHandle){
      let count = 0;
      for await (const [name, entry] of dirHandle.entries()){
        if (entry.kind === 'file') count++;
        else if (entry.kind === 'directory') count += await countFilesRecursive(entry);
      }
      return count;
    }
    async function analyzeA4A3(child){
      const meta = { a4:{exists:false,count:0}, a3:{exists:false,count:0} };
      try { const a4 = await child.handle.getDirectoryHandle('A4'); meta.a4.exists = true; meta.a4.count = await countFilesRecursive(a4); } catch(e){}
      try { const a3 = await child.handle.getDirectoryHandle('A3'); meta.a3.exists = true; meta.a3.count = await countFilesRecursive(a3); } catch(e){}
      child.meta = meta;
    }

    // ====== Create local structure under C:\edms ======
    async function createLocalStructure(folderName){
      if (!state.edmsRoot) await initEdmsRoot();
      const ok = await verifyPerm(state.edmsRoot.handle, 'readwrite');
      if (!ok) throw new Error('Permission to C:\\edms is required.');
      const fileDir = await ensureDir(state.edmsRoot.handle, folderName);
      await ensureDir(fileDir,'A4');
      await ensureDir(fileDir,'A3');
      await refreshRoot(state.edmsRoot.id);
      renderExplorer();
      return { parentName: 'C:\\edms', folderName };
    }

    // ====== ZIP (recursive) ======
    async function zipDirectoryToBlob(dirHandle) {
      const zip = new JSZip();
      async function add(handle, zipFolder) {
        for await (const [name, entry] of handle.entries()) {
          if (entry.kind === 'file') {
            const file = await entry.getFile();
            zipFolder.file(name, file);
          } else if (entry.kind === 'directory') {
            const newFolder = zipFolder.folder(name);
            await add(entry, newFolder);
          }
        }
      }
      await add(dirHandle, zip);
      return await zip.generateAsync({ type: "blob" });
    }

    // ====== Migration ======
    async function migrateParent(dirHandle, parentName){
      openProgress('Preparing Upload', `${parentName} → ${DEST_LABEL}`, 10);
      const zipBlob = await zipDirectoryToBlob(dirHandle);
      setProgress(40);
      const form = new FormData();
      form.append('folderName', parentName);
      form.append('zip', zipBlob, `${parentName}.zip`);
      const res = await fetch(MIGRATE_ENDPOINT, { method:'POST', body: form });
      const data = await res.json().catch(()=>({}));
      if (res.ok && data.ok) {
        setProgress(100); markProgressDone(); setTimeout(closeProgress, 500);
        showToast('Migration Complete', `${parentName} uploaded to server.`);
        // refresh logs & server view
        await fetchLogs();
        await fetchServerList(state.srvSubPath);
      } else {
        closeProgress();
        const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
        throw new Error(msg);
      }
    }

    // ====== Explorer (local) ======
    async function refreshRoot(rootId){
      const root = state.roots.find(r=>r.id===rootId);
      if(!root) return;
      const kids = await listChildren(root.handle);
      root.children = kids.map(x=>({
        id: root.id + '-' + x.name,
        name: x.name,
        handle: x.handle,
        parentId: root.id,
        meta: null
      }));
    }
    function selectExplorerFolder(childId){
      state.selectedFolderId = childId;
      document.getElementById('migrate-selected-btn').disabled = !childId;
      renderExplorer();
    }
    function explorerRow(html){ const tr=document.createElement('tr'); tr.className='border-b row'; tr.innerHTML=html; return tr; }

    function renderExplorer(){
      const tbody=document.getElementById('explorer-rows');
      tbody.innerHTML='';
      if (!state.edmsRoot){
        tbody.appendChild(explorerRow(`
          <td class="p-3"></td>
          <td class="p-3 text-muted-foreground" colspan="3">
            Root not initialized. Click <b>Initialize C:\\edms</b> on the left and choose <b>C:\\</b> (or an existing <b>edms</b>) once.
          </td>
        `));
        return;
      }
      const r = explorerRow(`
        <td class="p-3"><i class="fa-regular fa-hard-drive"></i></td>
        <td class="p-3 font-medium">edms</td>
        <td class="p-3"><span class="pill">root</span></td>
        <td class="p-3">
          <button class="refresh-root border bg-white hover:bg-muted py-1 px-2 rounded-md text-xs">Refresh</button>
        </td>
      `);
      r.querySelector('.refresh-root').addEventListener('click', async ()=>{
        await refreshRoot(state.edmsRoot.id); renderExplorer();
      });
      tbody.appendChild(r);

      const root = state.edmsRoot;
      if (root.children && root.children.length){
        for (const child of root.children){
          const selected = (state.selectedFolderId===child.id);
          const a4Badge = child.meta
            ? (child.meta.a4.exists ? `<span class="pill pill-ok">A4: ${child.meta.a4.count}</span>` : `<span class="pill pill-warn">A4: 0</span>`)
            : `<span class="pill">A4: ?</span>`;
          const a3Badge = child.meta
            ? (child.meta.a3.exists ? `<span class="pill pill-ok">A3: ${child.meta.a3.count}</span>` : `<span class="pill pill-warn">A3: 0</span>`)
            : `<span class="pill">A3: ?</span>`;
          const c = explorerRow(`
            <td class="p-3"></td>
            <td class="p-3">
              <button class="select-child ${selected ? 'text-indigo-700 font-semibold' : 'text-slate-800'}">
                <i class="fa-regular fa-folder mr-2"></i>${child.name}
              </button>
            </td>
            <td class="p-3 space-x-2">${a4Badge} ${a3Badge}</td>
            <td class="p-3">
              <div class="flex gap-2">
                <button class="detail-child border bg-white hover:bg-muted py-1 px-2 rounded-md text-xs"><i class="fa-regular fa-eye mr-1"></i>Details</button>
                <button class="select-child border bg-white hover:bg-muted py-1 px-2 rounded-md text-xs">Select</button>
                <button class="migrate-child bg-indigo-600 hover:bg-indigo-500 text-white py-1 px-2 rounded-md text-xs">Migrate Parent</button>
              </div>
            </td>
          `);
          c.querySelectorAll('.select-child').forEach(btn=>btn.addEventListener('click', ()=>selectExplorerFolder(child.id)));
          c.querySelector('.detail-child').addEventListener('click', async ()=>{
            try{ await analyzeA4A3(child); renderExplorer(); }
            catch(e){ showToast('Details Failed', e.message || String(e), 'error'); }
          });
          c.querySelector('.migrate-child').addEventListener('click', async ()=>{
            try { await migrateParent(child.handle, child.name); }
            catch(e){ showToast('Migration Failed', e.message || String(e), 'error'); }
          });
          tbody.appendChild(c);
        }
      } else {
        const empty = explorerRow(`
          <td class="p-3"></td>
          <td class="p-3 text-muted-foreground" colspan="3">No folders found under C:\\edms.</td>
        `);
        tbody.appendChild(empty);
      }
    }

    function renderFileList(){
      const list=document.getElementById('file-list'); list.innerHTML='';
      const q=(document.getElementById('search-input').value||'').toLowerCase();
      availableFiles
        .filter(f=>f.fileNo.toLowerCase().includes(q)||f.fileName.toLowerCase().includes(q))
        .forEach(file=>{
          const el=document.createElement('div');
          el.className=`p-3 border rounded-lg cursor-pointer transition-colors hover:bg-muted`;
          el.innerHTML=`
            <div class="flex items-start justify-between">
              <div>
                <p class="font-medium text-sm">${file.fileNo}</p>
                <p class="text-xs text-muted-foreground">${file.fileName}</p>
                <div class="flex items-center gap-2 mt-1">
                  <span class="text-xs bg-muted text-muted-foreground py-0.5 px-2 rounded-md">${file.type}</span>
                  <span class="text-xs text-muted-foreground">${file.dateCreated}</span>
                </div>
              </div>
              <span class="text-xs bg-green-50 text-green-700 border border-green-200 py-0.5 px-2 rounded-md">Ready</span>
            </div>`;
          el.addEventListener('click', ()=>{
            state.selectedFile=file;
            document.getElementById('selected-file-info').classList.remove('hidden');
            document.getElementById('selected-file-no').textContent=file.fileNo;
            document.getElementById('selected-file-name').textContent=file.fileName;
            document.getElementById('create-local-btn').disabled = !(state.edmsRoot && state.selectedFile);
          });
          list.appendChild(el);
        });
    }

    // ====== SERVER BROWSER ======
    async function fetchServerList(subPath=''){
      const url = new URL(API_LIST, window.location.href);
      if (subPath) url.searchParams.set('path', subPath);
      const res = await fetch(url);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP '+res.status));
      state.srvSubPath = data.sub || '';
      state.srvItems = data.items || [];
      renderServerPath(data);
      renderServerRows();
      clearPreview();
    }
    function renderServerPath(data){
      document.getElementById('srv-path').textContent = '/storage' + (state.srvSubPath ? '/'+state.srvSubPath : '');
      const bc = data.crumbs || [];
      const cont = document.getElementById('srv-crumbs');
      if (!bc.length) { cont.textContent = 'Root'; return; }
      cont.innerHTML = bc.map((c,i)=> {
        return `<button data-path="${c.path}" class="text-blue-700 hover:underline">${c.name}</button>${i<bc.length-1?' / ':''}`;
      }).join('');
      cont.querySelectorAll('button').forEach(btn=>{
        btn.addEventListener('click',()=> fetchServerList(btn.dataset.path));
      });
    }
    function srvRow(html){ const tr=document.createElement('tr'); tr.className='border-b row'; tr.innerHTML=html; return tr; }
    function fmtBytes(n){
      if (!n && n!==0) return '-';
      if (n < 1024) return n + ' B';
      if (n < 1024*1024) return (n/1024).toFixed(1)+' KB';
      return (n/(1024*1024)).toFixed(1)+' MB';
    }
    function fmtDate(ts){
      if (!ts) return '-';
      const d = new Date(ts*1000);
      return d.toLocaleString();
    }
    function renderServerRows(){
      const tbody = document.getElementById('srv-rows');
      tbody.innerHTML = '';
      if (!state.srvItems.length){
        tbody.appendChild(srvRow(`<td class="p-3" colspan="6" class="text-muted-foreground">Empty</td>`));
        return;
      }
      // parent up link if not at root
      if (state.srvSubPath){
        const up = state.srvSubPath.split('/').slice(0,-1).join('/');
        const tr = srvRow(`
          <td class="p-3"><i class="fa-regular fa-folder"></i></td>
          <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${up}">Go Back</button></td>
          <td class="p-3">dir</td>
          <td class="p-3">-</td>
          <td class="p-3">-</td>
          <td class="p-3"></td>
        `);
        tr.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList(up));
        tbody.appendChild(tr);
      }
      for (const item of state.srvItems){
        if (item.type === 'dir'){
          const tr = srvRow(`
            <td class="p-3"><i class="fa-regular fa-folder"></i></td>
            <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${(state.srvSubPath?state.srvSubPath+'/':'')+item.name}">${item.name}</button></td>
            <td class="p-3">dir</td>
            <td class="p-3">-</td>
            <td class="p-3">${fmtDate(item.mtime)}</td>
            <td class="p-3"></td>
          `);
          tr.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList((state.srvSubPath?state.srvSubPath+'/':'')+item.name));
          tbody.appendChild(tr);
        } else {
          const href = item.href || '#';
          const tr = srvRow(`
            <td class="p-3"><i class="fa-regular fa-file"></i></td>
            <td class="p-3">${item.name}</td>
            <td class="p-3">file</td>
            <td class="p-3">${fmtBytes(item.size)}</td>
            <td class="p-3">${fmtDate(item.mtime)}</td>
            <td class="p-3">
              <div class="flex gap-2">
                <button class="srv-preview border bg-white hover:bg-muted py-1 px-2 rounded-md text-xs" data-href="${href}" data-name="${item.name}">Preview</button>
                <a class="border bg-white hover:bg-muted py-1 px-2 rounded-md text-xs" href="${href}" target="_blank" rel="noopener">Open</a>
              </div>
            </td>
          `);
          tr.querySelector('.srv-preview').addEventListener('click', (e)=> previewFile(e.currentTarget.dataset.href, e.currentTarget.dataset.name));
          tbody.appendChild(tr);
        }
      }
    }

    function clearPreview(){
      const box = document.getElementById('preview-box');
      box.innerHTML = `<div class="text-sm text-muted-foreground">Select a PDF or image to preview.</div>`;
    }
    function previewFile(href, name){
      const box = document.getElementById('preview-box');
      const ext = (name.split('.').pop() || '').toLowerCase();
      box.innerHTML = '';
      if (['png','jpg','jpeg','gif','webp'].includes(ext)) {
        const img = document.createElement('img');
        img.src = href; img.alt = name; img.className = 'max-w-full rounded border';
        box.appendChild(img);
      } else if (ext === 'pdf') {
        const iframe = document.createElement('iframe');
        iframe.src = href; iframe.className='w-full'; iframe.style.minHeight='420px';
        box.appendChild(iframe);
      } else {
        box.innerHTML = `<div class="text-sm">No inline preview for <code>${ext||'unknown'}</code>. <a class="text-blue-700 underline" href="${href}" target="_blank">Open</a></div>`;
      }
    }

    // ====== Logs (server) ======
    async function fetchLogs(){
      const res = await fetch(API_LOGS);
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP '+res.status));
      state.logs = data.logs || [];
      renderLogs();
    }
    function renderLogs(){
      const root=document.getElementById('logs-content');
      if(state.logs.length===0){
        root.innerHTML = `
          <div class="text-center py-8">
            <i class="fa-solid fa-clock-rotate-left text-3xl text-muted-foreground mx-auto mb-4"></i>
            <p class="text-muted-foreground">No migrations yet</p>
            <p class="text-sm text-muted-foreground">After migrating, entries will appear here.</p>
          </div>`;
        return;
      }
      root.innerHTML = `
        <div class="border rounded-lg overflow-hidden">
          <table class="w-full text-sm">
            <thead class="bg-muted">
              <tr class="border-b">
                <th class="text-left p-3">When</th>
                <th class="text-left p-3">Parent Folder</th>
                <th class="text-left p-3">Server Path</th>
              </tr>
            </thead>
            <tbody>
              ${state.logs.map(m=>`
                <tr class="border-b">
                  <td class="p-3">${m.when || '-'}</td>
                  <td class="p-3 font-mono">${m.folder || '-'}</td>
                  <td class="p-3 font-mono"><a class="text-blue-700 hover:underline" href="${m.serverPath||'#'}" target="_blank" rel="noopener">${m.serverPath||'-'}</a></td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>`;
    }

    // ====== Tabs (Server/Logs) ======
    function showServerTab(){ document.getElementById('server-panel').classList.remove('hidden'); document.getElementById('logs-panel').classList.add('hidden'); }
    function showLogsTab(){ document.getElementById('logs-panel').classList.remove('hidden'); document.getElementById('server-panel').classList.add('hidden'); }

    // ====== EVENTS ======
    document.addEventListener('DOMContentLoaded', async ()=>{
      // Left pane
      renderFileList();
      renderExplorer();
      renderRootStatus();

      document.getElementById('init-edms-btn').addEventListener('click', async ()=>{
        try { await initEdmsRoot(); showToast('C:\\edms Ready', 'Access granted and initialized.'); }
        catch(e){ showToast('Init Failed', e.message || String(e), 'error'); }
      });

      document.getElementById('search-input').addEventListener('input', renderFileList);

      document.getElementById('create-local-btn').addEventListener('click', async ()=>{
        if(!state.selectedFile) return showToast('Pick a File','Select a file number first.','error');
        try{
          const res = await createLocalStructure(state.selectedFile.fileNo);
          showToast('Local Folders Created', `${res.parentName}\\${res.folderName}`);
        }catch(e){ showToast('Creation Failed', e.message || String(e), 'error'); }
      });

      // Right pane (local explorer)
      document.getElementById('refresh-all-btn').addEventListener('click', async ()=>{
        if (!state.edmsRoot) { showToast('Root Needed','Initialize C:\\edms first.','error'); return; }
        await refreshRoot(state.edmsRoot.id);
        renderExplorer();
      });
      document.getElementById('migrate-selected-btn').addEventListener('click', async ()=>{
        if(!state.selectedFolderId){ showToast('Nothing Selected','Pick a parent folder first.','error'); return; }
        const child = state.edmsRoot.children?.find(c=>c.id===state.selectedFolderId);
        if (child){
          try { await migrateParent(child.handle, child.name); }
          catch(e){ showToast('Migration Failed', e.message || String(e), 'error'); }
        } else {
          showToast('Not Found','Selected folder no longer available. Refresh and try again.', 'error');
        }
      });

      // Server tabs
      document.getElementById('tab-server').addEventListener('click', showServerTab);
      document.getElementById('tab-logs').addEventListener('click', showLogsTab);

      // Server browser
      document.getElementById('srv-refresh').addEventListener('click', ()=> fetchServerList(state.srvSubPath));

      // Initial server loads
      await fetchServerList('');
      await fetchLogs();
    });
  </script>
</body>
</html>
