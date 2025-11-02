<?php
// create.php — Single-file UI + API
// - Upload a *local folder* (client zips files via JSZip).
// - Server extracts zip into /public_html/storage/[FileNo] (this folder must not already exist).
// - Server list/preview confined to /public_html/storage
// - Durable logs in /public_html/storage/_migrations.json

/* -------------------------
   Helpers (server-side)
------------------------- */

function storage_root(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage';
}

function public_href_for(string $absPath): string {
    $abs = str_replace('\\', '/', $absPath);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    if ($docRoot && strpos($abs, $docRoot) === 0) {
        return substr($abs, strlen($docRoot));
    }
    $base = rtrim(str_replace('\\', '/', __DIR__), '/');
    if (strpos($abs, $base) === 0) {
        $rel = substr($abs, strlen($base));
        return ($rel[0] ?? '') === '/' ? $rel : '/'.$rel;
    }
    return '/storage';
}

function safe_join_under_storage(string $sub): string {
    $root = storage_root();
    $path = $root . DIRECTORY_SEPARATOR . $sub;
    $realRoot = realpath($root) ?: $root;
    $realPath = realpath($path) ?: $path;
    $realRootNorm = rtrim(str_replace('\\','/',$realRoot), '/');
    $realPathNorm = rtrim(str_replace('\\','/',$realPath), '/');
    if (strpos($realPathNorm, $realRootNorm) !== 0) {
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
            'size' => $isDir ? null : @filesize($full),
            'mtime'=> @filemtime($full) ?: null,
            'href' => $isDir ? null : public_href_for($full),
        ];
        $out[] = $item;
    }
    usort($out, function($a,$b){
        if ($a['type'] === $b['type']) return strcasecmp($a['name'],$b['name']);
        return $a['type']==='dir' ? -1 : 1;
    });
    return $out;
}

function logs_path(): string { return storage_root() . DIRECTORY_SEPARATOR . '_migrations.json'; }
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
function move_contents_up(string $sourceDir, string $targetDir): bool {
    if (!is_dir($sourceDir)) return false;
    $items = scandir($sourceDir);
    if (!$items) return false;
    $success = true;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $item;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item;
        if (file_exists($targetPath)) { $success = false; continue; }
        if (!rename($sourcePath, $targetPath)) { $success = false; }
    }
    return $success;
}

