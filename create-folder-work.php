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
    // Put the "storage" directory next to this script.
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage';
}

function public_href_for(string $absPath): string {
    // Build a web path relative to the document root so links are clickable.
    // Example: /storage/COM-2025-0001/A4/scan.pdf
    $abs = str_replace('\\', '/', $absPath);
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    if ($docRoot && strpos($abs, $docRoot) === 0) {
        return substr($abs, strlen($docRoot)); // starts with /
    }
    // Fallback: relative to this script
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
    $realPath = realpath($path) ?: $path; // allow non-existing
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

function move_contents_up(string $sourceDir, string $targetDir): bool {
    if (!is_dir($sourceDir)) return false;
    
    $items = scandir($sourceDir);
    if (!$items) return false;
    
    $success = true;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $item;
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $item;
        
        if (file_exists($targetPath)) {
            // If target already exists, we can't move - this shouldn't happen in our case
            $success = false;
            continue;
        }
        
        if (!rename($sourcePath, $targetPath)) {
            $success = false;
        }
    }
    
    return $success;
}

/* -------------------------
   API endpoints (GET)
------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
    header('Content-Type: application/json');
    $api = $_GET['api'] ?? '';

    // Ensure storage root exists
    $root = storage_root();
    if (!is_dir($root)) { @mkdir($root, 0775, true); }

    if ($api === 'list') {
        $sub = isset($_GET['path']) ? trim($_GET['path'], "/\\") : '';
        if (strpos($sub, '..') !== false) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad path']); exit; }
        $dir = safe_join_under_storage($sub);
        $items = list_dir_for_api($dir);

        // Breadcrumb
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
        $folderName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $_POST['folderName']); // sanitize
        $upload = $_FILES['zip'];
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Upload failed (php code '.$upload['error'].')']);
            exit;
        }

        // Ensure /storage exists
        $root = storage_root();
        if (!is_dir($root)) {
            if (!mkdir($root, 0775, true)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Could not create storage root']);
                exit;
            }
        }

        // Target: /storage/[FolderName]
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

        // Safety: disallow traversal or absolute paths
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

        // Check if there's a top-level folder with the same name and move contents up
        $potentialInnerFolder = $target . DIRECTORY_SEPARATOR . $folderName;
        if (is_dir($potentialInnerFolder)) {
            // Move contents from inner folder to target folder
            if (!move_contents_up($potentialInnerFolder, $target)) {
                error_log("Failed to move contents from $potentialInnerFolder to $target");
                // Continue anyway - at least we have the files extracted
            } else {
                // Remove the now empty inner folder
                @rmdir($potentialInnerFolder);
            }
        }

        $serverPathWeb = public_href_for($target); // e.g. /storage/COM-2025-0001
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
        
        // Security: Validate file path is within storage directory
        $storageRoot = storage_root();
        $targetPath = safe_join_under_storage($filePath);
        
        // Ensure the target path is actually within storage
        if (strpos($targetPath, $storageRoot) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid file path']);
            exit;
        }
        
        // Check if file exists and is writable
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
        
        // Validate uploaded image
        if ($imageFile['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Image upload failed']);
            exit;
        }
        
        // Check if it's actually an image
        $imageInfo = getimagesize($imageFile['tmp_name']);
        if (!$imageInfo) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid image file']);
            exit;
        }
        
        // Move uploaded file to replace original
        if (move_uploaded_file($imageFile['tmp_name'], $targetPath)) {
            // Clear file stat cache and get fresh file info
            clearstatcache(true, $targetPath);
            
            echo json_encode([
                'ok' => true,
                'message' => 'Image saved successfully',
                'filePath' => $filePath,
                'fileSize' => filesize($targetPath),
                'mtime' => filemtime($targetPath),
                'cacheBuster' => time() // Add cache buster to force refresh
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
        
        // Security: Validate file path is within storage directory
        $storageRoot = storage_root();
        $targetPath = safe_join_under_storage($filePath);
        
        // Ensure the target path is actually within storage
        if (strpos($targetPath, $storageRoot) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid file path']);
            exit;
        }
        
        // Check if file exists
        if (!file_exists($targetPath)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'File not found']);
            exit;
        }
        
        // Prevent deletion of directories via this endpoint
        if (is_dir($targetPath)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Cannot delete directories']);
            exit;
        }
        
        // Delete the file
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
  <!-- FIXED: Correct JSZip URL -->
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
    .pill{font-size:11px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:.15rem .5rem}
    .btn { border:1px solid #e2e8f0; background:#fff; padding:.5rem .75rem; border-radius:.5rem; font-size:.9rem; }
    .btn:hover { background:#f8fafc; }
    .btn-primary{ background:#4f46e5; border-color:#4f46e5; color:#fff; }
    .btn-primary:hover{ background:#4338ca; }
    .btn-success{ background:#10b981; border-color:#10b981; color:#fff; }
    .btn-success:hover{ background:#059669; }
    .btn-danger{ background:#dc2626; border-color:#dc2626; color:#fff; }
    .btn-danger:hover{ background:#b91c1c; }
    .preview-box { min-height: 200px; }
    
    /* Preview Modal Styles */
    .preview-modal {
      background: rgba(0, 0, 0, 0.9);
    }
    .preview-content {
      max-width: 95vw;
      max-height: 95vh;
    }
    .preview-toolbar {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
    }
    .preview-nav-btn {
      background: rgba(255, 255, 255, 0.9);
      border: none;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.25rem;
      transition: all 0.2s ease;
    }
    .preview-nav-btn:hover {
      background: rgba(255, 255, 255, 1);
      transform: scale(1.1);
    }
    .preview-image {
      max-width: 100%;
      max-height: 80vh;
      object-fit: contain;
    }
    .pdf-iframe {
      width: 100%;
      height: 80vh;
      border: none;
    }
    
    /* Image editing styles */
    .edit-toolbar {
      background: rgba(255,255,255,0.95);
      border-radius: 8px;
      padding: 8px;
      z-index: 10;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }
    .edit-toolbar button {
      margin: 2px;
      padding: 6px 8px;
    }
    .preview-container {
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .preview-image-editable {
      max-width: 100%;
      max-height: 80vh;
      transition: transform 0.3s ease;
      object-fit: contain;
    }
    .crop-overlay {
      position: absolute;
      border: 2px dashed #4f46e5;
      background: rgba(79, 70, 229, 0.1);
      cursor: move;
    }
    .crop-handle {
      position: absolute;
      width: 12px;
      height: 12px;
      background: #4f46e5;
      border: 2px solid white;
      border-radius: 50%;
    }
    .crop-handle-nw { top: -6px; left: -6px; cursor: nw-resize; }
    .crop-handle-ne { top: -6px; right: -6px; cursor: ne-resize; }
    .crop-handle-sw { bottom: -6px; left: -6px; cursor: sw-resize; }
    .crop-handle-se { bottom: -6px; right: -6px; cursor: se-resize; }
    .zoom-controls {
      position: absolute;
      bottom: 20px;
      right: 20px;
      background: rgba(255,255,255,0.9);
      border-radius: 8px;
      padding: 8px;
      z-index: 10;
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
      <!-- Left: Upload + Migrate (reduced width) -->
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

          <div id="status" class="text-sm text-muted-foreground">No folder selected.</div>

          <button id="migrateBtn" class="btn btn-primary w-full disabled:opacity-50" disabled>
            <i class="fa-solid fa-paper-plane mr-2"></i>Migrate to Server
          </button>
        </div>
      </div>

      <!-- Right: Server Browser & Logs (increased width) -->
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

        <!-- Server Browser -->
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
          
          <!-- Small Preview Box -->
          <div class="mt-4 border rounded-lg p-3">
            <div class="font-medium mb-2">Quick Preview</div>
            <div id="previewBox" class="preview-box text-sm text-muted-foreground flex items-center justify-center">
              Select a file to preview.
            </div>
          </div>
        </div>

        <!-- Logs -->
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
        <!-- Header -->
        <div class="preview-toolbar flex items-center justify-between p-4 border-b rounded-t-lg">
          <div class="flex items-center space-x-4">
            <button id="modalClose" class="text-gray-500 hover:text-gray-700">
              <i class="fa-solid fa-times text-xl"></i>
            </button>
            <h3 id="modalFileName" class="text-lg font-semibold"></h3>
            <div id="modalFileInfo" class="text-sm text-muted-foreground"></div>
          </div>
          <div class="edit-toolbar flex items-center space-x-2" id="imageEditToolbar" style="display: none;">
            <button class="btn btn-sm" title="Rotate Left" onclick="rotateImage(-90)">
              <i class="fa-solid fa-rotate-left"></i>
            </button>
            <button class="btn btn-sm" title="Rotate Right" onclick="rotateImage(90)">
              <i class="fa-solid fa-rotate-right"></i>
            </button>
            <button class="btn btn-sm" title="Crop" onclick="toggleCropMode()">
              <i class="fa-solid fa-crop"></i>
            </button>
            <button class="btn btn-sm" title="Reset" onclick="resetImage()">
              <i class="fa-solid fa-refresh"></i>
            </button>
            <button class="btn btn-sm btn-success" title="Save Edited" onclick="saveEditedImage()">
              <i class="fa-solid fa-floppy-disk"></i> Save
            </button>
            <button class="btn btn-sm" title="Download Edited" onclick="downloadEditedImage()">
              <i class="fa-solid fa-download"></i> Download
            </button>
            <button class="btn btn-sm btn-danger" title="Delete File" onclick="deleteCurrentFile()">
              <i class="fa-solid fa-trash"></i> Delete
            </button>
            <div class="zoom-controls-static flex items-center space-x-1">
              <button class="btn btn-sm" title="Zoom Out" onclick="zoomImage(0.8)">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <span class="text-xs px-2" id="zoomLevel">100%</span>
              <button class="btn btn-sm" title="Zoom In" onclick="zoomImage(1.2)">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Content Area -->
        <div class="preview-container flex-1 p-4 relative">
          <!-- Navigation Buttons -->
          <button id="modalPrev" class="preview-nav-btn absolute left-4 top-1/2 transform -translate-y-1/2">
            <i class="fa-solid fa-chevron-left"></i>
          </button>
          <button id="modalNext" class="preview-nav-btn absolute right-4 top-1/2 transform -translate-y-1/2">
            <i class="fa-solid fa-chevron-right"></i>
          </button>

          <!-- Content will be inserted here -->
          <div id="modalContent" class="w-full h-full flex items-center justify-center">
            <div class="text-muted-foreground">Loading preview...</div>
          </div>
        </div>

        <!-- Footer -->
        <div class="preview-toolbar flex items-center justify-between p-4 border-t rounded-b-lg">
          <div class="text-sm text-muted-foreground">
            <span id="modalCounter">0/0</span> files
          </div>
          <div class="flex space-x-2">
            <button class="btn btn-sm" onclick="fitToScreen()">
              <i class="fa-solid fa-expand mr-1"></i> Fit
            </button>
            <button class="btn btn-sm" onclick="actualSize()">
              <i class="fa-solid fa-arrows-alt mr-1"></i> Actual Size
            </button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /container -->

  <script>
    // ---- Config
    const MIGRATE_ENDPOINT = window.location.pathname;
    const API_LIST = window.location.pathname + '?api=list';
    const API_LOGS = window.location.pathname + '?api=logs';
    const API_SAVE_IMAGE = window.location.pathname + '?api=save-image';
    const API_DELETE_FILE = window.location.pathname + '?api=delete-file';

    // ---- Preview Modal State
    let previewState = {
      currentIndex: 0,
      fileList: [],
      isOpen: false,
      currentDirItems: [],
      currentFilePath: ''
    };

    // ---- Image Editing State
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
      currentFileName: ''
    };

    // ---- Preview Modal Functions
    function openPreviewModal(fileName) {
      const currentDir = previewState.currentDirItems || [];
      
      // Filter to only include previewable files (images and PDFs)
      const previewableFiles = currentDir.filter(item => 
        item.type === 'file' && (
          item.name.toLowerCase().endsWith('.pdf') ||
          item.name.toLowerCase().match(/\.(jpg|jpeg|png|gif|webp|bmp)$/i)
        )
      );

      if (previewableFiles.length === 0) {
        alert('No previewable files (PDF or images) in this directory');
        return;
      }

      // Find current file index
      const currentIndex = previewableFiles.findIndex(file => file.name === fileName);
      if (currentIndex === -1) {
        alert('File not found in previewable files');
        return;
      }

      previewState.fileList = previewableFiles;
      previewState.currentIndex = currentIndex;
      previewState.isOpen = true;

      // Show modal
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
      
      // Reset image editing state
      if (currentImageState.element) {
        resetImage();
      }
    }

    function loadCurrentPreview() {
      if (!previewState.isOpen || previewState.fileList.length === 0) return;

      const currentFile = previewState.fileList[previewState.currentIndex];
      const modalContent = document.getElementById('modalContent');
      const modalFileName = document.getElementById('modalFileName');
      const modalFileInfo = document.getElementById('modalFileInfo');
      const modalCounter = document.getElementById('modalCounter');
      const imageEditToolbar = document.getElementById('imageEditToolbar');

      // Update file info
      modalFileName.textContent = currentFile.name;
      modalFileInfo.textContent = `Size: ${fmtBytes(currentFile.size)} • Modified: ${fmtDate(currentFile.mtime)}`;
      modalCounter.textContent = `${previewState.currentIndex + 1}/${previewState.fileList.length}`;

      // Store current file path (relative to storage)
      const hrefParts = currentFile.href.split('/storage/');
      previewState.currentFilePath = hrefParts.length > 1 ? hrefParts[1] : '';

      // Clear previous content
      modalContent.innerHTML = '<div class="text-muted-foreground">Loading preview...</div>';
      imageEditToolbar.style.display = 'none';

      const ext = currentFile.name.toLowerCase().split('.').pop();
      
      if (['pdf'].includes(ext)) {
        // PDF preview
        modalContent.innerHTML = `
          <iframe src="${currentFile.href}" class="pdf-iframe" 
                  title="PDF Preview: ${currentFile.name}"></iframe>
        `;
      } else if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(ext)) {
        // Image preview - add cache buster to force refresh
        const cacheBuster = '?t=' + (currentFile.mtime || Date.now());
        const img = document.createElement('img');
        img.src = currentFile.href + cacheBuster;
        img.alt = currentFile.name;
        img.className = 'preview-image-editable';
        img.onload = () => {
          initializeImageEditing(img, currentFile.name);
          imageEditToolbar.style.display = 'flex';
        };
        img.onerror = () => {
          // If cached version fails, try without cache buster
          img.src = currentFile.href;
        };
        modalContent.innerHTML = '';
        modalContent.appendChild(img);
      } else {
        modalContent.innerHTML = `
          <div class="text-center">
            <i class="fa-solid fa-file text-4xl text-muted-foreground mb-2"></i>
            <div class="text-muted-foreground">No preview available for this file type</div>
            <a href="${currentFile.href}" target="_blank" class="btn btn-primary mt-2">
              <i class="fa-solid fa-external-link mr-2"></i>Open File
            </a>
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

    function fitToScreen() {
      if (currentImageState.element) {
        currentImageState.scale = 1;
        updateImageTransform();
      }
    }

    function actualSize() {
      if (currentImageState.element) {
        currentImageState.scale = 1;
        updateImageTransform();
      }
    }

    // ---- Image Editing Functions
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
        currentFileName: fileName
      };

      updateImageTransform();
    }

    function rotateImage(degrees) {
      currentImageState.rotation += degrees;
      updateImageTransform();
    }

    function zoomImage(factor) {
      currentImageState.scale *= factor;
      updateImageTransform();
    }

    function resetZoom() {
      currentImageState.scale = 1;
      updateImageTransform();
    }

    function resetImage() {
      currentImageState.rotation = 0;
      currentImageState.scale = 1;
      if (currentImageState.isCropping) {
        toggleCropMode();
      }
      updateImageTransform();
    }

    function updateImageTransform() {
      if (currentImageState.element) {
        currentImageState.element.style.transform = 
          `rotate(${currentImageState.rotation}deg) scale(${currentImageState.scale})`;
        currentImageState.element.style.transformOrigin = 'center center';
        document.getElementById('zoomLevel').textContent = Math.round(currentImageState.scale * 100) + '%';
      }
    }

    function toggleCropMode() {
      currentImageState.isCropping = !currentImageState.isCropping;
      
      if (currentImageState.isCropping) {
        createCropOverlay();
      } else {
        removeCropOverlay();
      }
    }

    function createCropOverlay() {
      const container = currentImageState.element.parentElement;
      const rect = currentImageState.element.getBoundingClientRect();
      
      currentImageState.cropOverlay = document.createElement('div');
      currentImageState.cropOverlay.className = 'crop-overlay';
      
      // Set initial crop area (80% of image)
      currentImageState.cropWidth = rect.width * 0.8;
      currentImageState.cropHeight = rect.height * 0.8;
      currentImageState.cropStartX = (rect.width - currentImageState.cropWidth) / 2;
      currentImageState.cropStartY = (rect.height - currentImageState.cropHeight) / 2;
      
      currentImageState.cropOverlay.style.left = currentImageState.cropStartX + 'px';
      currentImageState.cropOverlay.style.top = currentImageState.cropStartY + 'px';
      currentImageState.cropOverlay.style.width = currentImageState.cropWidth + 'px';
      currentImageState.cropOverlay.style.height = currentImageState.cropHeight + 'px';
      
      // Add resize handles
      const handles = ['nw', 'ne', 'sw', 'se'];
      handles.forEach(handle => {
        const handleEl = document.createElement('div');
        handleEl.className = `crop-handle crop-handle-${handle}`;
        currentImageState.cropOverlay.appendChild(handleEl);
      });
      
      setupCropInteractions();
      container.appendChild(currentImageState.cropOverlay);
    }

    function removeCropOverlay() {
      if (currentImageState.cropOverlay) {
        currentImageState.cropOverlay.remove();
        currentImageState.cropOverlay = null;
      }
    }

    function setupCropInteractions() {
      let isDragging = false;
      let isResizing = false;
      let resizeDirection = '';
      let startX, startY;
      
      currentImageState.cropOverlay.addEventListener('mousedown', startDrag);
      
      function startDrag(e) {
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
      
      function handleMove(e) {
        if (!isDragging && !isResizing) return;
        
        const dx = e.clientX - startX;
        const dy = e.clientY - startY;
        
        if (isDragging) {
          currentImageState.cropStartX = Math.max(0, currentImageState.cropStartX + dx);
          currentImageState.cropStartY = Math.max(0, currentImageState.cropStartY + dy);
        } else if (isResizing) {
          if (resizeDirection.includes('e')) {
            currentImageState.cropWidth = Math.max(50, currentImageState.cropWidth + dx);
          }
          if (resizeDirection.includes('w')) {
            currentImageState.cropStartX = Math.max(0, currentImageState.cropStartX + dx);
            currentImageState.cropWidth = Math.max(50, currentImageState.cropWidth - dx);
          }
          if (resizeDirection.includes('s')) {
            currentImageState.cropHeight = Math.max(50, currentImageState.cropHeight + dy);
          }
          if (resizeDirection.includes('n')) {
            currentImageState.cropStartY = Math.max(0, currentImageState.cropStartY + dy);
            currentImageState.cropHeight = Math.max(50, currentImageState.cropHeight - dy);
          }
        }
        
        updateCropOverlay();
        startX = e.clientX;
        startY = e.clientY;
      }
      
      function stopDrag() {
        isDragging = false;
        isResizing = false;
        document.removeEventListener('mousemove', handleMove);
        document.removeEventListener('mouseup', stopDrag);
      }
    }
    
    function updateCropOverlay() {
      if (currentImageState.cropOverlay) {
        currentImageState.cropOverlay.style.left = currentImageState.cropStartX + 'px';
        currentImageState.cropOverlay.style.top = currentImageState.cropStartY + 'px';
        currentImageState.cropOverlay.style.width = currentImageState.cropWidth + 'px';
        currentImageState.cropOverlay.style.height = currentImageState.cropHeight + 'px';
      }
    }

    function getEditedImageBlob() {
      return new Promise((resolve) => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const img = currentImageState.element;
        
        // Set canvas dimensions based on whether we're cropping
        if (currentImageState.isCropping && currentImageState.cropWidth > 0 && currentImageState.cropHeight > 0) {
          // For crop: use the crop dimensions
          const scaleX = img.naturalWidth / img.width;
          const scaleY = img.naturalHeight / img.height;
          
          canvas.width = currentImageState.cropWidth * scaleX;
          canvas.height = currentImageState.cropHeight * scaleY;
          
          // Calculate the source crop coordinates in natural image dimensions
          const srcX = currentImageState.cropStartX * scaleX;
          const srcY = currentImageState.cropStartY * scaleY;
          const srcWidth = currentImageState.cropWidth * scaleX;
          const srcHeight = currentImageState.cropHeight * scaleY;
          
          // Apply transformations to the context before drawing
          ctx.translate(canvas.width / 2, canvas.height / 2);
          ctx.rotate(currentImageState.rotation * Math.PI / 180);
          ctx.scale(currentImageState.scale, currentImageState.scale);
          ctx.translate(-canvas.width / 2, -canvas.height / 2);
          
          // Draw the cropped portion
          ctx.drawImage(
            img,
            srcX, srcY, srcWidth, srcHeight,  // source rectangle (crop area)
            0, 0, canvas.width, canvas.height // destination rectangle (full canvas)
          );
        } else {
          // For non-crop: use full image dimensions
          canvas.width = img.naturalWidth;
          canvas.height = img.naturalHeight;
          
          // Apply transformations
          ctx.translate(canvas.width / 2, canvas.height / 2);
          ctx.rotate(currentImageState.rotation * Math.PI / 180);
          ctx.scale(currentImageState.scale, currentImageState.scale);
          ctx.translate(-canvas.width / 2, -canvas.height / 2);
          
          // Draw the full image
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        }
        
        // Convert to blob
        canvas.toBlob(resolve);
      });
    }

    async function saveEditedImage() {
      if (!currentImageState.element || !previewState.currentFilePath) {
        alert('No image to save or file path not available');
        return;
      }

      try {
        // Show saving indicator
        const saveBtn = document.querySelector('button[onclick="saveEditedImage()"]');
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
        saveBtn.disabled = true;

        // Get the edited image as blob
        const blob = await getEditedImageBlob();
        
        // Create form data
        const formData = new FormData();
        formData.append('filePath', previewState.currentFilePath);
        formData.append('image', blob, currentImageState.currentFileName);

        // Send to server
        const response = await fetch(API_SAVE_IMAGE, {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.ok) {
          // Update the current file in the file list with new metadata
          const currentFile = previewState.fileList[previewState.currentIndex];
          if (currentFile) {
            currentFile.size = result.fileSize;
            currentFile.mtime = result.mtime;
          }
          
          // Update the image source with cache buster to force refresh
          const timestamp = result.cacheBuster || Date.now();
          currentImageState.element.src = currentImageState.originalSrc.split('?')[0] + '?t=' + timestamp;
          currentImageState.originalSrc = currentImageState.element.src;
          
          // Show success message
          showNotification('Image saved successfully!', 'success');
          
          // Refresh the file list to show updated file size and modification time
          await fetchServerList(currentSrvPath);
        } else {
          throw new Error(result.error || 'Failed to save image');
        }

      } catch (error) {
        console.error('Save error:', error);
        showNotification('Failed to save image: ' + error.message, 'error');
      } finally {
        // Restore button state
        const saveBtn = document.querySelector('button[onclick="saveEditedImage()"]');
        saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save';
        saveBtn.disabled = false;
      }
    }

    function downloadEditedImage() {
      getEditedImageBlob().then(blob => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'edited-' + currentImageState.currentFileName;
        a.click();
        URL.revokeObjectURL(url);
      });
    }

    // ---- Delete File Functionality
    async function deleteCurrentFile() {
      if (!previewState.currentFilePath) {
        alert('No file to delete');
        return;
      }

      const currentFile = previewState.fileList[previewState.currentIndex];
      if (!currentFile) return;

      if (!confirm(`Are you sure you want to delete "${currentFile.name}"? This action cannot be undone.`)) {
        return;
      }

      try {
        // Show deleting indicator
        const deleteBtn = document.querySelector('button[onclick="deleteCurrentFile()"]');
        const originalText = deleteBtn.innerHTML;
        deleteBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';
        deleteBtn.disabled = true;

        const formData = new FormData();
        formData.append('filePath', previewState.currentFilePath);

        const response = await fetch(API_DELETE_FILE, {
          method: 'POST',
          body: formData
        });

        const result = await response.json();

        if (result.ok) {
          showNotification('File deleted successfully!', 'success');
          
          // Close the modal and refresh the file list
          closePreviewModal();
          await fetchServerList(currentSrvPath);
        } else {
          throw new Error(result.error || 'Failed to delete file');
        }

      } catch (error) {
        console.error('Delete error:', error);
        showNotification('Failed to delete file: ' + error.message, 'error');
      } finally {
        // Restore button state
        const deleteBtn = document.querySelector('button[onclick="deleteCurrentFile()"]');
        deleteBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Delete';
        deleteBtn.disabled = false;
      }
    }

    function showNotification(message, type = 'info') {
      // Create notification element
      const notification = document.createElement('div');
      notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
        type === 'success' ? 'bg-green-500 text-white' : 
        type === 'error' ? 'bg-red-500 text-white' : 
        'bg-blue-500 text-white'
      }`;
      notification.innerHTML = `
        <div class="flex items-center">
          <i class="fa-solid ${
            type === 'success' ? 'fa-check-circle' : 
            type === 'error' ? 'fa-exclamation-circle' : 
            'fa-info-circle'
          } mr-2"></i>
          <span>${message}</span>
        </div>
      `;
      
      document.body.appendChild(notification);
      
      // Remove after 3 seconds
      setTimeout(() => {
        notification.remove();
      }, 3000);
    }

    // ---- DOM Elements
    const fileNoEl   = document.getElementById('fileNo');
    const folderEl   = document.getElementById('folderInput');
    const statusEl   = document.getElementById('status');
    const migrateBtn = document.getElementById('migrateBtn');

    // Progress modal helpers
    function openProgress(title, sub, pct=0){
      document.getElementById('progressTitle').textContent = title;
      document.getElementById('progressSub').textContent = sub || '';
      document.querySelector('#progressModal .progress-bar').style.width = pct+'%';
      document.getElementById('progressText').textContent = pct+'%';
      document.getElementById('progressDone').classList.add('hidden');
      const m = document.getElementById('progressModal');
      m.classList.remove('hidden'); m.classList.add('flex');
    }
    function setProgress(pct){
      document.querySelector('#progressModal .progress-bar').style.width = pct+'%';
      document.getElementById('progressText').textContent = pct+'%';
    }
    function doneProgress(){
      document.getElementById('progressDone').classList.remove('hidden');
      setTimeout(()=> {
        const m = document.getElementById('progressModal');
        m.classList.add('hidden'); m.classList.remove('flex');
      }, 600);
    }

    // ---- Upload state
    let selectedFiles = [];
    let ready = false;
    let localParentName = null;

    const norm = s => (s || '').trim();

    function detectSelectedRoot(files){
      const segs = [];
      for (const f of files) {
        const rel = f.webkitRelativePath || f.name;
        const first = (rel.split('/')[0] || '').trim();
        if (first) segs.push(first.toLowerCase());
      }
      if (!segs.length) return null;
      const unique = Array.from(new Set(segs));
      if (unique.length === 1 && unique[0] !== 'a3' && unique[0] !== 'a4') {
        return unique[0];
      }
      return null;
    }

    function validateUploadSection(){
      const typedRaw = norm(fileNoEl.value);
      const typed = typedRaw.toLowerCase();
      const hasFiles = selectedFiles.length > 0;

      if (!hasFiles){
        statusEl.textContent = 'No folder selected.';
        migrateBtn.disabled = true; ready = false; return;
      }

      const inferred = detectSelectedRoot(selectedFiles);
      localParentName = inferred;

      if (!typed){
        statusEl.textContent = inferred
          ? `Detected local folder "${inferred}". Enter the File Number to proceed.`
          : 'Folder selected. Enter the File Number to proceed.';
        migrateBtn.disabled = true; ready = false; return;
      }

      if (inferred){
        const match = (typed === inferred);
        if (!match){
          statusEl.innerHTML = `Folder appears to be <code>${inferred}</code> but you typed <code>${typedRaw}</code>.`;
          migrateBtn.disabled = true; ready = false; return;
        }
        statusEl.innerHTML = `Ready: <b>${typedRaw}</b> will be migrated with all subfolders and files (${selectedFiles.length} items).`;
        migrateBtn.disabled = false; ready = true; return;
      } else {
        statusEl.innerHTML = `Ready: <b>${typedRaw}</b> (parent name not detectable by browser). `
          + `All selected items (${selectedFiles.length}) will be zipped and migrated.`;
        migrateBtn.disabled = false; ready = true; return;
      }
    }

    folderEl.addEventListener('change', () => {
      selectedFiles = Array.from(folderEl.files || []);
      validateUploadSection();
    });
    fileNoEl.addEventListener('input', validateUploadSection);

    // ---- Client zip (preserves subfolders)
    async function filesToZipBlob(files) {
      const zip = new JSZip();
      for (const f of files) {
        const rel = f.webkitRelativePath || f.name;
        zip.file(rel, f);
      }
      return await zip.generateAsync({ type:'blob' });
    }

    async function migrateNow(){
      if (!ready) return;

      const folderName = norm(fileNoEl.value);
      openProgress('Preparing Upload', `${folderName} → /public_html/storage`, 10);

      const zipBlob = await filesToZipBlob(selectedFiles);
      setProgress(55);

      const form = new FormData();
      form.append('folderName', folderName);
      form.append('zip', zipBlob, `${folderName}.zip`);

      const res = await fetch(MIGRATE_ENDPOINT, { method:'POST', body: form });
      const data = await res.json().catch(()=>({}));
      if (res.ok && data.ok) {
        setProgress(100);
        doneProgress();
        await Promise.all([ fetchServerList(currentSrvPath), fetchLogs() ]);
        statusEl.innerHTML = `<span class="text-green-700">Migration complete.</span> Saved to <code>${data.serverPath || '/storage/'+folderName}</code>.`;
        folderEl.value = '';
        selectedFiles = [];
        ready = false;
        migrateBtn.disabled = true;
      } else {
        document.getElementById('progressModal').classList.add('hidden');
        const msg = (data && (data.error || data.message)) || `HTTP ${res.status}`;
        alert('Migration failed: ' + msg);
      }
    }

    migrateBtn.addEventListener('click', migrateNow);

    // ----------------------------
    // Server browser + logs (GET)
    // ----------------------------
    let currentSrvPath = '';

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
    function clearPreview(){
      document.getElementById('previewBox').innerHTML =
        `<div class="text-sm text-muted-foreground">Select a file to preview.</div>`;
    }

    function showQuickPreview(href, name) {
      const box = document.getElementById('previewBox');
      const ext = (name.split('.').pop() || '').toLowerCase();
      
      if (['png','jpg','jpeg','gif','webp'].includes(ext)) {
        // Add cache buster to force refresh
        const cacheBuster = '?t=' + Date.now();
        box.innerHTML = `
          <div class="text-center">
            <img src="${href}${cacheBuster}" alt="${name}" class="max-w-full max-h-32 mx-auto rounded border cursor-pointer" 
                 onclick="openPreviewModal('${name}')">
            <div class="text-xs mt-2">Click to open in preview modal</div>
          </div>
        `;
      } else if (ext === 'pdf') {
        box.innerHTML = `
          <div class="text-center">
            <i class="fa-solid fa-file-pdf text-4xl text-red-500 mb-2"></i>
            <div class="text-sm">${name}</div>
            <button class="btn btn-sm mt-2" onclick="openPreviewModal('${name}')">
              <i class="fa-solid fa-expand mr-1"></i> Preview PDF
            </button>
          </div>
        `;
      } else {
        box.innerHTML = `
          <div class="text-center">
            <i class="fa-solid fa-file text-4xl text-muted-foreground mb-2"></i>
            <div class="text-sm">${name}</div>
            <div class="text-xs text-muted-foreground mt-1">No preview available</div>
          </div>
        `;
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
      if (!crumbs.length) {
        crumbsEl.textContent = 'Root';
      } else {
        crumbsEl.innerHTML = crumbs.map((c,i)=>(
          `<button class="text-blue-700 hover:underline" data-path="${c.path}">${c.name}</button>${i<crumbs.length-1?' / ':''}`
        )).join('');
        crumbsEl.querySelectorAll('button').forEach(b=>{
          b.addEventListener('click', ()=> fetchServerList(b.dataset.path));
        });
      }

      const tbody = document.getElementById('srvRows');
      tbody.innerHTML = '';

      if (currentSrvPath){
        const up = currentSrvPath.split('/').slice(0,-1).join('/');
        const trUp = srvRow(`
          <td class="p-3"><i class="fa-regular fa-folder"></i></td>
          <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${up}">Go Back</button></td>
          <td class="p-3">-</td>
          <td class="p-3">-</td>
          <td class="p-3"></td>
        `);
        trUp.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList(up));
        tbody.appendChild(trUp);
      }

      const items = data.items || [];
      if (!items.length){
        tbody.appendChild(srvRow(`<td class="p-3 text-muted-foreground text-center" colspan="5">Empty directory</td>`));
      } else {
        for (const item of items){
          if (item.type === 'dir'){
            const tr = srvRow(`
              <td class="p-3"><i class="fa-regular fa-folder text-yellow-500"></i></td>
              <td class="p-3"><button class="srv-nav text-blue-700 hover:underline" data-path="${(currentSrvPath?currentSrvPath+'/':'')+item.name}">${item.name}</button></td>
              <td class="p-3">-</td>
              <td class="p-3">${fmtDate(item.mtime)}</td>
              <td class="p-3"></td>
            `);
            tr.querySelector('.srv-nav').addEventListener('click', ()=> fetchServerList((currentSrvPath?currentSrvPath+'/':'')+item.name));
            tbody.appendChild(tr);
          } else {
            const href = item.href || '#';
            const isPreviewable = item.name.toLowerCase().endsWith('.pdf') || 
                                 item.name.toLowerCase().match(/\.(jpg|jpeg|png|gif|webp|bmp)$/i);
            const tr = srvRow(`
              <td class="p-3"><i class="fa-regular fa-file"></i></td>
              <td class="p-3">${item.name}</td>
              <td class="p-3">${fmtBytes(item.size)}</td>
              <td class="p-3">${fmtDate(item.mtime)}</td>
              <td class="p-3">
                <div class="flex gap-2">
                  ${isPreviewable ? `<button class="btn srv-preview text-xs" data-href="${href}" data-name="${item.name}">Preview</button>` : ''}
                  <a class="btn text-xs" href="${href}" target="_blank" rel="noopener">Open</a>
                </div>
              </td>
            `);
            if (isPreviewable) {
              tr.querySelector('.srv-preview').addEventListener('click', (e)=> {
                showQuickPreview(e.currentTarget.dataset.href, e.currentTarget.dataset.name);
              });
            }
            tbody.appendChild(tr);
          }
        }
      }
      clearPreview();
    }

    async function fetchLogs(){
      const res = await fetch(API_LOGS);
      const data = await res.json();
      if (!res.ok || !data.ok) { alert(data.error || ('HTTP '+res.status)); return; }
      const logs = data.logs || [];
      const root = document.getElementById('logsContent');
      if (!logs.length) {
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
              ${logs.map(m=>`
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

    // Modal event listeners
    document.getElementById('modalClose').addEventListener('click', closePreviewModal);
    document.getElementById('modalPrev').addEventListener('click', () => navigatePreview(-1));
    document.getElementById('modalNext').addEventListener('click', () => navigatePreview(1));

    // Keyboard navigation
    document.addEventListener('keydown', (e) => {
      if (!previewState.isOpen) return;
      
      switch(e.key) {
        case 'Escape':
          closePreviewModal();
          break;
        case 'ArrowLeft':
          navigatePreview(-1);
          break;
        case 'ArrowRight':
          navigatePreview(1);
          break;
      }
    });

    // Tabs
    const serverPanel = document.getElementById('serverPanel');
    const logsPanel   = document.getElementById('logsPanel');
    document.getElementById('tabServer').addEventListener('click', ()=>{ serverPanel.classList.remove('hidden'); logsPanel.classList.add('hidden'); });
    document.getElementById('tabLogs').addEventListener('click', ()=>{ logsPanel.classList.remove('hidden'); serverPanel.classList.add('hidden'); });
    document.getElementById('srvRefresh').addEventListener('click', ()=> fetchServerList(currentSrvPath));

    // Boot
    (async function(){
      await fetchServerList('');
      await fetchLogs();
    })();
  </script>
</body>
</html>