/* -------------------------
   API endpoints (GET)
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'] ?? '';

    $root = storage_root();
    if (!is_dir($root)) { @mkdir($root, 0775, true); }

    if ($api === 'list') {
        $sub = isset($_GET['path']) ? trim($_GET['path'], "/\\") : '';
        if (strpos($sub, '..') !== false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad path']); exit; }
        $dir = safe_join_under_storage($sub);
        $items = list_dir_for_api($dir);

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

    // Expose PHP upload limits so the client can preflight
    if ($api === 'limits') {
        $toBytes = function($val) {
            $val = trim((string)$val);
            $num = (int)$val;
            $unit = strtolower(substr($val, -1));
            switch ($unit) {
                case 'g': return $num * 1024 * 1024 * 1024;
                case 'm': return $num * 1024 * 1024;
                case 'k': return $num * 1024;
                default:  return (int)$val;
            }
        };
        $out = [
            'ok' => true,
            'limits' => [
                'post_max_size'       => ['raw'=>ini_get('post_max_size'),       'bytes'=>$toBytes(ini_get('post_max_size'))],
                'upload_max_filesize' => ['raw'=>ini_get('upload_max_filesize'), 'bytes'=>$toBytes(ini_get('upload_max_filesize'))],
                'memory_limit'        => ['raw'=>ini_get('memory_limit'),        'bytes'=>$toBytes(ini_get('memory_limit'))],
                'max_execution_time'  => ['raw'=>ini_get('max_execution_time')],
                'max_input_time'      => ['raw'=>ini_get('max_input_time')],
                'max_file_uploads'    => ['raw'=>ini_get('max_file_uploads')],
            ]
        ];
        echo json_encode($out);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Unknown API']);
    exit;
}

/* -------------------------
   Migration endpoint (POST)
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api'])) {
    header('Content-Type: application/json');

    try {
        if (empty($_POST['folderName']) || !isset($_FILES['zip'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing folderName or zip']);
            exit;
        }
        
        $folderName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $_POST['folderName']);
        $upload = $_FILES['zip'];
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload failed (php code '.$upload['error'].')']);
            exit;
        }

        $root = storage_root();
        if (!is_dir($root)) {
            if (!mkdir($root, 0775, true)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Could not create storage root']);
                exit;
            }
        }

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

        $potentialInnerFolder = $target . DIRECTORY_SEPARATOR . $folderName;
        if (is_dir($potentialInnerFolder)) {
            if (!move_contents_up($potentialInnerFolder, $target)) {
                error_log("Failed to move contents from $potentialInnerFolder to $target");
            } else {
                @rmdir($potentialInnerFolder);
            }
        }

        $serverPathWeb = public_href_for($target);
        append_log([
            'when' => date('Y-m-d H:i:s'),
            'folder' => $folderName,
            'serverPath' => $serverPathWeb
        ]);

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
   Save Image endpoint (POST)
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'save-image') {
    header('Content-Type: application/json');
    
    try {
        if (empty($_POST['filePath']) || empty($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing filePath or image data']);
            exit;
        }
        
        $filePath = $_POST['filePath'];
        $imageFile = $_FILES['image'];
        
        $storageRoot = storage_root();
        $targetPath = safe_join_under_storage($filePath);
        
        if (strpos($targetPath, $storageRoot) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid file path']);
            exit;
        }
        if (!file_exists($targetPath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'File not found']);
            exit;
        }
        if (!is_writable($targetPath)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'File is not writable']);
            exit;
        }
        
        if ($imageFile['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Image upload failed']);
            exit;
        }
        
        $imageInfo = getimagesize($imageFile['tmp_name']);
        if (!$imageInfo) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid image file']);
            exit;
        }
        
        if (move_uploaded_file($imageFile['tmp_name'], $targetPath)) {
            clearstatcache(true, $targetPath);
            echo json_encode([
                'ok' => true,
                'message' => 'Image saved successfully',
                'filePath' => $filePath,
                'fileSize' => filesize($targetPath),
                'mtime' => filemtime($targetPath),
                'cacheBuster' => time()
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to save image']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* -------------------------
   Delete File endpoint (POST)
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'delete-file') {
    header('Content-Type: application/json');
    
    try {
        if (empty($_POST['filePath'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing filePath']);
            exit;
        }
        
        $filePath = $_POST['filePath'];
        $storageRoot = storage_root();
        $targetPath = safe_join_under_storage($filePath);
        
        if (strpos($targetPath, $storageRoot) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid file path']);
            exit;
        }
        if (!file_exists($targetPath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'File not found']);
            exit;
        }
        if (is_dir($targetPath)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Cannot delete directories']);
            exit;
        }
        
        if (unlink($targetPath)) {
            echo json_encode([
                'ok' => true,
                'message' => 'File deleted successfully',
                'filePath' => $filePath
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to delete file']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
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
  <title>Blind Scanning Workflow — Upload & Migrate</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"/>
  <style>
    body { background:#ffffff; color:#0f172a; }
    .text-muted-foreground { color:#64748b; }
    .bg-card { background:#ffffff; }
    .bg-muted { background:#f1f5f9; }
    .border { border-color:#e2e8f0; }
    .progress-bar { transition: width .3s ease; }
    .row:hover { background:#f8fafc; }
    .btn { border:1px solid #e2e8f0; background:#fff; padding:.5rem .75rem; border-radius:.5rem; font-size:.9rem; }
    .btn:hover { background:#f8fafc; }
    .btn-primary{ background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .btn-primary:hover{ background:#4338ca; }
    .btn-success{ background:#10b981; border-color:#10b981; color:#fff; }
    .btn-success:hover{ background:#059669; }
    .btn-danger{ background:#dc2626; border-color:#dc2626; color:#fff; }
    .btn-danger:hover{ background:#b91c1c; }
    .preview-box { min-height: 200px; }

    .preview-modal { background: rgba(0, 0, 0, 0.9); }
    .preview-content { max-width: 95vw; max-height: 95vh; }
    .preview-toolbar { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); }
    .preview-nav-btn {
      background: rgba(255, 255, 255, 0.9); border: none; width: 50px; height: 50px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; font-size: 1.25rem; transition: all 0.2s ease;
    }
    .preview-nav-btn:hover { background: rgba(255, 255, 255, 1); transform: scale(1.1); }
    .pdf-iframe { width: 100%; height: 80vh; border: none; }

    .preview-container { position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .preview-image-editable { max-width: 100%; max-height: 80vh; transition: transform 0.3s ease; object-fit: contain; }

    .crop-overlay { position: absolute; border: 2px dashed #4f46e5; background: rgba(79, 70, 229, 0.1); cursor: move; }
    .crop-handle { position: absolute; width: 12px; height: 12px; background: #4f46e5; border: 2px solid white; border-radius: 50%; }
    .crop-handle-nw { top: -6px; left: -6px; cursor: nw-resize; }
    .crop-handle-ne { top: -6px; right: -6px; cursor: ne-resize; }
    .crop-handle-sw { bottom: -6px; left: -6px; cursor: sw-resize; }
    .crop-handle-se { bottom: -6px; right: -6px; cursor: se-resize; }
    
    .auto-convert-badge {
      background: #10b981;
      color: white;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
      margin-left: 8px;
    }
  </style>
</head>
<body class="min-h-screen">
  <div class="container mx-auto py-6 space-y-6">
    <div>
      <h1 class="text-3xl font-bold">Blind Scanning Workflow — Upload & Migrate</h1>
      <p class="text-muted-foreground">
        Step 1: Enter the <b>File Number</b>. Step 2: Pick the whole <b>local folder</b> you scanned (it must include A4/A3 subfolders).
        Step 3: Click <b>Migrate</b>. The server will create <code>/public_html/storage/[FileNo]</code> and extract all contents.
      </p>
      <p class="text-sm mt-1">Destination: <code>/public_html/storage/[FileNo]</code></p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
      <div class="bg-card border rounded-lg p-6 lg:col-span-2">
        <h2 class="text-xl font-bold mb-4"><i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload & Migrate</h2>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">File Number (target on server)</label>
            <input id="fileNo" type="text" class="w-full border rounded-md px-3 py-2" placeholder="e.g., RES-2025-0001" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">Pick local folder</label>
            <input id="folderInput" type="file" webkitdirectory directory multiple class="border rounded-md px-3 py-2"/>
            <p class="text-xs text-muted-foreground mt-1">
              Use Chrome/Edge on desktop. The folder name should match the File Number (but we will allow migrate if the browser can't expose that name).
            </p>
          </div>
          
          <!-- PDF Conversion Option -->
          <div class="border-t pt-4">
            <div class="flex items-center space-x-2 mb-2">
              <input type="checkbox" id="convertPdfs" class="rounded border-gray-300">
              <label for="convertPdfs" class="text-sm font-medium">
                Convert PDF files to images (client-side)
                <span id="autoConvertBadge" class="auto-convert-badge hidden">Auto-activated</span>
              </label>
            </div>
            <p class="text-xs text-muted-foreground">
              PDF conversion is automatically activated when PDF files are detected. All PDF files will be converted to JPG images in your browser before upload.
              Images are placed in the same folder as the original PDF. No server dependencies required.
            </p>
            <div id="pdfConversionInfo" class="text-xs text-blue-600 mt-1 hidden">
              <i class="fa-solid fa-info-circle mr-1"></i>
              <span id="pdfConversionText"></span>
            </div>
          </div>

          <div id="status" class="text-sm text-muted-foreground">No folder selected.</div>
          <button id="migrateBtn" class="btn btn-primary w-full disabled:opacity-50" disabled>
            <i class="fa-solid fa-paper-plane mr-2"></i>Migrate to Server
          </button>
        </div>
      </div>

      <div class="bg-card border rounded-lg p-0 lg:col-span-3">
        <div class="p-6 pb-2">
          <h2 class="text-xl font-bold">Server Storage & Logs</h2>
          <p class="text-muted-foreground">Browse <code>/public_html/storage</code>, preview files, and review migration logs.</p>
        </div>
        <div class="px-6 pb-0">
          <div class="flex gap-2">
            <button id="tabServer" class="btn">Server Browser</button>
            <button id="tabLogs" class="btn">Migration Logs</button>
          </div>
        </div>

        <div id="serverPanel" class="p-6">
          <div class="flex items-center justify-between mb-3">
            <div>
              <div class="text-sm"><span class="mr-2">Path:</span><span id="srvPath" class="font-mono">/storage</span></div>
              <div id="srvCrumbs" class="text-xs text-muted-foreground mt-1">Root</div>
            </div>
            <button id="srvRefresh" class="btn"><i class="fa-solid fa-rotate mr-2"></i>Refresh</button>
          </div>

          <div class="border rounded-lg overflow-hidden">
            <table class="w-full text-sm">
              <thead class="bg-muted">
                <tr class="border-b">
                  <th class="text-left p-3 w-10"></th>
                  <th class="text-left p-3">Name</th>
                  <th class="text-left p-3">Size</th>
                  <th class="text-left p-3">Modified</th>
                  <th class="text-left p-3">Actions</th>
                </tr>
              </thead>
              <tbody id="srvRows"></tbody>
            </table>
          </div>

          <div class="mt-4 border rounded-lg p-3">
            <div class="font-medium mb-2">Quick Preview</div>
            <div id="previewBox" class="preview-box text-sm text-muted-foreground flex items-center justify-center">
              Select a file to preview.
            </div>
          </div>
        </div>

        <div id="logsPanel" class="p-6 hidden">
          <div id="logsContent"></div>
        </div>
      </div>
    </div>

    <!-- Progress Modal -->
    <div id="progressModal" class="fixed inset-0 bg-black/30 hidden items-center justify-center z-40">
      <div class="bg-white border rounded-lg w-full max-w-md mx-4">
        <div class="p-6">
          <h3 id="progressTitle" class="text-xl font-bold">Working...</h3>
          <p id="progressSub" class="text-muted-foreground">Please wait</p>
        </div>
        <div class="px-6 pb-6">
          <div class="space-y-4">
            <div class="w-full bg-muted rounded-full h-2"><div class="progress-bar bg-indigo-600 h-2 rounded-full" style="width:0%"></div></div>
            <div id="progressText" class="text-sm text-center text-muted-foreground">0%</div>
            <div id="progressDone" class="flex items-center justify-center gap-2 text-green-700 hidden">
              <i class="fa-solid fa-circle-check"></i><span>Done!</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="fixed inset-0 preview-modal hidden items-center justify-center z-50">
      <div class="preview-content bg-white rounded-lg w-full max-w-7xl mx-4 flex flex-col">
        <div class="preview-toolbar flex items-center justify-between p-4 border-b rounded-t-lg">
          <div class="flex items-center space-x-4">
            <button id="modalClose" class="text-gray-500 hover:text-gray-700">
              <i class="fa-solid fa-times text-xl"></i>
            </button>
            <h3 id="modalFileName" class="text-lg font-semibold"></h3>
            <div id="modalFileInfo" class="text-sm text-muted-foreground"></div>
          </div>
          <div class="edit-toolbar flex items-center space-x-2" id="imageEditToolbar" style="display: none;">
            <button class="btn btn-sm" title="Rotate Left" onclick="rotateImage(-90)"><i class="fa-solid fa-rotate-left"></i></button>
            <button class="btn btn-sm" title="Rotate Right" onclick="rotateImage(90)"><i class="fa-solid fa-rotate-right"></i></button>
            <button class="btn btn-sm" title="Crop" onclick="toggleCropMode()"><i class="fa-solid fa-crop"></i></button>
            <button class="btn btn-sm" title="Reset" onclick="resetImage()"><i class="fa-solid fa-refresh"></i></button>
            <button class="btn btn-sm btn-success" title="Save Edited" onclick="saveEditedImage()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <button class="btn btn-sm" title="Download Edited" onclick="downloadEditedImage()"><i class="fa-solid fa-download"></i> Download</button>
            <button class="btn btn-sm btn-danger" title="Delete File" onclick="deleteCurrentFile()"><i class="fa-solid fa-trash"></i> Delete</button>
            <div class="zoom-controls-static flex items-center space-x-1">
              <button class="btn btn-sm" title="Zoom Out" onclick="zoomImage(0.8)"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
              <span class="text-xs px-2" id="zoomLevel">100%</span>
              <button class="btn btn-sm" title="Zoom In" onclick="zoomImage(1.2)"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </div>
          </div>
        </div>

        <div class="preview-container flex-1 p-4 relative">
          <button id="modalPrev" class="preview-nav-btn absolute left-4 top-1/2 -translate-y-1/2"><i class="fa-solid fa-chevron-left"></i></button>
          <button id="modalNext" class="preview-nav-btn absolute right-4 top-1/2 -translate-y-1/2"><i class="fa-solid fa-chevron-right"></i></button>

          <div id="modalContent" class="w-full h-full flex items-center justify-center">
            <div class="text-muted-foreground">Loading preview...</div>
          </div>
        </div>

        <div class="preview-toolbar flex items-center justify-between p-4 border-t rounded-b-lg">
          <div class="text-sm text-muted-foreground"><span id="modalCounter">0/0</span> files</div>
          <div class="flex space-x-2">
            <button class="btn btn-sm" onclick="fitToScreen()"><i class="fa-solid fa-expand mr-1"></i> Fit</button>
            <button class="btn btn-sm" onclick="actualSize()"><i class="fa-solid fa-arrows-alt mr-1"></i> Actual Size</button>
          </div>
        </div>
      </div>
    </div>

  </div>

  <script>
    // ---- Endpoints
    const MIGRATE_ENDPOINT = window.location.pathname;
    const API_LIST = window.location.pathname + '?api=list';
    const API_LOGS = window.location.pathname + '?api=logs';
    const API_SAVE_IMAGE = window.location.pathname + '?api=save-image';
    const API_DELETE_FILE = window.location.pathname + '?api=delete-file';
    const API_LIMITS = window.location.pathname + '?api=limits';

    // ---- Preview modal state
    let previewState = { currentIndex: 0, fileList: [], isOpen: false, currentDirItems: [], currentFilePath: '' };

    // ---- Image editing state
    let currentImageState = {
      element: null,
      originalSrc: null,
      rotation: 0,
      scale: 1,
      isCropping: false,
      cropOverlay: null,
      cropStartX: 0,
      cropStartY: 0,
      cropWidth: 0,
      cropHeight: 0,
      currentFileName: '',
      displayedImageArea: null
    };

    // ---- Server limits
    let serverLimits = null;

    // ---- PDF Conversion State
    let hasPdfFiles = false;
    let pdfConversionEnabled = false;
    let pdfConversionResults = {
      converted: 0,
      failed: 0,
      total: 0,
      failedFiles: []
    };

    function parsePhpSizeToBytes(str){
      if (!str) return 0;
      const m = String(str).trim().match(/^(\d+)\s*([KMG])?$/i);
      if (!m) return Number(str) || 0;
      const n = parseInt(m[1],10);
      const u = (m[2]||'').toUpperCase();
      if (u === 'G') return n*1024*1024*1024;
      if (u === 'M') return n*1024*1024;
      if (u === 'K') return n*1024;
      return n;
    }

    async function fetchLimits(){
      try {
        const r = await fetch(API_LIMITS, {cache:'no-store'});
        const j = await r.json();
        if (j && j.ok) serverLimits = j.limits;
      } catch(e){ /* ignore */ }
    }

    // ---- PDF.js Functions
    async function convertPdfInBrowser(pdfFile) {
        // Load pdf.js library dynamically
        if (typeof pdfjsLib === 'undefined') {
            await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js');
            // Also load the worker
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.worker.min.js';
        }
        
        const pdf = await pdfjsLib.getDocument(URL.createObjectURL(pdfFile)).promise;
        const images = [];
        
        for (let i = 1; i <= pdf.numPages; i++) {
            const page = await pdf.getPage(i);
            const viewport = page.getViewport({ scale: 2.0 });
            
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            
            await page.render({
                canvasContext: ctx,
                viewport: viewport
            }).promise;
            
            const blob = await new Promise(resolve => {
                canvas.toBlob(resolve, 'image/jpeg', 0.85);
            });
            
            images.push({
                blob: blob,
                page: i,
                width: canvas.width,
                height: canvas.height
            });
        }
        
        return images;
    }

    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    // ---- Modal open/close
    function openPreviewModal(fileName) {
      const currentDir = previewState.currentDirItems || [];
      const previewableFiles = currentDir.filter(item => item.type==='file' && (item.name.toLowerCase().endsWith('.pdf') || item.name.toLowerCase().match(/\.(jpg|jpeg|png|gif|webp|bmp)$/i)));
      if (!previewableFiles.length) { alert('No previewable files (PDF or images) in this directory'); return; }
      const currentIndex = previewableFiles.findIndex(f => f.name === fileName);
      if (currentIndex === -1) { alert('File not found in previewable files'); return; }
      previewState.fileList = previewableFiles;
      previewState.currentIndex = currentIndex;
      previewState.isOpen = true;

      document.getElementById('previewModal').classList.remove('hidden');
      document.getElementById('previewModal').classList.add('flex');
      document.body.style.overflow = 'hidden';
      loadCurrentPreview();
    }

    function closePreviewModal() {
      document.getElementById('previewModal').classList.add('hidden');
      document.getElementById('previewModal').classList.remove('flex');
      document.body.style.overflow = 'auto';
      previewState.isOpen = false;
      if (currentImageState.element) resetImage();
    }

    function loadCurrentPreview() {
      if (!previewState.isOpen || !previewState.fileList.length) return;
      const currentFile = previewState.fileList[previewState.currentIndex];
      const modalContent = document.getElementById('modalContent');
      const modalFileName = document.getElementById('modalFileName');
      const modalFileInfo = document.getElementById('modalFileInfo');
      const modalCounter = document.getElementById('modalCounter');
      const imageEditToolbar = document.getElementById('imageEditToolbar');

      modalFileName.textContent = currentFile.name;
      modalFileInfo.textContent = `Size: ${fmtBytes(currentFile.size)} • Modified: ${fmtDate(currentFile.mtime)}`;
      modalCounter.textContent = `${previewState.currentIndex + 1}/${previewState.fileList.length}`;

      const hrefParts = currentFile.href.split('/storage/');
      previewState.currentFilePath = hrefParts.length > 1 ? hrefParts[1] : '';

      modalContent.innerHTML = '<div class="text-muted-foreground">Loading preview...</div>';
      imageEditToolbar.style.display = 'none';

      const ext = currentFile.name.toLowerCase().split('.').pop();
      if (ext === 'pdf') {
        modalContent.innerHTML = `<iframe src="${currentFile.href}" class="pdf-iframe" title="PDF Preview: ${currentFile.name}"></iframe>`;
      } else if (['jpg','jpeg','png','gif','webp','bmp'].includes(ext)) {
        const cacheBuster = '?t=' + (currentFile.mtime || Date.now());
        const img = document.createElement('img');
        img.src = currentFile.href + cacheBuster;
        img.alt = currentFile.name;
        img.className = 'preview-image-editable';

        const stage = document.createElement('div');
        stage.id = 'cropStage';
        stage.style.position = 'relative';
        stage.style.display = 'inline-block';

        stage.appendChild(img);
        img.onload = () => { initializeImageEditing(img, currentFile.name); imageEditToolbar.style.display = 'flex'; };
        img.onerror = () => { img.src = currentFile.href; };

        modalContent.innerHTML = '';
        modalContent.appendChild(stage);
      } else {
        modalContent.innerHTML = `
          <div class="text-center">
            <i class="fa-solid fa-file text-4xl text-muted-foreground mb-2"></i>
            <div class="text-muted-foreground">No preview available for this file type</div>
            <a href="${currentFile.href}" target="_blank" class="btn btn-primary mt-2"><i class="fa-solid fa-external-link mr-2"></i>Open File</a>
          </div>
        `;
      }
    }

    function navigatePreview(direction) {
      if (!previewState.isOpen) return;
      const newIndex = previewState.currentIndex + direction;
      if (newIndex >= 0 && newIndex < previewState.fileList.length) {
        previewState.currentIndex = newIndex;
        loadCurrentPreview();
      }
    }

    function fitToScreen(){ if (currentImageState.element){ currentImageState.scale = 1; updateImageTransform(); } }
    function actualSize(){ if (currentImageState.element){ currentImageState.scale = 1; updateImageTransform(); } }

    // ---- Image Editing Functions (RESTORED)
    function initializeImageEditing(imgElement, fileName) {
      currentImageState = {
        element: imgElement,
        originalSrc: imgElement.src,
        rotation: 0,
        scale: 1,
        isCropping: false,
        cropOverlay: null,
        cropStartX: 0,
        cropStartY: 0,
        cropWidth: 0,
        cropHeight: 0,
        currentFileName: fileName,
        displayedImageArea: null
      };
      updateImageTransform();
    }

    function rotateImage(deg){ 
      currentImageState.rotation += deg; 
      updateImageTransform(); 
    }

    function zoomImage(factor){ 
      currentImageState.scale *= factor; 
      updateImageTransform(); 
    }

    function resetImage(){
      currentImageState.rotation = 0;
      currentImageState.scale = 1;
      if (currentImageState.isCropping) toggleCropMode();
      updateImageTransform();
    }

    function updateImageTransform(){
      if (!currentImageState.element) return;
      currentImageState.element.style.transform = `rotate(${currentImageState.rotation}deg) scale(${currentImageState.scale})`;
      currentImageState.element.style.transformOrigin = 'center center';
      const zl = document.getElementById('zoomLevel'); 
      if (zl) zl.textContent = Math.round(currentImageState.scale * 100) + '%';
    }

    function toggleCropMode() {
      currentImageState.isCropping = !currentImageState.isCropping;
      const stage = document.getElementById('cropStage');

      if (currentImageState.isCropping) {
        if (currentImageState.rotation !== 0 || currentImageState.scale !== 1) {
          showNotification('Rotation/zoom reset for accurate cropping', 'info');
        }
        currentImageState.rotation = 0;
        currentImageState.scale = 1;
        updateImageTransform();

        createCropOverlay();
        window.addEventListener('resize', recomputeDisplayedArea, { passive:true });
      } else {
        removeCropOverlay();
        window.removeEventListener('resize', recomputeDisplayedArea);
      }
    }

    function recomputeDisplayedArea(){
      if (!currentImageState.isCropping || !currentImageState.element) return;
      removeCropOverlay(); 
      createCropOverlay();
    }

    function computeDisplayedImageArea() {
      const stage = document.getElementById('cropStage');
      const container = stage || currentImageState.element.parentElement;
      const containerRect = container.getBoundingClientRect();

      const cw = containerRect.width;
      const ch = containerRect.height;
      const img = currentImageState.element;

      const containerAspect = cw / ch;
      const imageAspect = img.naturalWidth / img.naturalHeight;

      let width, height, left, top;
      if (imageAspect > containerAspect) {
        width = cw;
        height = cw / imageAspect;
        left = 0;
        top = (ch - height) / 2;
      } else {
        height = ch;
        width = ch * imageAspect;
        left = (cw - width) / 2;
        top = 0;
      }
      currentImageState.displayedImageArea = { left, top, width, height, containerW: cw, containerH: ch };
      return currentImageState.displayedImageArea;
    }

    function createCropOverlay() {
      const stage = document.getElementById('cropStage');
      const container = stage || currentImageState.element.parentElement;

      const area = computeDisplayedImageArea();

      currentImageState.cropOverlay = document.createElement('div');
      currentImageState.cropOverlay.className = 'crop-overlay';

      // Start with 80% of image area centered
      currentImageState.cropWidth  = area.width * 0.8;
      currentImageState.cropHeight = area.height * 0.8;
      currentImageState.cropStartX = area.left + (area.width - currentImageState.cropWidth)/2;
      currentImageState.cropStartY = area.top  + (area.height - currentImageState.cropHeight)/2;

      updateCropOverlay();

      ['nw','ne','sw','se'].forEach(handle=>{
        const h = document.createElement('div');
        h.className = `crop-handle crop-handle-${handle}`;
        currentImageState.cropOverlay.appendChild(h);
      });

      setupCropInteractions();
      container.appendChild(currentImageState.cropOverlay);
    }

    function removeCropOverlay(){
      if (currentImageState.cropOverlay){ 
        currentImageState.cropOverlay.remove(); 
        currentImageState.cropOverlay = null; 
      }
    }

    function setupCropInteractions(){
      let isDragging=false, isResizing=false, resizeDirection='', startX, startY;
      const container = document.getElementById('cropStage') || currentImageState.element.parentElement;

      currentImageState.cropOverlay.addEventListener('mousedown', startDrag);

      function startDrag(e){
        if (e.target.classList.contains('crop-handle')) {
          isResizing = true;
          resizeDirection = e.target.classList[1].split('-')[2];
        } else {
          isDragging = true;
        }
        startX = e.clientX; 
        startY = e.clientY;
        e.preventDefault();
        document.addEventListener('mousemove', handleMove);
        document.addEventListener('mouseup', stopDrag);
      }

      function handleMove(e){
        if (!isDragging && !isResizing) return;
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;

        const containerRect = container.getBoundingClientRect();
        const maxLeft = 0, maxTop = 0, maxRight = containerRect.width, maxBottom = containerRect.height;

        if (isDragging){
          currentImageState.cropStartX += dx;
          currentImageState.cropStartY += dy;
          currentImageState.cropStartX = Math.max(maxLeft, Math.min(currentImageState.cropStartX, maxRight - currentImageState.cropWidth));
          currentImageState.cropStartY = Math.max(maxTop,  Math.min(currentImageState.cropStartY, maxBottom - currentImageState.cropHeight));
        } else {
          if (resizeDirection.includes('e')){
            currentImageState.cropWidth = Math.max(20, currentImageState.cropWidth + dx);
            currentImageState.cropWidth = Math.min(currentImageState.cropWidth, maxRight - currentImageState.cropStartX);
          }
          if (resizeDirection.includes('w')){
            currentImageState.cropStartX += dx;
            currentImageState.cropWidth = Math.max(20, currentImageState.cropWidth - dx);
            if (currentImageState.cropStartX < maxLeft){
              currentImageState.cropWidth += (currentImageState.cropStartX - maxLeft);
              currentImageState.cropStartX = maxLeft;
            }
          }
          if (resizeDirection.includes('s')){
            currentImageState.cropHeight = Math.max(20, currentImageState.cropHeight + dy);
            currentImageState.cropHeight = Math.min(currentImageState.cropHeight, maxBottom - currentImageState.cropStartY);
          }
          if (resizeDirection.includes('n')){
            currentImageState.cropStartY += dy;
            currentImageState.cropHeight = Math.max(20, currentImageState.cropHeight - dy);
            if (currentImageState.cropStartY < maxTop){
              currentImageState.cropHeight += (currentImageState.cropStartY - maxTop);
              currentImageState.cropStartY = maxTop;
            }
          }
        }

        updateCropOverlay();
        startX = e.clientX; startY = e.clientY;
      }

      function stopDrag(){
        isDragging=false; 
        isResizing=false;
        document.removeEventListener('mousemove', handleMove);
        document.removeEventListener('mouseup', stopDrag);
      }
    }

    function updateCropOverlay(){
      if (!currentImageState.cropOverlay) return;
      currentImageState.cropOverlay.style.left = currentImageState.cropStartX + 'px';
      currentImageState.cropOverlay.style.top = currentImageState.cropStartY + 'px';
      currentImageState.cropOverlay.style.width = currentImageState.cropWidth + 'px';
      currentImageState.cropOverlay.style.height = currentImageState.cropHeight + 'px';
    }

    function getEditedImageBlob(){
      return new Promise((resolve)=>{
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = currentImageState.element;

        const W = img.naturalWidth, H = img.naturalHeight;

        if (currentImageState.isCropping && currentImageState.cropWidth > 0 && currentImageState.cropHeight > 0) {
          const area = currentImageState.displayedImageArea || computeDisplayedImageArea();

          // Map factors from displayed image to natural pixels
          const sx = W / area.width;
          const sy = H / area.height;

          // Crop rectangle in container coords
          const cx = currentImageState.cropStartX;
          const cy = currentImageState.cropStartY;
          const cw = currentImageState.cropWidth;
          const ch = currentImageState.cropHeight;

          // Canvas size equals selected rectangle in natural pixels
          const canvasW = Math.max(1, Math.round(cw * sx));
          const canvasH = Math.max(1, Math.round(ch * sy));
          canvas.width = canvasW; canvas.height = canvasH;

          // Overlap between crop-rect and displayed image (container coords)
          const ix = area.left, iy = area.top, iw = area.width, ih = area.height;
          const ox = Math.max(cx, ix);
          const oy = Math.max(cy, iy);
          const oRight = Math.min(cx + cw, ix + iw);
          const oBottom = Math.min(cy + ch, iy + ih);
          const ow = Math.max(0, oRight - ox);
          const oh = Math.max(0, oBottom - oy);

          ctx.clearRect(0,0,canvasW,canvasH);

          if (ow > 0 && oh > 0) {
            // Source (natural px)
            const srcX = (ox - ix) * sx;
            const srcY = (oy - iy) * sy;
            const srcW = ow * sx;
            const srcH = oh * sy;

            // Destination (canvas px): offset where overlap lands inside the crop canvas
            const destX = (ox - cx) * sx;
            const destY = (oy - cy) * sy;

            ctx.drawImage(
              img,
              Math.round(srcX), Math.round(srcY), Math.round(srcW), Math.round(srcH),
              Math.round(destX), Math.round(destY), Math.round(srcW), Math.round(srcH)
            );
          }
        } else {
          // Non-crop path (apply rotation/zoom as-is)
          canvas.width = W; canvas.height = H;
          ctx.clearRect(0,0,canvas.width,canvas.height);
          ctx.translate(canvas.width/2, canvas.height/2);
          ctx.rotate(currentImageState.rotation * Math.PI/180);
          ctx.scale(currentImageState.scale, currentImageState.scale);
          ctx.translate(-canvas.width/2, -canvas.height/2);
          ctx.drawImage(img, 0, 0, W, H);
        }

        canvas.toBlob(resolve);
      });
    }

    async function saveEditedImage() {
      if (!currentImageState.element || !previewState.currentFilePath) { alert('No image to save or file path not available'); return; }
      try {
        const btn = document.querySelector('button[onclick="saveEditedImage()"]');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...'; btn.disabled = true;

        const blob = await getEditedImageBlob();
        const formData = new FormData();
        formData.append('filePath', previewState.currentFilePath);
        formData.append('image', blob, currentImageState.currentFileName);

        const response = await fetch(API_SAVE_IMAGE, { method:'POST', body: formData });
        const result = await response.json();

        if (result.ok) {
          const cur = previewState.fileList[previewState.currentIndex];
          if (cur) { cur.size = result.fileSize; cur.mtime = result.mtime; }
          const t = result.cacheBuster || Date.now();
          currentImageState.element.src = currentImageState.originalSrc.split('?')[0] + '?t=' + t;
          currentImageState.originalSrc = currentImageState.element.src;

          if (currentImageState.isCropping) toggleCropMode();
          showNotification('Image saved successfully!', 'success');
          await fetchServerList(currentSrvPath);
        } else {
          throw new Error(result.error || 'Failed to save image');
        }
      } catch (e) {
        console.error(e); showNotification('Failed to save image: ' + e.message, 'error');
      } finally {
        const btn = document.querySelector('button[onclick="saveEditedImage()"]');
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save'; btn.disabled = false;
      }
    }

    function downloadEditedImage(){
      getEditedImageBlob().then(blob=>{
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'edited-' + currentImageState.currentFileName;
        a.click(); URL.revokeObjectURL(url);
      });
    }

    async function deleteCurrentFile(){
      if (!previewState.currentFilePath) { alert('No file to delete'); return; }
      const currentFile = previewState.fileList[previewState.currentIndex];
      if (!currentFile) return;
      if (!confirm(`Delete "${currentFile.name}"? This cannot be undone.`)) return;

      try {
        const btn = document.querySelector('button[onclick="deleteCurrentFile()"]');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...'; btn.disabled = true;

        const formData = new FormData(); formData.append('filePath', previewState.currentFilePath);
        const res = await fetch(API_DELETE_FILE, { method:'POST', body: formData });
        const out = await res.json();
        if (out.ok) {
          showNotification('File deleted successfully!', 'success');
          closePreviewModal(); await fetchServerList(currentSrvPath);
        } else { throw new Error(out.error || 'Failed to delete file'); }
      } catch (e){ console.error(e); showNotification('Failed to delete file: ' + e.message, 'error'); }
      finally {
        const btn = document.querySelector('button[onclick="deleteCurrentFile()"]');
        btn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete'; btn.disabled = false;
      }
    }

    function showNotification(msg, type='info'){
      const n = document.createElement('div');
      n.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${type==='success'?'bg-green-500 text-white':type==='error'?'bg-red-500 text-white':'bg-blue-500 text-white'}`;
      n.innerHTML = `<div class="flex items-center"><i class="fa-solid ${type==='success'?'fa-check-circle':type==='error'?'fa-exclamation-circle':'fa-info-circle'} mr-2"></i><span>${msg}</span></div>`;
      document.body.appendChild(n); setTimeout(()=>n.remove(), 4000);
    }

    // ---- Upload UI
    const fileNoEl = document.getElementById('fileNo');
    const folderEl = document.getElementById('folderInput');
    const statusEl = document.getElementById('status');
    const migrateBtn = document.getElementById('migrateBtn');
    const convertPdfsEl = document.getElementById('convertPdfs');
    const pdfConversionInfoEl = document.getElementById('pdfConversionInfo');
    const pdfConversionTextEl = document.getElementById('pdfConversionText');
    const autoConvertBadge = document.getElementById('autoConvertBadge');

    function openProgress(t,s,p=0){
      document.getElementById('progressTitle').textContent=t;
      document.getElementById('progressSub').textContent=s||'';
      document.querySelector('#progressModal .progress-bar').style.width=p+'%';
      document.getElementById('progressText').textContent=p+'%';
      document.getElementById('progressDone').classList.add('hidden');
      const m=document.getElementById('progressModal'); m.classList.remove('hidden'); m.classList.add('flex');
    }

    function setProgress(p){ document.querySelector('#progressModal .progress-bar').style.width=p+'%'; document.getElementById('progressText').textContent=p+'%'; }
    function doneProgress(){ document.getElementById('progressDone').classList.remove('hidden'); setTimeout(()=>{const m=document.getElementById('progressModal'); m.classList.add('hidden'); m.classList.remove('flex');},600); }

    let selectedFiles = [], ready=false, localParentName=null;
    const norm=s=>(s||'').trim();

    function detectSelectedRoot(files){
      const segs=[]; for (const f of files){ const rel=f.webkitRelativePath||f.name; const first=(rel.split('/')[0]||'').trim(); if (first) segs.push(first.toLowerCase()); }
      if (!segs.length) return null; const unique=Array.from(new Set(segs));
      if (unique.length===1 && unique[0]!=='a3' && unique[0]!=='a4') return unique[0];
      return null;
    }

    function detectFileTypes(files) {
      hasPdfFiles = false;
      let pdfCount = 0;
      
      for (const file of files) {
        const name = file.name.toLowerCase();
        if (name.endsWith('.pdf')) {
          hasPdfFiles = true;
          pdfCount++;
        }
      }
      
      // AUTO-ACTIVATE PDF CONVERSION WHEN PDF FILES ARE DETECTED
      if (hasPdfFiles) {
        pdfConversionEnabled = true;
        convertPdfsEl.checked = true;
        autoConvertBadge.classList.remove('hidden');
        pdfConversionTextEl.textContent = `Found ${pdfCount} PDF file(s). PDF conversion automatically activated.`;
        pdfConversionInfoEl.classList.remove('hidden');
        pdfConversionInfoEl.className = 'text-xs text-green-600 mt-1';
      } else {
        pdfConversionEnabled = false;
        convertPdfsEl.checked = false;
        autoConvertBadge.classList.add('hidden');
        pdfConversionInfoEl.classList.add('hidden');
      }
    }

    function validateUploadSection(){
      const typedRaw=norm(fileNoEl.value), typed=typedRaw.toLowerCase();
      const hasFiles = selectedFiles.length>0;
      if (!hasFiles){ statusEl.textContent='No folder selected.'; migrateBtn.disabled=true; ready=false; return; }
      const inferred=detectSelectedRoot(selectedFiles); localParentName=inferred;
      
      // Detect file types (this will auto-activate PDF conversion if PDFs are found)
      detectFileTypes(selectedFiles);
      
      if (!typed){ statusEl.textContent=inferred?`Detected local folder "${inferred}". Enter the File Number to proceed.`:'Folder selected. Enter the File Number to proceed.'; migrateBtn.disabled=true; ready=false; return; }
      if (inferred){
        const match=(typed===inferred);
        if (!match){ statusEl.innerHTML=`Folder appears to be <code>${inferred}</code> but you typed <code>${typedRaw}</code>.`; migrateBtn.disabled=true; ready=false; return; }
        let statusText = `Ready: <b>${typedRaw}</b> will be migrated with all subfolders and files (${selectedFiles.length} items).`;
        if (hasPdfFiles && pdfConversionEnabled) {
          statusText += ` <span class="text-green-600">PDF conversion automatically activated.</span>`;
        }
        statusEl.innerHTML = statusText;
        migrateBtn.disabled=false; ready=true; return;
      } else {
        let statusText = `Ready: <b>${typedRaw}</b> (parent name not detectable by browser). All selected items (${selectedFiles.length}) will be zipped and migrated.`;
        if (hasPdfFiles && pdfConversionEnabled) {
          statusText += ` <span class="text-green-600">PDF conversion automatically activated.</span>`;
        }
        statusEl.innerHTML = statusText;
        migrateBtn.disabled=false; ready=true; return;
      }
    }

    folderEl.addEventListener('change', ()=>{ 
      selectedFiles = Array.from(folderEl.files||[]); 
      validateUploadSection(); 
    });
    
    fileNoEl.addEventListener('input', validateUploadSection);
    
    // Allow manual override, but auto-activation will still happen when PDFs are detected
    convertPdfsEl.addEventListener('change', ()=>{
      pdfConversionEnabled = convertPdfsEl.checked;
      if (pdfConversionEnabled && hasPdfFiles) {
        autoConvertBadge.classList.remove('hidden');
      } else {
        autoConvertBadge.classList.add('hidden');
      }
      validateUploadSection();
    });

    // Process PDF files and place images in parent folder
    async function processPdfFilesForZip(files) {
      const zip = new JSZip();
      pdfConversionResults = { converted: 0, failed: 0, total: 0, failedFiles: [] };
      
      // Find all PDF files
      const pdfFiles = files.filter(file => file.name.toLowerCase().endsWith('.pdf'));
      pdfConversionResults.total = pdfFiles.length;
      
      if (pdfFiles.length === 0) {
        // If no PDFs, just add all files to zip
        for (const file of files) {
          const relativePath = file.webkitRelativePath || file.name;
          zip.file(relativePath, file);
        }
        return { zip, conversionResults: pdfConversionResults };
      }
      
      openProgress('Converting PDFs', `Processing ${pdfFiles.length} PDF file(s)`, 5);
      
      // Track which PDFs we're processing
      const processedPdfs = new Set();
      
      for (let i = 0; i < pdfFiles.length; i++) {
        const pdfFile = pdfFiles[i];
        const relativePath = pdfFile.webkitRelativePath || pdfFile.name;
        const baseName = pdfFile.name.replace(/\.pdf$/i, '');
        const pdfDir = relativePath.includes('/') ? relativePath.substring(0, relativePath.lastIndexOf('/')) : '';
        
        setProgress(5 + (i / pdfFiles.length) * 40);
        processedPdfs.add(relativePath);
        
        try {
          const progressText = `Converting ${pdfFile.name} (${i+1}/${pdfFiles.length})`;
          document.getElementById('progressSub').textContent = progressText;
          
          const images = await convertPdfInBrowser(pdfFile);
          
          // Add each page as JPEG directly in the parent folder
          for (let j = 0; j < images.length; j++) {
            const image = images[j];
            // Create image filename: [original-pdf-name]_page_[number].jpg
            const imageFileName = `${baseName}_page_${j + 1}.jpg`;
            // Use the same directory as the original PDF
            const imagePath = pdfDir ? `${pdfDir}/${imageFileName}` : imageFileName;
            zip.file(imagePath, image.blob);
          }
          
          pdfConversionResults.converted++;
          console.log(`Converted ${pdfFile.name} to ${images.length} pages in parent folder`);
          
        } catch (error) {
          pdfConversionResults.failed++;
          pdfConversionResults.failedFiles.push(pdfFile.name);
          console.error(`Failed to convert ${pdfFile.name}:`, error);
          // Add original PDF to zip if conversion fails
          zip.file(relativePath, pdfFile);
        }
      }
      
      // Add all non-PDF files to zip
      const nonPdfFiles = files.filter(file => !file.name.toLowerCase().endsWith('.pdf'));
      for (const file of nonPdfFiles) {
        const relativePath = file.webkitRelativePath || file.name;
        zip.file(relativePath, file);
      }
      
      // Add any PDF files that weren't processed (shouldn't happen, but just in case)
      const allFilePaths = new Set(files.map(f => f.webkitRelativePath || f.name));
      const unprocessedPdfs = Array.from(allFilePaths).filter(path => 
        path.toLowerCase().endsWith('.pdf') && !processedPdfs.has(path)
      );
      
      for (const pdfPath of unprocessedPdfs) {
        const pdfFile = files.find(f => (f.webkitRelativePath || f.name) === pdfPath);
        if (pdfFile && !zip.file(pdfPath)) {
          zip.file(pdfPath, pdfFile);
          pdfConversionResults.failed++;
        }
      }
      
      return { zip, conversionResults: pdfConversionResults };
    }

    async function filesToZipBlob(files){
      if (pdfConversionEnabled && hasPdfFiles) {
        const { zip, conversionResults } = await processPdfFilesForZip(files);
        return await zip.generateAsync({type:'blob'});
      } else {
        const zip = new JSZip();
        for (const f of files){ 
          const rel = f.webkitRelativePath || f.name; 
          zip.file(rel, f); 
        }
        return await zip.generateAsync({type:'blob'});
      }
    }

    // Abortable fetch with timeout + robust server error reading
    async function fetchWithTimeoutAndText(url, options={}, timeoutMs=180000){
      const ctrl = new AbortController();
      const t = setTimeout(()=>ctrl.abort('timeout'), timeoutMs);
      try{
        const res = await fetch(url, {...options, signal: ctrl.signal});
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch(_) {}
        return {res, text, json};
      } finally {
        clearTimeout(t);
      }
    }

    async function migrateNow(){
      if (!ready) return;
      const folderName=norm(fileNoEl.value);

      openProgress('Preparing Upload', `${folderName} → /public_html/storage`, 0);

      // Build ZIP (with PDF conversion if enabled)
      const zipBlob = await filesToZipBlob(selectedFiles);
      setProgress(50);

      // Preflight against server limits
      if (!serverLimits) { await fetchLimits(); }
      if (serverLimits) {
        const upMax = Number(serverLimits.upload_max_filesize?.bytes || 0);
        const postMax = Number(serverLimits.post_max_size?.bytes || 0);
        const hardMax = Math.min(upMax || Infinity, postMax || Infinity);
        if (hardMax && zipBlob.size > hardMax) {
          document.getElementById('progressModal').classList.add('hidden');
          alert(
            `Upload too large (${fmtBytes(zipBlob.size)}).\n`+
            `Server limits: upload_max_filesize=${serverLimits.upload_max_filesize.raw}, post_max_size=${serverLimits.post_max_size.raw}.\n`+
            `Raise limits or upload a smaller folder.`
          );
          return;
        }
      }

      setProgress(60);

      const form=new FormData(); 
      form.append('folderName', folderName); 
      form.append('zip', zipBlob, `${folderName}.zip`);

      try {
        const {res, text, json} = await fetchWithTimeoutAndText(MIGRATE_ENDPOINT, {method:'POST', body: form}, 180000);

        const ok = res.ok && json && json.ok === true;
        if (ok){
          setProgress(100); doneProgress();
          
          // Show PDF conversion results if applicable
          if (pdfConversionEnabled && hasPdfFiles) {
            let conversionMessage = `Migration complete.`;
            if (pdfConversionResults.converted > 0) {
              conversionMessage += ` Converted ${pdfConversionResults.converted} PDF file(s) to images.`;
            }
            if (pdfConversionResults.failed > 0) {
              conversionMessage += ` ${pdfConversionResults.failed} PDF conversion(s) failed.`;
            }
            statusEl.innerHTML = `<span class="text-green-700">${conversionMessage}</span> Saved to <code>${json.serverPath || '/storage/'+folderName}</code>.`;
          } else {
            statusEl.innerHTML = `<span class="text-green-700">Migration complete.</span> Saved to <code>${json.serverPath || '/storage/'+folderName}</code>.`;
          }
          
          await Promise.all([fetchServerList(currentSrvPath), fetchLogs()]);
          folderEl.value=''; selectedFiles=[]; ready=false; migrateBtn.disabled=true;
          convertPdfsEl.checked = false;
          pdfConversionEnabled = false;
          autoConvertBadge.classList.add('hidden');
        } else {
          document.getElementById('progressModal').classList.add('hidden');
          const snippet = (json && (json.error || json.message))
            || (text ? text.slice(0, 400) : '')
            || `HTTP ${res.status} ${res.statusText}`;
          alert('Migration failed: ' + snippet);
        }
      } catch (err) {
        document.getElementById('progressModal').classList.add('hidden');
        const msg = (err && err.name === 'AbortError') ? 'Request timed out' : (err && err.message) ? err.message : String(err);
        alert('Migration failed: ' + msg);
      }
    }

    document.getElementById('migrateBtn').addEventListener('click', migrateNow);

    // ---- Server browser & logs
    let currentSrvPath='';

    function srvRow(html){ const tr=document.createElement('tr'); tr.className='border-b row'; tr.innerHTML=html; return tr; }
    function fmtBytes(n){ if (!n && n!==0) return '-'; if (n<1024) return n+' B'; if (n<1024*1024) return (n/1024).toFixed(1)+' KB'; return (n/(1024*1024)).toFixed(1)+' MB'; }
    function fmtDate(ts){ if (!ts) return '-'; const d=new Date(ts*1000); return d.toLocaleString(); }
    function clearPreview(){ document.getElementById('previewBox').innerHTML = `<div class="text-sm text-muted-foreground">Select a file to preview.</div>`; }

    function showQuickPreview(href,name){
      const box=document.getElementById('previewBox');
      const ext=(name.split('.').pop()||'').toLowerCase();
      if (['png','jpg','jpeg','gif','webp'].includes(ext)){
        const t='?t='+Date.now();
        box.innerHTML = `<div class="text-center">
          <img src="${href}${t}" alt="${name}" class="max-w-full max-h-32 mx-auto rounded border cursor-pointer" onclick="openPreviewModal('${name}')">
          <div class="text-xs mt-2">Click to open in preview modal</div></div>`;
      } else if (ext==='pdf'){
        box.innerHTML = `<div class="text-center">
          <i class="fa-solid fa-file-pdf text-4xl text-red-500 mb-2"></i>
          <div class="text-sm">${name}</div>
          <button class="btn btn-sm mt-2" onclick="openPreviewModal('${name}')"><i class="fa-solid fa-expand mr-1"></i> Preview PDF</button>
        </div>`;
      } else {
        box.innerHTML = `<div class="text-center">
          <i class="fa-solid fa-file text-4xl text-muted-foreground mb-2"></i>
          <div class="text-sm">${name}</div>
          <div class="text-xs text-muted-foreground mt-1">No preview available</div>
        </div>`;
      }
    }

    async function fetchServerList(subPath=''){
      const url = new URL(API_LIST, window.location.href);
      if (subPath) url.searchParams.set('path', subPath);
      const res = await fetch(url);
      const data = await res.json();
      if (!res.ok || !data.ok) { alert(data.error || ('HTTP '+res.status)); return; }

      currentSrvPath = data.sub || '';
      previewState.currentDirItems = data.items || [];

      document.getElementById('srvPath').textContent = '/storage' + (currentSrvPath ? '/'+currentSrvPath : '');

      const crumbs = data.crumbs || [];
      const crumbsEl = document.getElementById('srvCrumbs');
      if (!crumbs.length) { crumbsEl.textContent='Root'; }
      else {
        crumbsEl.innerHTML = crumbs.map((c,i)=>`<button class="text-blue-700 hover:underline" data-path="${c.path}">${c.name}</button>${i<crumbs.length-1?' / ':''}`).join('');
        crumbsEl.querySelectorAll('button').forEach(b=> b.addEventListener('click', ()=> fetchServerList(b.dataset.path)));
      }

      const tbody=document.getElementById('srvRows'); tbody.innerHTML='';
      if (currentSrvPath){
        const up=currentSrvPath.split('/').slice(0,-1).join('/');
        const trUp=srvRow(`<td class="p-3"><i class="fa-regular fa-folder"></i></td>
          <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${up}">Go Back</button></td>
          <td class="p-3">-</td><td class="p-3">-</td><td class="p-3"></td>`);
        trUp.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList(up));
        tbody.appendChild(trUp);
      }

      const items=data.items||[];
      if (!items.length){
        tbody.appendChild(srvRow(`<td class="p-3 text-muted-foreground text-center" colspan="5">Empty directory</td>`));
      } else {
        for (const item of items){
          if (item.type==='dir'){
            const tr=srvRow(`<td class="p-3"><i class="fa-regular fa-folder text-yellow-500"></i></td>
              <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${(currentSrvPath?currentSrvPath+'/':'')+item.name}">${item.name}</button></td>
              <td class="p-3">-</td><td class="p-3">${fmtDate(item.mtime)}</td><td class="p-3"></td>`);
            tr.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList((currentSrvPath?currentSrvPath+'/':'')+item.name));
            tbody.appendChild(tr);
          } else {
            const href=item.href||'#';
            const isPreviewable = item.name.toLowerCase().endsWith('.pdf') || item.name.toLowerCase().match(/\.(jpg|jpeg|png|gif|webp|bmp)$/i);
            const tr=srvRow(`<td class="p-3"><i class="fa-regular fa-file"></i></td>
              <td class="p-3">${item.name}</td>
              <td class="p-3">${fmtBytes(item.size)}</td>
              <td class="p-3">${fmtDate(item.mtime)}</td>
              <td class="p-3">
                <div class="flex gap-2">
                  ${isPreviewable?`<button class="btn srv-preview text-xs" data-href="${href}" data-name="${item.name}">Preview</button>`:''}
                  <a class="btn text-xs" href="${href}" target="_blank" rel="noopener">Open</a>
                </div>
              </td>`);
            if (isPreviewable){
              tr.querySelector('.srv-preview').addEventListener('click', (e)=>{ showQuickPreview(e.currentTarget.dataset.href, e.currentTarget.dataset.name); });
            }
            tbody.appendChild(tr);
          }
        }
      }
      clearPreview();
    }

    async function fetchLogs(){
      const res=await fetch(API_LOGS);
      const data=await res.json();
      if (!res.ok || !data.ok) { alert(data.error || ('HTTP '+res.status)); return; }
      const logs=data.logs||[];
      const root=document.getElementById('logsContent');
      if (!logs.length){
        root.innerHTML = `<div class="text-center py-8">
          <i class="fa-solid fa-clock-rotate-left text-3xl text-muted-foreground mx-auto mb-4"></i>
          <p class="text-muted-foreground">No migrations yet</p>
          <p class="text-sm text-muted-foreground">After migrating, entries will appear here.</p>
        </div>`;
        return;
      }
      root.innerHTML = `<div class="border rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-muted"><tr class="border-b"><th class="text-left p-3">When</th><th class="text-left p-3">Parent Folder</th><th class="text-left p-3">Server Path</th></tr></thead>
          <tbody>${logs.map(m=>`
            <tr class="border-b">
              <td class="p-3">${m.when||'-'}</td>
              <td class="p-3 font-mono">${m.folder||'-'}</td>
              <td class="p-3 font-mono"><a class="text-blue-700 hover:underline" href="${m.serverPath||'#'}" target="_blank" rel="noopener">${m.serverPath||'-'}</a></td>
            </tr>`).join('')}
          </tbody>
        </table></div>`;
    }

    // Modal events
    document.getElementById('modalClose').addEventListener('click', closePreviewModal);
    document.getElementById('modalPrev').addEventListener('click', ()=> navigatePreview(-1));
    document.getElementById('modalNext').addEventListener('click', ()=> navigatePreview(1));

    document.addEventListener('keydown', (e)=>{
      if (!previewState.isOpen) return;
      if (e.key==='Escape') closePreviewModal();
      else if (e.key==='ArrowLeft') navigatePreview(-1);
      else if (e.key==='ArrowRight') navigatePreview(1);
    });

    const serverPanel=document.getElementById('serverPanel');
    const logsPanel=document.getElementById('logsPanel');
    document.getElementById('tabServer').addEventListener('click', ()=>{ serverPanel.classList.remove('hidden'); logsPanel.classList.add('hidden'); });
    document.getElementById('tabLogs').addEventListener('click', ()=>{ logsPanel.classList.remove('hidden'); serverPanel.classList.add('hidden'); });
    document.getElementById('srvRefresh').addEventListener('click', ()=> fetchServerList(currentSrvPath));

    (async function(){
      await fetchLimits();
      await fetchServerList('');
      await fetchLogs();
    })();
  </script>
</body>
</html>