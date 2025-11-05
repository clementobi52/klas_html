<?php

// Helper function to format file size (used by upload handler)
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Scans the upload directory and returns a structured array of batches and documents.
 * @param string $dir The base upload directory to scan.
 * @return array
 */
function getUploadData($dir) {
    $batches = [];
    $baseDir = rtrim($dir, '/') . '/';

    if (!file_exists($baseDir) || !is_dir($baseDir)) {
        return []; // Return empty if storage doesn't exist
    }

    $fileNumbers = array_diff(scandir($baseDir), ['.', '..']);

    foreach ($fileNumbers as $fileNumber) {
        $batchPath = $baseDir . $fileNumber;
        if (is_dir($batchPath)) {
            $batch = [
                'fileNumber' => $fileNumber,
                'documents' => [],
                // Get folder modification time as approx date
                'date' => date("m/d/Y", filemtime($batchPath)) 
            ];
            
            $files = array_diff(scandir($batchPath), ['.', '..']);
            foreach ($files as $fileName) {
                $filePath = $batchPath . '/' . $fileName;
                if (is_file($filePath)) {
                    // Create a web-accessible path
                    // This assumes /storage/ is directly under DOCUMENT_ROOT
                    $webPath = '/storage/' . rawurlencode($fileNumber) . '/' . rawurlencode($fileName);

                    $batch['documents'][] = [
                        'fileName' => $fileName,
                        'fileSize' => filesize($filePath),
                        'date' => date("m/d/Y", filemtime($filePath)),
                        'serverPath' => $webPath,
                        'serverFileName' => $fileName
                    ];
                }
            }
            // Only add batches that contain documents
            if (!empty($batch['documents'])) {
                 $batches[] = $batch;
            }
        }
    }
    // Sort batches by date, newest first
    usort($batches, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    return $batches;
}

// --- ENDPOINT ROUTER ---

$UPLOAD_BASE_DIR = $_SERVER['DOCUMENT_ROOT'] . "/storage/";

// Debug endpoint to check directory structure
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'debug') {
    header('Content-Type: application/json');
    
    $debugInfo = [
        'document_root' => $_SERVER['DOCUMENT_ROOT'],
        'upload_base_dir' => $UPLOAD_BASE_DIR,
        'base_dir_exists' => file_exists($UPLOAD_BASE_DIR),
        'base_dir_is_dir' => is_dir($UPLOAD_BASE_DIR),
        'base_dir_realpath' => realpath($UPLOAD_BASE_DIR),
        'storage_contents' => []
    ];
    
    if (file_exists($UPLOAD_BASE_DIR) && is_dir($UPLOAD_BASE_DIR)) {
        $items = scandir($UPLOAD_BASE_DIR);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                $itemPath = $UPLOAD_BASE_DIR . $item;
                $debugInfo['storage_contents'][$item] = [
                    'is_dir' => is_dir($itemPath),
                    'realpath' => realpath($itemPath),
                    'files' => []
                ];
                
                if (is_dir($itemPath)) {
                    $subItems = scandir($itemPath);
                    foreach ($subItems as $subItem) {
                        if ($subItem !== '.' && $subItem !== '..') {
                            $debugInfo['storage_contents'][$item]['files'][] = $subItem;
                        }
                    }
                }
            }
        }
    }
    
    echo json_encode($debugInfo);
    exit;
}

// 1. Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check for file upload
    if (isset($_FILES['file']) || !empty($_FILES)) {
        // Set headers first to prevent any output before JSON
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');

        // Enable error reporting for debugging
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        // Log the request for debugging
        error_log("File upload request received");
        error_log("POST data: " . print_r($_POST, true));
        error_log("FILES data: " . print_r($_FILES, true));

        // Configuration
        $MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB max file size
        $ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp', 'pdf'];

        // Get POST data
        $fileNumber = $_POST['fileNumber'] ?? '';
        $fileName = $_POST['fileName'] ?? '';
        
        // Check for file data in different possible locations
        $fileData = null;
        if (isset($_FILES['file'])) {
            $fileData = $_FILES['file'];
        } elseif (!empty($_FILES)) {
            // Get the first file if 'file' key doesn't exist
            $fileData = reset($_FILES);
        }

        // Validate input
        if (empty($fileNumber)) {
            error_log("File number is empty");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File number is required']);
            exit;
        }

        if (!$fileData) {
            error_log("No file data found in request");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded - file data not found']);
            exit;
        }

        // Check for upload errors
        if ($fileData['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'Upload failed with error code: ' . $fileData['error'];
            switch ($fileData['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errorMessage = 'File too large (server limit)';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errorMessage = 'File partially uploaded';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $errorMessage = 'No file was uploaded';
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $errorMessage = 'Missing temporary folder';
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $errorMessage = 'Failed to write file to disk';
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $errorMessage = 'A PHP extension stopped the file upload';
                    break;
            }
            error_log("Upload error: " . $errorMessage);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $errorMessage]);
            exit;
        }

        // Validate file size
        if ($fileData['size'] > $MAX_FILE_SIZE) {
            error_log("File too large: " . $fileData['size'] . " bytes");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File too large: ' . formatFileSize($fileData['size']) . ' (max: ' . formatFileSize($MAX_FILE_SIZE) . ')']);
            exit;
        }

        // Validate file exists and has size
        if ($fileData['size'] === 0) {
            error_log("File has zero size");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Uploaded file is empty']);
            exit;
        }

        // Validate file type
        $fileExtension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $ALLOWED_TYPES)) {
            error_log("File type not allowed: " . $fileExtension);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File type not allowed: ' . $fileExtension . ' (allowed: ' . implode(', ', $ALLOWED_TYPES) . ')']);
            exit;
        }

        // Sanitize file number for folder name - MODIFIED to allow spaces
        $sanitizedFileNumber = preg_replace('/[^a-zA-Z0-9_ -]/', '', $fileNumber);
        if (empty($sanitizedFileNumber)) {
            error_log("File number became empty after sanitization: " . $fileNumber);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file number format']);
            exit;
        }

        // Create directory if it doesn't exist
        $uploadDir = $UPLOAD_BASE_DIR . $sanitizedFileNumber . '/';
        if (!file_exists($uploadDir)) {
            error_log("Creating directory: " . $uploadDir);
            if (!mkdir($uploadDir, 0755, true)) {
                $error = error_get_last();
                error_log("Failed to create directory: " . $uploadDir . " - " . ($error ? $error['message'] : 'Unknown error'));
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to create directory: ' . $uploadDir]);
                exit;
            }
        }

        // Check if directory is writable
        if (!is_writable($uploadDir)) {
            error_log("Directory not writable: " . $uploadDir);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Upload directory is not writable']);
            exit;
        }

        // Generate unique filename to prevent overwrites
        $uniqueName = uniqid() . '_' . basename($fileData['name']);
        $uploadPath = $uploadDir . $uniqueName;

        // Create web-accessible path
        $webPath = '/storage/' . rawurlencode($sanitizedFileNumber) . '/' . rawurlencode($uniqueName);

        error_log("Attempting to move uploaded file from: " . $fileData['tmp_name'] . " to: " . $uploadPath);
        error_log("Temp file exists: " . (file_exists($fileData['tmp_name']) ? 'yes' : 'no'));
        error_log("Temp file size: " . (file_exists($fileData['tmp_name']) ? filesize($fileData['tmp_name']) : '0'));

        // Move uploaded file
        if (move_uploaded_file($fileData['tmp_name'], $uploadPath)) {
            error_log("File moved successfully: " . $uploadPath);
            error_log("Final file size: " . filesize($uploadPath));
            
            echo json_encode([
                'success' => true,
                'message' => 'File uploaded successfully',
                'path' => $uploadPath,                    // Full server path
                'fileName' => $uniqueName,                // Unique server filename
                'originalName' => $fileData['name'],      // Original filename
                'webPath' => $webPath,                    // Web-accessible path for deletion
                'folder' => $sanitizedFileNumber,
                'size' => $fileData['size']
            ]);
        } else {
            $lastError = error_get_last();
            error_log("Failed to move uploaded file. Last error: " . ($lastError ? $lastError['message'] : 'Unknown error'));
            error_log("Temp file: " . $fileData['tmp_name']);
            error_log("Target path: " . $uploadPath);
            error_log("Is upload: " . (is_uploaded_file($fileData['tmp_name']) ? 'yes' : 'no'));
            
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'message' => 'Failed to move uploaded file',
                'error' => $lastError ? $lastError['message'] : 'Unknown error',
                'temp_file' => $fileData['tmp_name'],
                'target_path' => $uploadPath
            ]);
        }
        exit;
    }
    
    // Check for delete action
  // Check for delete action
$input = json_decode(file_get_contents('php://input'), true);
if (isset($input['action']) && $input['action'] === 'delete') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, DELETE');
    header('Access-Control-Allow-Headers: Content-Type');
    
    $filePath = $input['path'] ?? '';
    if (empty($filePath)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File path is required.']);
        exit;
    }

    // Debug logging
    error_log("=== DELETE REQUEST START ===");
    error_log("Raw file path received: " . $filePath);
    error_log("UPLOAD_BASE_DIR: " . $UPLOAD_BASE_DIR);
    error_log("DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT']);

    // Remove leading slash from serverPath if present
    $relativePath = ltrim($filePath, '/');
    
    // Check if path starts with storage/
    if (strpos($relativePath, 'storage/') !== 0) {
        error_log("Invalid path format: " . $relativePath);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file path format. Path must start with /storage/']);
        exit;
    }

    // Construct the full filesystem path
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $relativePath;
    
    error_log("Constructed full path: " . $fullPath);
    error_log("File exists (fullPath): " . (file_exists($fullPath) ? 'YES' : 'NO'));

    // Security check: Ensure the file is inside the UPLOAD_BASE_DIR
    $realBasePath = realpath($UPLOAD_BASE_DIR);
    $realFullPath = realpath($fullPath);
    
    error_log("realBasePath: " . ($realBasePath ?: 'NOT FOUND/INACCESSIBLE'));
    error_log("realFullPath: " . ($realFullPath ?: 'NOT FOUND/INACCESSIBLE'));

    if (!$realBasePath) {
        error_log("Base path not found or inaccessible: " . $UPLOAD_BASE_DIR);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server configuration error: Upload directory not accessible.']);
        exit;
    }

    // If the file doesn't exist at the expected path, let's search for it with better matching
    if (!$realFullPath || !file_exists($realFullPath)) {
        error_log("File not found at expected path. Searching in upload directory...");
        
        // Extract filename from the path and decode URL encoding
        $filename = basename($relativePath);
        $decodedFilename = rawurldecode($filename); // Decode URL-encoded filenames
        
        error_log("Original filename: " . $filename);
        error_log("Decoded filename: " . $decodedFilename);
        
        // Search for the file in the upload directory with multiple matching strategies
        $foundPath = null;
        $fileNumbers = array_diff(scandir($UPLOAD_BASE_DIR), ['.', '..']);
        
        foreach ($fileNumbers as $fileNumber) {
            $batchPath = $UPLOAD_BASE_DIR . $fileNumber;
            if (is_dir($batchPath)) {
                error_log("Searching in folder: " . $fileNumber);
                $files = array_diff(scandir($batchPath), ['.', '..']);
                foreach ($files as $fileInDir) {
                    // Try multiple matching strategies
                    $fileMatches = false;
                    
                    // Strategy 1: Exact match with original filename
                    if ($fileInDir === $filename) {
                        $fileMatches = true;
                        error_log("  Exact match found: " . $fileInDir);
                    }
                    // Strategy 2: Exact match with decoded filename
                    elseif ($fileInDir === $decodedFilename) {
                        $fileMatches = true;
                        error_log("  Decoded match found: " . $fileInDir);
                    }
                    // Strategy 3: Partial match (for unique IDs)
                    elseif (strpos($fileInDir, $decodedFilename) !== false || strpos($fileInDir, $filename) !== false) {
                        $fileMatches = true;
                        error_log("  Partial match found: " . $fileInDir);
                    }
                    // Strategy 4: Case-insensitive match for PNG files
                    elseif (strtolower($fileInDir) === strtolower($filename) || strtolower($fileInDir) === strtolower($decodedFilename)) {
                        $fileMatches = true;
                        error_log("  Case-insensitive match found: " . $fileInDir);
                    }
                    
                    if ($fileMatches) {
                        $foundPath = $batchPath . '/' . $fileInDir;
                        error_log("MATCH FOUND: " . $foundPath);
                        break 2;
                    }
                }
            }
        }
        
        if ($foundPath && file_exists($foundPath)) {
            $realFullPath = realpath($foundPath);
            error_log("Using found path: " . $realFullPath);
        } else {
            error_log("File not found in any directory. Tried: " . $filename . " and " . $decodedFilename);
            
            // List all files for debugging
            error_log("Available files in storage:");
            foreach ($fileNumbers as $fileNumber) {
                $batchPath = $UPLOAD_BASE_DIR . $fileNumber;
                if (is_dir($batchPath)) {
                    $files = array_diff(scandir($batchPath), ['.', '..']);
                    foreach ($files as $fileInDir) {
                        error_log("  " . $fileNumber . "/" . $fileInDir);
                    }
                }
            }
            
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'File not found: ' . $filename]);
            exit;
        }
    }

    // Check if the file is within the base directory
    if ($realFullPath && $realBasePath && strpos($realFullPath, $realBasePath) === 0) {
        if (file_exists($realFullPath)) {
            error_log("Attempting to delete: " . $realFullPath);
            error_log("File size: " . filesize($realFullPath));
            error_log("File permissions: " . substr(sprintf('%o', fileperms($realFullPath)), -4));
            error_log("File type: " . pathinfo($realFullPath, PATHINFO_EXTENSION));
            
            // Check if file is writable
            if (!is_writable($realFullPath)) {
                error_log("File is not writable. Attempting to change permissions...");
                if (chmod($realFullPath, 0644)) {
                    error_log("Permissions changed to 0644");
                } else {
                    error_log("Failed to change permissions");
                }
            }
            
            // Attempt to delete the file
            if (unlink($realFullPath)) {
                error_log("File deleted successfully: " . $realFullPath);
                
                // Optional: Remove empty directories
                $dir = dirname($realFullPath);
                if (is_dir($dir) && count(scandir($dir)) === 2) { // Only . and .. remain
                    if (rmdir($dir)) {
                        error_log("Removed empty directory: " . $dir);
                    } else {
                        error_log("Failed to remove directory (may not be empty): " . $dir);
                    }
                }
                
                echo json_encode(['success' => true, 'message' => 'File deleted successfully.']);
            } else {
                $lastError = error_get_last();
                error_log("Failed to delete file. Last error: " . ($lastError ? $lastError['message'] : 'Unknown error'));
                error_log("File writable: " . (is_writable($realFullPath) ? 'YES' : 'NO'));
                error_log("Directory writable: " . (is_writable(dirname($realFullPath)) ? 'YES' : 'NO'));
                
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete file. Check file permissions.']);
            }
        } else {
            error_log("File disappeared before deletion: " . $realFullPath);
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'File not found: ' . basename($filePath)]);
        }
    } else {
        error_log("=== SECURITY VIOLATION DETECTED ===");
        error_log("RealFullPath: " . $realFullPath);
        error_log("RealBasePath: " . $realBasePath);
        error_log("Base path length: " . strlen($realBasePath));
        error_log("Full path starts with base: " . (strpos($realFullPath, $realBasePath) === 0 ? 'YES' : 'NO'));
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied: Invalid file path.']);
    }
    
    error_log("=== DELETE REQUEST END ===");
    exit;
}
    
    // Fallback for unknown POST
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid POST request']);
    exit;
}

// 2. Handle GET: Fetch Upload Log (AJAX)
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'log') {
    header('Content-Type: application/json');
    try {
        $batchesData = getUploadData($UPLOAD_BASE_DIR);
        echo json_encode(['success' => true, 'data' => $batchesData]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// 3. Handle GET: Render Page (Default)
// If it's not a POST or ?action=log, just continue and render the HTML below.

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Document Upload - KLAS</title>
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Lucide Icons -->
  <script src="https://unpkg.com/lucide@latest"></script>
  <!-- PDF.js for PDF processing -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <!-- Custom Styles -->
  <style>
    /* Base styles */
    :root {
      --primary: #3b82f6;
      --primary-foreground: #ffffff;
      --muted: #f3f4f6;
      --muted-foreground: #6b7280;
      --border: #e5e7eb;
      --ring: #3b82f6;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      color: #0f172a;
      background-color: #f8fafc;
    }

    /* Card styles */
    .card {
      background-color: white;
      border-radius: 0.5rem;
      border: 1px solid var(--border);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }

    /* Button styles */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 0.375rem;
      font-weight: 500;
      font-size: 0.875rem;
      line-height: 1.25rem;
      padding: 0.5rem 1rem;
      transition: all 0.2s;
      cursor: pointer;
    }

    .btn-primary {
      background-color: var(--primary);
      color: var(--primary-foreground);
    }

    .btn-primary:hover {
      background-color: #2563eb;
    }

    .btn-outline {
      background-color: transparent;
      border: 1px solid var(--border);
    }

    .btn-outline:hover {
      background-color: var(--muted);
    }

    .btn-ghost {
      background-color: transparent;
    }

    .btn-ghost:hover {
      background-color: var(--muted);
    }

    .btn-destructive {
      background-color: #ef4444;
      color: white;
    }

    .btn-destructive:hover {
      background-color: #dc2626;
    }

    .btn-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.75rem;
    }

    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* Badge styles */
    .badge {
      display: inline-flex;
      align-items: center;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 500;
      line-height: 1;
      padding: 0.25rem 0.5rem;
      white-space: nowrap;
    }

    .badge-outline {
      background-color: transparent;
      border: 1px solid var(--border);
    }

    .badge-secondary {
      background-color: #f3f4f6;
      color: #1f2937;
    }

    /* Input styles */
    .input {
      display: block;
      width: 100%;
      border-radius: 0.375rem;
      border: 1px solid var(--border);
      padding: 0.5rem 0.75rem;
      font-size: 0.875rem;
      line-height: 1.25rem;
      background-color: white;
    }

    .input:focus {
      outline: none;
      border-color: var(--ring);
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.3);
    }

    /* Progress bar */
    .progress {
      position: relative;
      width: 100%;
      height: 0.5rem;
      overflow: hidden;
      background-color: var(--muted);
      border-radius: 9999px;
    }

    .progress-bar {
      position: absolute;
      height: 100%;
      background-color: var(--primary);
      transition: width 0.3s ease;
    }

    /* Dialog styles */
    .dialog-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 50;
    }

    .dialog-content {
      background-color: white;
      border-radius: 0.5rem;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      width: 100%;
      max-width: 32rem;
      max-height: 90vh;
      overflow-y: auto;
    }

    .dialog-preview {
      max-width: 900px;
      height: 800px;
      display: flex;
      flex-direction: column;
    }

    /* Tab styles */
    .tabs {
      display: flex;
      flex-direction: column;
      width: 100%;
    }

    .tabs-list {
      display: flex;
      border-bottom: 1px solid var(--border);
    }

    .tab {
      padding: 0.5rem 1rem;
      font-size: 0.875rem;
      font-weight: 500;
      border-bottom: 2px solid transparent;
      cursor: pointer;
    }

    .tab[aria-selected="true"] {
      border-bottom-color: var(--primary);
      color: var(--primary);
    }

    .tab-content {
      display: none;
      padding-top: 1.5rem;
    }

    .tab-content[aria-hidden="false"] {
      display: block;
    }

    /* Radio group */
    .radio-group {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 0.5rem;
    }

    .radio-item {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .radio-item input[type="radio"] {
      width: 1rem;
      height: 1rem;
    }

    /* Custom animations */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .animate-fade-in {
      animation: fadeIn 0.3s ease-in-out;
    }

    /* Hide scrollbar for Chrome, Safari and Opera */
    .no-scrollbar::-webkit-scrollbar {
      display: none;
    }

    /* Hide scrollbar for IE, Edge and Firefox */
    .no-scrollbar {
      -ms-overflow-style: none;  /* IE and Edge */
      scrollbar-width: none;  /* Firefox */
    }

    /* PDF Conversion Styles */
    .pdf-conversion-info {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 0.375rem;
      padding: 0.75rem;
      margin-top: 0.5rem;
    }

    .pdf-conversion-badge {
      background: #10b981;
      color: white;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 500;
      margin-left: 8px;
    }

    /* Image preview styles */
    .document-image {
      max-height: 160px;
      object-fit: contain;
      background: white;
    }

    .loading-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 2px solid #f3f4f6;
      border-radius: 50%;
      border-top-color: #3b82f6;
      animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* Selected files preview styles */
    .file-preview-thumbnail {
      width: 64px;
      height: 64px;
      border-radius: 0.375rem;
      border: 1px solid #e5e7eb;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f9fafb;
      flex-shrink: 0;
    }

    .file-preview-thumbnail img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }

    .file-preview-overlay {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0);
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      transition: all 0.2s ease;
    }

    .file-preview-overlay:hover {
      background: rgba(0, 0, 0, 0.4);
      opacity: 1;
    }

    .file-preview-container {
      position: relative;
      display: inline-block;
    }

    .file-preview-container:hover .file-preview-thumbnail {
      transform: scale(1.05);
    }

    .file-preview-thumbnail {
      transition: transform 0.2s ease;
    }

    /* Full preview modal styles */
    .full-preview-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.75);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 100;
      padding: 1rem;
    }

    .full-preview-content {
      background: white;
      border-radius: 0.5rem;
      max-width: 90vw;
      max-height: 90vh;
      width: auto;
      display: flex;
      flex-direction: column;
    }

    .full-preview-image {
      max-width: 100%;
      max-height: 70vh;
      object-fit: contain;
    }

    /* Toast notification */
    .toast-notification {
      position: fixed;
      top: 1rem;
      right: 1rem;
      background: #10b981;
      color: white;
      padding: 0.75rem 1rem;
      border-radius: 0.375rem;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
      z-index: 1000;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }
  </style>
</head>
<body class="bg-gray-50">
  <div class="container mx-auto py-6 space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col space-y-2">
      <h1 class="text-2xl font-bold tracking-tight">Document Upload</h1>
      <p class="text-muted-foreground">Upload scanned documents to their digital folders</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
      <!-- Today's Uploads -->
      <div class="card">
        <div class="p-4 pb-2">
          <h3 class="text-sm font-medium">Today's Uploads</h3>
        </div>
        <div class="p-4 pt-0">
          <div class="text-2xl font-bold" id="uploads-count">0</div>
          <p class="text-xs text-muted-foreground mt-1">Batches uploaded today</p>
        </div>
      </div>

      <!-- Pending Page Typing -->
      <div class="card">
        <div class="p-4 pb-2">
          <h3 class="text-sm font-medium">Pending Page Typing</h3>
        </div>
        <div class="p-4 pt-0">
          <div class="text-2xl font-bold" id="pending-count">0</div>
          <p class="text-xs text-muted-foreground mt-1">Documents waiting for page typing</p>
        </div>
      </div>

      <!-- Next Steps -->
      <div class="card">
        <div class="p-4 pb-2">
          <h3 class="text-sm font-medium">Next Steps</h3>
        </div>
        <div class="p-4 pt-0">
          <div class="text-2xl font-bold flex items-center">
            Page Typing
            <span class="badge ml-2 bg-purple-500 text-white">Stage 3</span>
          </div>
          <p class="text-xs text-muted-foreground mt-1">After uploading, proceed to page typing</p>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <div class="tabs-list grid w-full md:w-auto grid-cols-2">
        <button class="tab" role="tab" aria-selected="true" data-tab="upload">Upload Documents</button>
        <button class="tab" role="tab" aria-selected="false" data-tab="uploaded-files">Uploaded Documents</button>
      </div>

      <!-- Upload Tab -->
      <div class="tab-content mt-6" role="tabpanel" aria-hidden="false" data-tab-content="upload">
        <div class="card">
          <div class="p-6 border-b">
            <div class="flex flex-col md:flex-row md:items-center justify-between">
              <div>
                <h2 class="text-lg font-semibold">Document Upload</h2>
                <p class="text-sm text-muted-foreground">Upload scanned documents to their digital folders</p>
              </div>
              <div class="mt-2 md:mt-0 selected-file-badge hidden">
                <span class="badge bg-blue-500 text-white px-3 py-1 flex items-center">
                  <i data-lucide="folder-open" class="h-4 w-4 mr-2"></i>
                  <span id="selected-file-number">No file selected</span>
                </span>
              </div>
            </div>
          </div>
          <div class="p-6">
            <div class="space-y-6">
              <div class="flex justify-between items-center">
                <label class="text-sm font-medium">Select Indexed File</label>
                <button class="btn btn-outline btn-sm gap-1" id="select-file-btn">
                  <i data-lucide="folder" class="h-4 w-4"></i>
                  <span id="change-file-text">Select File</span>
                </button>
              </div>

              <!-- Upload area -->
              <div class="border rounded-md p-4">
                <h3 class="text-sm font-medium mb-4">Upload Scanned Documents</h3>

                <!-- PDF Conversion Option -->
                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md">
                  <div class="flex items-center space-x-2 mb-2">
                    <input type="checkbox" id="convertPdfs" class="rounded border-gray-300" checked>
                    <label for="convertPdfs" class="text-sm font-medium">
                      Convert PDF files to images (client-side)
                      <span id="autoConvertBadge" class="pdf-conversion-badge">Auto-activated</span>
                    </label>
                  </div>
                  <p class="text-xs text-gray-600">
                    PDF files will be automatically converted to JPG images in your browser before upload.
                    Images are placed in the same folder as the original PDF.
                  </p>
                  <div id="pdfConversionInfo" class="text-xs text-blue-600 mt-1">
                    <span id="pdfConversionText">PDF conversion ready</span>
                  </div>
                </div>

                <!-- Idle state -->
                <div id="upload-idle" class="rounded-md border-2 border-dashed p-8 text-center">
                  <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                    <i data-lucide="file-up" class="h-6 w-6"></i>
                  </div>
                  <h3 class="mb-2 text-lg font-medium">Drag and drop scanned documents here</h3>
                  <p class="mb-4 text-sm text-muted-foreground">or click to browse files on your computer</p>
                  <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.gif,.bmp,.tiff,.webp" class="hidden" id="file-upload">
                  <button class="btn btn-primary gap-2" id="browse-files-btn" disabled>
                    <i data-lucide="upload" class="h-4 w-4"></i>
                    Browse Files
                  </button>
                  <p class="mt-2 text-sm text-red-500" id="select-file-warning">Please select an indexed file first</p>
                  <p class="text-xs text-gray-500 mt-2">
                    Supported formats: PDF, JPG, PNG, GIF, BMP, TIFF, WebP
                  </p>
                </div>

                <!-- Selected files list -->
                <div id="selected-files-container" class="rounded-md border divide-y mt-4 hidden">
                  <div class="p-3 bg-muted/50 flex justify-between items-center">
                    <span class="font-medium"><span id="selected-files-count">0</span> files selected</span>
                    <button class="btn btn-ghost btn-sm" id="clear-all-btn">Clear All</button>
                  </div>
                  <div id="selected-files-list">
                    <!-- Files will be added here dynamically -->
                  </div>
                </div>

                <!-- Uploading state -->
                <div id="upload-progress" class="space-y-2 mt-4 hidden">
                  <div class="flex justify-between text-sm">
                    <span>Uploading <span id="uploading-count">0</span> files...</span>
                    <span id="upload-percentage">0%</span>
                  </div>
                  <div class="progress">
                    <div class="progress-bar" id="progress-bar" style="width: 0%"></div>
                  </div>
                </div>

                <!-- Complete state -->
                <div id="upload-complete" class="mt-4 p-4 bg-green-50 border border-green-100 rounded-md hidden">
                  <div class="flex items-center gap-2 text-green-700">
                    <i data-lucide="check-circle" class="h-5 w-5"></i>
                    <span class="font-medium">Upload Complete!</span>
                  </div>
                  <p class="text-sm text-green-700 mt-1">
                    Files have been successfully uploaded and organized by paper size.
                  </p>
                </div>

                <!-- Error state -->
                <div id="upload-error" class="mt-4 p-4 bg-red-50 border border-red-100 rounded-md hidden">
                  <div class="flex items-center gap-2 text-red-700">
                    <i data-lucide="alert-circle" class="h-5 w-5"></i>
                    <span class="font-medium">Upload Failed!</span>
                  </div>
                  <p class="text-sm text-red-700 mt-1" id="upload-error-message">
                    Some files failed to upload. Check console for details.
                  </p>
                </div>
              </div>

              <!-- Action buttons -->
              <div class="flex flex-col md:flex-row gap-4 justify-center">
                <!-- Start upload button (idle state) -->
                <button class="btn btn-primary gap-2 hidden" id="start-upload-btn">
                  <i data-lucide="upload" class="h-4 w-4"></i>
                  Start Upload
                </button>

                <!-- Cancel button (uploading state) -->
                <button class="btn btn-destructive gap-2 hidden" id="cancel-upload-btn">
                  <i data-lucide="alert-circle" class="h-4 w-4"></i>
                  Cancel
                </button>

                <!-- Complete state buttons -->
                <button class="btn btn-outline gap-2 hidden" id="upload-more-btn">
                  <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                  Upload More
                </button>
                <button class="btn btn-primary gap-2 hidden" id="view-uploaded-btn">
                  <i data-lucide="check-circle" class="h-4 w-4"></i>
                  View Uploaded Files
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Uploaded Files Tab -->
      <div class="tab-content mt-6" role="tabpanel" aria-hidden="true" data-tab-content="uploaded-files">
        <div class="card">
          <div class="p-6 border-b">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div>
                <h2 class="text-lg font-semibold">Uploaded Documents</h2>
                <p class="text-sm text-muted-foreground">Documents uploaded and ready for page typing</p>
              </div>
              <div class="flex flex-col md:flex-row items-end md:items-center gap-2">
                <div class="flex items-center gap-2">
                  <label for="paper-size-filter" class="text-sm font-medium whitespace-nowrap">Filter by Size:</label>
                  <select id="paper-size-filter" class="input w-[120px]">
                    <option value="All">All Sizes</option>
                    <option value="A4">A4</option>
                    <option value="A5">A5</option>
                    <option value="A3">A3</option>
                    <option value="Letter">Letter</option>
                    <option value="Legal">Legal</option>
                    <option value="Custom">Custom</option>
                  </select>
                </div>
                <div class="relative w-full md:w-64">
                  <i data-lucide="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"></i>
                  <input type="search" placeholder="Search files..." class="input w-full pl-8" id="file-search">
                </div>
                <button class="btn btn-outline btn-sm whitespace-nowrap" id="toggle-view-btn">
                  Folder View
                </button>
              </div>
            </div>
          </div>
          <div class="p-6">
            <!-- Empty state -->
            <div id="no-documents" class="rounded-md border p-8 text-center">
              <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                <i data-lucide="file-text" class="h-6 w-6"></i>
              </div>
              <h3 class="mb-2 text-lg font-medium">No uploaded documents yet</h3>
              <p class="mb-4 text-sm text-muted-foreground">Upload documents to see them listed here</p>
              <button class="btn btn-primary gap-2" id="go-to-upload-btn">
                <i data-lucide="upload" class="h-4 w-4"></i>
                Go to Upload
              </button>
            </div>

            <!-- List view -->
            <div id="list-view" class="rounded-md border divide-y hidden">
              <!-- Batches will be added here dynamically -->
            </div>

            <!-- Folder view -->
            <div id="folder-view" class="space-y-6 hidden">
              <!-- Folders will be added here dynamically -->
            </div>
          </div>
          <!-- REMOVED: Upload More button from batch actions -->
          <div id="batch-actions" class="flex justify-end border-t pt-4 p-6 hidden">
            <button class="btn btn-primary gap-2" id="proceed-to-typing-btn">
              Proceed to Page Typing
              <i data-lucide="arrow-right" class="h-4 w-4"></i>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- PDF Conversion Progress Modal -->
    <div id="pdf-conversion-modal" class="dialog-backdrop hidden" aria-hidden="true">
      <div class="dialog-content animate-fade-in">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold">PDF Conversion</h2>
        </div>
        <div class="py-4 px-6 space-y-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 bg-blue-100 rounded-full">
              <i data-lucide="file-up" class="h-5 w-5 text-blue-600"></i>
            </div>
            <div>
              <h4 class="font-medium">Converting PDF to Images</h4>
              <p class="text-sm text-gray-600" id="pdf-conversion-current-file">Processing PDF files...</p>
            </div>
          </div>
          <div class="space-y-2">
            <div class="flex justify-between text-sm">
              <span>Conversion Progress</span>
              <span id="pdf-conversion-progress-percent">0%</span>
            </div>
            <div class="progress">
              <div class="progress-bar" id="pdf-conversion-progress-bar" style="width: 0%"></div>
            </div>
          </div>
          <div class="p-3 bg-gray-100 rounded text-sm">
            <p class="font-medium mb-1">Processing:</p>
            <ul class="space-y-1 text-gray-600">
              <li>• Loading PDF files</li>
              <li>• Converting pages to JPEG images</li>
              <li>• Generating image files</li>
              <li>• Preparing for upload</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Document Preview Dialog -->
    <div id="preview-dialog" class="dialog-backdrop hidden" aria-hidden="true">
      <div class="dialog-content dialog-preview animate-fade-in">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold" id="preview-title">Document Preview</h2>
        </div>

        <div class="flex-1 overflow-auto border rounded-md relative p-4">
          <!-- Document viewer -->
          <div class="w-full h-full flex items-center justify-center bg-muted/30">
            <img
              id="preview-image"
              src="/placeholder.svg"
              alt="Document preview"
              class="max-h-full max-w-full object-contain transition-transform"
            >
          </div>
        </div>

        <!-- Document info -->
        <div class="mt-2 p-2 bg-muted/20 rounded-md flex items-center justify-between">
          <div id="document-info">
            <!-- Document info badges will be added here -->
          </div>
        </div>

        <!-- Controls -->
        <div class="flex justify-between mt-4 p-4">
          <div class="flex gap-2">
            <button class="btn btn-outline btn-sm" id="prev-page-btn">Previous</button>
            <button class="btn btn-outline btn-sm" id="next-page-btn">Next</button>
          </div>
          <div class="flex gap-2">
            <button class="btn btn-outline btn-sm" id="zoom-out-btn">
              <i data-lucide="zoom-out" class="h-4 w-4"></i>
            </button>
            <span class="px-2 py-1 border rounded-md text-sm" id="zoom-level">100%</span>
            <button class="btn btn-outline btn-sm" id="zoom-in-btn">
              <i data-lucide="zoom-in" class="h-4 w-4"></i>
            </button>
            <button class="btn btn-outline btn-sm" id="rotate-btn">
              <i data-lucide="rotate-cw" class="h-4 w-4"></i>
            </button>
          </div>
          <button class="btn btn-primary btn-sm" id="proceed-to-typing-from-preview-btn">
            Proceed to Page Typing
          </button>
        </div>
      </div>
    </div>

    <!-- File Selector Dialog -->
    <div id="file-selector-dialog" class="dialog-backdrop hidden" aria-hidden="true">
      <div class="dialog-content animate-fade-in">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold">Select Indexed File for Document Upload</h2>
        </div>
        <div class="py-4 px-6">
          <div class="relative mb-4">
            <i data-lucide="search" class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"></i>
            <input type="search" placeholder="Search indexed files..." class="input w-full pl-8" id="file-search-input">
          </div>
          <div class="rounded-md border divide-y max-h-[400px] overflow-y-auto" id="indexed-files-list">
            <!-- Indexed files will be added here dynamically -->
          </div>
        </div>
        <div class="flex justify-end gap-2 p-4 border-t">
          <button class="btn btn-outline" id="cancel-file-select-btn">Cancel</button>
          <button class="btn btn-primary" id="confirm-file-select-btn" disabled>Select File</button>
        </div>
      </div>
    </div>

    <!-- Document Details Dialog -->
    <div id="document-details-dialog" class="dialog-backdrop hidden" aria-hidden="true">
      <div class="dialog-content animate-fade-in">
        <div class="p-4 border-b">
          <h2 class="text-lg font-semibold">Document Details</h2>
        </div>
        <div class="py-4 px-6 space-y-4">
          <div>
            <label for="document-name" class="block mb-2 text-sm font-medium">File Name</label>
            <p class="text-sm font-medium" id="document-name"></p>
          </div>

          <div>
            <label for="paper-size" class="block mb-2 text-sm font-medium">Paper Size</label>
            <div class="radio-group">
              <div class="radio-item">
                <input type="radio" name="paper-size" id="A4" value="A4">
                <label for="A4" class="text-sm">A4</label>
              </div>
              <div class="radio-item">
                <input type="radio" name="paper-size" id="A5" value="A5">
                <label for="A5" class="text-sm">A5</label>
              </div>
              <div class="radio-item">
                <input type="radio" name="paper-size" id="A3" value="A3">
                <label for="A3" class="text-sm">A3</label>
              </div>
              <div class="radio-item">
                <input type="radio" name="paper-size" id="Letter" value="Letter">
                <label for="Letter" class="text-sm">Letter</label>
              </div>
              <div class="radio-item">
                <input type="radio" name="paper-size" id="Legal" value="Legal">
                <label for="Legal" class="text-sm">Legal</label>
              </div>
              <div class="radio-item">
                <input type="radio" name="paper-size" id="Custom" value="Custom">
                <label for="Custom" class="text-sm">Custom</label>
              </div>
            </div>
          </div>

          <div>
            <label for="document-type" class="block mb-2 text-sm font-medium">Document Type</label>
            <select id="document-type" class="input">
              <option value="Certificate">Certificate</option>
              <option value="Deed">Deed</option>
              <option value="Letter">Letter</option>
              <option value="Application Form">Application Form</option>
              <option value="Map">Map</option>
              <option value="Survey Plan">Survey Plan</option>
              <option value="Receipt">Receipt</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div>
            <label for="document-notes" class="block mb-2 text-sm font-medium">Notes (Optional)</label>
            <textarea id="document-notes" class="input" rows="3"></textarea>
          </div>
        </div>
        <div class="flex justify-end gap-2 p-4 border-t">
          <button class="btn btn-outline" id="cancel-details-btn">Cancel</button>
          <button class="btn btn-primary" id="save-details-btn">Save Details</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Hidden upload form for fallback -->
  <form id="upload-form" action="" method="post" enctype="multipart/form-data" style="display: none;">
    <input type="file" name="file" id="upload-form-file">
    <input type="hidden" name="fileNumber" id="upload-form-fileNumber">
    <input type="hidden" name="fileName" id="upload-form-fileName">
  </form>

  <script>
    // Initialize PDF.js worker
    if (typeof pdfjsLib !== "undefined") {
      pdfjsLib.GlobalWorkerOptions.workerSrc =
        "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
    }

    // Initialize Lucide icons
    lucide.createIcons();

    // State management
    const state = {
      activeTab: 'upload',
      uploadStatus: 'idle', // 'idle', 'uploading', 'complete', 'error'
      uploadProgress: 0,
      previewOpen: false,
      selectedFile: null,
      zoomLevel: 100,
      rotation: 0,
      currentPreviewPage: 1,
      selectedIndexedFile: null,
      showFileSelector: false,
      selectedUploadFiles: [],
      showFolderView: false,
      selectedPageInFolder: null,
      showDocumentDetails: false,
      currentDocumentIndex: null,
      documentBatches: [], // This will be populated from server
      uploadDocuments: [],
      filterPaperSize: 'All',
      searchQuery: '',
      // PDF Conversion State
      pdfConversionEnabled: true,
      hasPdfFiles: false,
      pdfConversionResults: {
        converted: 0,
        failed: 0,
        total: 0,
        failedFiles: []
      },
      currentFolderId: null,
      // Store actual file URLs for display
      filePreviews: new Map(),
      // Server upload configuration - using current page for uploads
      uploadEndpoint: window.location.href,
      // NEW: Track selected file number for upload more functionality
      selectedFileNumberForUpload: null
    };

    // Sample data
    const documentTypes = ["Certificate", "Deed", "Letter", "Application Form", "Map", "Survey Plan", "Receipt", "Other"];

    const indexedFiles = [
      {
        id: "FILE-2023-004",
        fileNumber: "KNML 08146",
        name: "Musa Usman Bayero",
        type: "Right of Occupancy",
        landUseType: "Residential",
        district: "Nasarawa",
      },
      {
        id: "FILE-2023-005",
        fileNumber: "MLKN 03888",
        name: "Hajiya Fatima Mohammed",
        type: "Deed of Assignment",
        landUseType: "Industrial",
        district: "Bompai",
      },
      {
        id: "FILE-2023-006",
        fileNumber: "KNGP 00721",
        name: "Abdullahi Sani",
        type: "Site Plan",
        landUseType: "Commercial",
        district: "Fagge",
      },
    ];

    // DOM Elements
    const elements = {
      // Tabs
      tabs: document.querySelectorAll('[role="tab"]'),
      tabContents: document.querySelectorAll('[role="tabpanel"]'),
      
      // Upload tab
      selectedFileBadge: document.querySelector('.selected-file-badge'),
      selectedFileNumber: document.getElementById('selected-file-number'),
      selectFileBtn: document.getElementById('select-file-btn'),
      changeFileText: document.getElementById('change-file-text'),
      uploadIdle: document.getElementById('upload-idle'),
      fileUpload: document.getElementById('file-upload'),
      browseFilesBtn: document.getElementById('browse-files-btn'),
      selectFileWarning: document.getElementById('select-file-warning'),
      selectedFilesContainer: document.getElementById('selected-files-container'),
      selectedFilesCount: document.getElementById('selected-files-count'),
      selectedFilesList: document.getElementById('selected-files-list'),
      clearAllBtn: document.getElementById('clear-all-btn'),
      uploadProgress: document.getElementById('upload-progress'),
      uploadingCount: document.getElementById('uploading-count'),
      uploadPercentage: document.getElementById('upload-percentage'),
      progressBar: document.getElementById('progress-bar'),
      uploadComplete: document.getElementById('upload-complete'),
      uploadError: document.getElementById('upload-error'),
      uploadErrorMessage: document.getElementById('upload-error-message'),
      startUploadBtn: document.getElementById('start-upload-btn'),
      cancelUploadBtn: document.getElementById('cancel-upload-btn'),
      uploadMoreBtn: document.getElementById('upload-more-btn'),
      viewUploadedBtn: document.getElementById('view-uploaded-btn'),
      
      // PDF Conversion elements
      convertPdfs: document.getElementById('convertPdfs'),
      pdfConversionText: document.getElementById('pdfConversionText'),
      autoConvertBadge: document.getElementById('autoConvertBadge'),
      pdfConversionModal: document.getElementById('pdf-conversion-modal'),
      pdfConversionCurrentFile: document.getElementById('pdf-conversion-current-file'),
      pdfConversionProgressPercent: document.getElementById('pdf-conversion-progress-percent'),
      pdfConversionProgressBar: document.getElementById('pdf-conversion-progress-bar'),
      
      // Uploaded files tab
      uploadsCount: document.getElementById('uploads-count'),
      pendingCount: document.getElementById('pending-count'),
      paperSizeFilter: document.getElementById('paper-size-filter'),
      fileSearch: document.getElementById('file-search'),
      toggleViewBtn: document.getElementById('toggle-view-btn'),
      noDocuments: document.getElementById('no-documents'),
      listView: document.getElementById('list-view'),
      folderView: document.getElementById('folder-view'),
      batchActions: document.getElementById('batch-actions'),
      proceedToTypingBtn: document.getElementById('proceed-to-typing-btn'),
      goToUploadBtn: document.getElementById('go-to-upload-btn'),
      
      // Preview dialog
      previewDialog: document.getElementById('preview-dialog'),
      previewTitle: document.getElementById('preview-title'),
      previewImage: document.getElementById('preview-image'),
      documentInfo: document.getElementById('document-info'),
      prevPageBtn: document.getElementById('prev-page-btn'),
      nextPageBtn: document.getElementById('next-page-btn'),
      zoomOutBtn: document.getElementById('zoom-out-btn'),
      zoomLevel: document.getElementById('zoom-level'),
      zoomInBtn: document.getElementById('zoom-in-btn'),
      rotateBtn: document.getElementById('rotate-btn'),
      proceedToTypingFromPreviewBtn: document.getElementById('proceed-to-typing-from-preview-btn'),
      
      // File selector dialog
      fileSelectorDialog: document.getElementById('file-selector-dialog'),
      fileSearchInput: document.getElementById('file-search-input'),
      indexedFilesList: document.getElementById('indexed-files-list'),
      cancelFileSelectBtn: document.getElementById('cancel-file-select-btn'),
      confirmFileSelectBtn: document.getElementById('confirm-file-select-btn'),
      
      // Document details dialog
      documentDetailsDialog: document.getElementById('document-details-dialog'),
      documentName: document.getElementById('document-name'),
      paperSizeRadios: document.querySelectorAll('input[name="paper-size"]'),
      documentType: document.getElementById('document-type'),
      documentNotes: document.getElementById('document-notes'),
      cancelDetailsBtn: document.getElementById('cancel-details-btn'),
      saveDetailsBtn: document.getElementById('save-details-btn')
    };

    // Helper functions
    function formatFileSize(bytes) {
      if (bytes < 1024) return bytes + " B";
      else if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + " KB";
      else return (bytes / (1024 * 1024)).toFixed(2) + " MB";
    }

    function getPaperSizeColor(size) {
      switch (size) {
        case "A4": return "bg-blue-500";
        case "A5": return "bg-green-500";
        case "A3": return "bg-purple-500";
        case "Letter": return "bg-amber-500";
        case "Legal": return "bg-rose-500";
        case "Custom": return "bg-gray-500";
        default: return "bg-gray-500";
      }
    }

    // File to URL conversion
    function fileToUrl(file) {
      return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = (e) => resolve(e.target.result);
        reader.readAsDataURL(file);
      });
    }

    // Store file preview URLs
    async function storeFilePreview(file, key) {
      if (!state.filePreviews.has(key)) {
        const url = await fileToUrl(file);
        state.filePreviews.set(key, url);
      }
      return state.filePreviews.get(key);
    }

    // Debug function to check file paths
    function debugDeletePaths() {
      console.log('=== DELETE PATH DEBUG ===');
      state.documentBatches.forEach((batch, batchIndex) => {
        console.log(`Batch ${batchIndex}: ${batch.fileNumber}`);
        batch.documents.forEach((doc, docIndex) => {
          console.log(`  Document ${docIndex}:`, {
            fileName: doc.fileName,
            serverPath: doc.serverPath,
            hasServerPath: !!doc.serverPath
          });
        });
      });
    }

    // Test server configuration
    async function testServerConfig() {
      try {
        const response = await fetch(`${state.uploadEndpoint}?action=debug`);
        const result = await response.json();
        console.log('=== SERVER CONFIG DEBUG ===', result);
        return result;
      } catch (error) {
        console.error('Failed to test server config:', error);
      }
    }

    // PDF Conversion Functions
    function detectFileTypes(files) {
      state.hasPdfFiles = false;
      let pdfCount = 0;
      
      for (const file of files) {
        const name = file.name.toLowerCase();
        if (name.endsWith('.pdf')) {
          state.hasPdfFiles = true;
          pdfCount++;
        }
      }
      
      // Update UI based on PDF detection
      if (state.hasPdfFiles) {
        if (state.pdfConversionEnabled) {
          elements.pdfConversionText.textContent = `Found ${pdfCount} PDF file(s). PDF conversion activated.`;
          elements.autoConvertBadge.classList.remove("hidden");
        } else {
          elements.pdfConversionText.textContent = `Found ${pdfCount} PDF file(s). Enable conversion to convert to images.`;
          elements.autoConvertBadge.classList.add("hidden");
        }
      } else {
        elements.pdfConversionText.textContent = "No PDF files detected";
        elements.autoConvertBadge.classList.add("hidden");
      }
    }

    async function convertPdfInBrowser(pdfFile) {
      const arrayBuffer = await pdfFile.arrayBuffer();
      const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
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
        
        const imageFile = new File([blob], `page_${i}.jpg`, { 
          type: 'image/jpeg',
          lastModified: pdfFile.lastModified
        });
        
        images.push({
          file: imageFile,
          page: i,
          width: canvas.width,
          height: canvas.height,
          fileName: `page_${i}.jpg`
        });
      }
      
      return images;
    }

    async function processPdfFilesForUpload(files) {
      const processedFiles = [];
      state.pdfConversionResults = { converted: 0, failed: 0, total: 0, failedFiles: [] };
      
      // Find all PDF files
      const pdfFiles = files.filter(file => file.name.toLowerCase().endsWith('.pdf'));
      state.pdfConversionResults.total = pdfFiles.length;
      
      if (pdfFiles.length === 0) {
        // If no PDFs, just return all files as-is
        return files.map(file => ({ file, type: 'file' }));
      }
      
      // Show PDF conversion modal
      if (elements.pdfConversionModal) elements.pdfConversionModal.classList.remove("hidden");
      
      for (let i = 0; i < pdfFiles.length; i++) {
        const pdfFile = pdfFiles[i];
        
        // Update progress
        const progress = (i / pdfFiles.length) * 100;
        updatePdfConversionProgress(progress, `Converting: ${pdfFile.name}`);
        
        try {
          const images = await convertPdfInBrowser(pdfFile);
          
          // Create a folder structure for this PDF
          const pdfFolderName = pdfFile.name.replace(/\.pdf$/i, '');
          const pdfFolder = {
            id: `FOLDER-${Date.now()}-${i}`,
            name: pdfFolderName,
            type: 'folder',
            status: 'Ready for page typing',
            date: new Date().toLocaleDateString(),
            children: [],
            isPdfFolder: true,
            originalPdfFile: pdfFile
          };
          
          // Add each page as a separate file within the folder
          for (let j = 0; j < images.length; j++) {
            const image = images[j];
            
            const fileEntry = {
              id: `UPLOAD-${Date.now()}-${i}-${j}`,
              name: image.fileName,
              size: formatFileSize(image.file.size),
              type: 'image/jpeg',
              status: 'Ready for page typing',
              date: new Date().toLocaleDateString(),
              file: image.file,
              isConvertedPdf: true,
              parentFolder: pdfFolder.id,
              pageNumber: j + 1
            };
            
            pdfFolder.children.push(fileEntry);
          }
          
          processedFiles.push(pdfFolder);
          state.pdfConversionResults.converted++;
          console.log(`Converted ${pdfFile.name} to ${images.length} pages in folder ${pdfFolderName}`);
          
        } catch (error) {
          state.pdfConversionResults.failed++;
          state.pdfConversionResults.failedFiles.push(pdfFile.name);
          console.error(`Failed to convert ${pdfFile.name}:`, error);
          // Add original PDF if conversion fails
          processedFiles.push({
            file: pdfFile,
            type: 'file',
            isConvertedPdf: false
          });
        }
      }
      
      // Add all non-PDF files
      const nonPdfFiles = files.filter(file => !file.name.toLowerCase().endsWith('.pdf'));
      nonPdfFiles.forEach((file, index) => {
        processedFiles.push({
          file: file,
          type: 'file',
          isConvertedPdf: false
        });
      });
      
      // Hide conversion modal
      if (elements.pdfConversionModal) elements.pdfConversionModal.classList.add("hidden");
      
      return processedFiles;
    }

    function updatePdfConversionProgress(progress, text = '') {
      if (elements.pdfConversionProgressPercent) elements.pdfConversionProgressPercent.textContent = `${Math.round(progress)}%`;
      if (elements.pdfConversionProgressBar) elements.pdfConversionProgressBar.style.width = `${progress}%`;
      if (elements.pdfConversionCurrentFile && text) elements.pdfConversionCurrentFile.textContent = text;
    }

    // Server Upload Functions
    async function uploadFileToServer(file, fileNumber) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('fileNumber', fileNumber);
        formData.append('fileName', file.name);
        
        try {
            console.log('=== UPLOAD ATTEMPT ===');
            console.log('Uploading to:', state.uploadEndpoint);
            console.log('File details:', {
                name: file.name,
                size: file.size,
                type: file.type,
                lastModified: file.lastModified
            });
            console.log('File number:', fileNumber);
            
            const response = await fetch(state.uploadEndpoint, {
                method: 'POST',
                body: formData,
            });
            
            console.log('=== SERVER RESPONSE ===');
            console.log('Status:', response.status, response.statusText);
            
            let result;
            const responseText = await response.text();
            console.log('Raw response:', responseText);
            
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Failed to parse JSON response:', parseError);
                throw new Error(`Server returned invalid JSON: ${responseText.substring(0, 200)}`);
            }
            
            console.log('Parsed result:', result);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${result.message || 'Upload failed'}`);
            }
            
            if (!result.success) {
                throw new Error(result.message || 'Upload failed');
            }
            
            console.log('✅ Upload successful:', result.message);
            return result;
        } catch (error) {
            console.error('❌ Upload failed:', {
                message: error.message,
                fileName: file.name,
                fileSize: file.size,
                fileType: file.type,
                stack: error.stack
            });
            throw error;
        }
    }

    // Helper function to create batch from uploaded files
    function createBatchFromUploadedFiles(indexedFile, uploadedFiles) {
      if (uploadedFiles.length === 0) return;
      
      const batchDocuments = [];
      
      // Group PDF pages by folder
      const pdfFolders = {};
      
      uploadedFiles.forEach(doc => {
        if (doc.isPdfFolder) {
          const folderKey = doc.folderName;
          if (!pdfFolders[folderKey]) {
            pdfFolders[folderKey] = {
              id: `FOLDER-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`,
              fileName: doc.folderName + '.pdf',
              fileSize: doc.file.size * doc.pageCount,
              paperSize: doc.paperSize || 'A4',
              documentType: doc.documentType || 'PDF Document',
              isPdfFolder: true,
              folderName: doc.folderName,
              pageCount: doc.pageCount,
              date: new Date().toLocaleDateString(),
              pages: []
            };
          }
          pdfFolders[folderKey].pages.push({
            file: doc.file,
            fileName: doc.file.name,
            fileSize: doc.file.size,
            paperSize: doc.paperSize || 'A4',
            documentType: doc.documentType || 'Certificate',
            pageNumber: pdfFolders[folderKey].pages.length + 1,
            serverFileName: doc.serverFileName,
            serverPath: doc.serverPath
          });
        } else {
          // Regular file
          batchDocuments.push({
            file: doc.file,
            fileName: doc.file.name,
            fileSize: doc.file.size,
            paperSize: doc.paperSize || 'A4',
            documentType: doc.documentType || 'Other',
            notes: doc.notes,
            date: new Date().toLocaleDateString(),
            isConvertedPdf: doc.isConvertedPdf || false,
            serverFileName: doc.serverFileName,
            serverPath: doc.serverPath
          });
        }
      });
      
      // Add PDF folders and their pages
      Object.values(pdfFolders).forEach(folder => {
        batchDocuments.push({
          id: folder.id,
          fileName: folder.fileName,
          fileSize: folder.fileSize,
          paperSize: folder.paperSize,
          documentType: folder.documentType,
          isPdfFolder: true,
          folderName: folder.folderName,
          pageCount: folder.pageCount,
          date: folder.date
        });
        
        folder.pages.forEach(page => {
          batchDocuments.push({
            fileName: page.fileName,
            fileSize: page.fileSize,
            paperSize: page.paperSize,
            documentType: page.documentType,
            parentFolder: folder.id,
            pageNumber: page.pageNumber,
            file: page.file,
            date: new Date().toLocaleDateString(),
            serverFileName: page.serverFileName,
            serverPath: page.serverPath
          });
        });
      });
      
      const newBatch = {
        id: `BATCH-${Date.now()}`,
        fileNumber: indexedFile.fileNumber,
        name: indexedFile.name,
        documents: batchDocuments,
        date: new Date().toLocaleDateString(),
        status: 'Ready for page typing',
        uploadTime: new Date().toISOString()
      };
      
      // Add to client state (pre-existing batches from server + new one)
      state.documentBatches = [newBatch, ...state.documentBatches];
      console.log('Created new batch:', newBatch);
      console.log('Total batches:', state.documentBatches.length);
    }

    async function startUpload() {
      if (!state.selectedIndexedFile) {
        state.showFileSelector = true;
        updateUI();
        return;
      }
      
      if (state.uploadDocuments.length === 0) {
        alert('Please select files to upload');
        return;
      }
      
      state.uploadStatus = 'uploading';
      state.uploadProgress = 0;
      updateUI();
      
      // Find the selected indexed file
      const indexedFile = indexedFiles.find(f => f.id === state.selectedIndexedFile);
      
      if (!indexedFile) {
        alert('Selected file not found');
        state.uploadStatus = 'idle';
        updateUI();
        return;
      }
      
      // Upload files one by one
      const totalFiles = state.uploadDocuments.length;
      let successfulUploads = 0;
      let failedUploads = 0;
      const uploadedFiles = []; // Track successfully uploaded files
      
      for (let i = 0; i < totalFiles; i++) {
        const doc = state.uploadDocuments[i];
        
        // Update progress
        state.uploadProgress = Math.round((i / totalFiles) * 100);
        updateUI();
        
        try {
          const result = await uploadFileToServer(doc.file, indexedFile.fileNumber);
          successfulUploads++;
          
          // Store the server filename and path for deletion
          uploadedFiles.push({
            ...doc,
            serverFileName: result.fileName,        // Unique server filename
            serverPath: result.webPath,             // Web-accessible path for deletion
            originalName: result.originalName       // Original filename for display
          });
          
          console.log(`Successfully uploaded: ${doc.file.name} → Server: ${result.fileName}`);
        } catch (error) {
          console.error(`Failed to upload ${doc.file.name}:`, error);
          failedUploads++;
        }
      }
      
      // Complete upload
      state.uploadProgress = 100;
      
      if (failedUploads > 0) {
        if (successfulUploads > 0) {
          // Some files uploaded successfully - create batch with successful ones
          createBatchFromUploadedFiles(indexedFile, uploadedFiles);
          state.uploadStatus = 'complete';
          elements.uploadErrorMessage.textContent = `${successfulUploads} files uploaded successfully, ${failedUploads} files failed. Documents tab has been updated.`;
          elements.uploadError.classList.remove('hidden');
          console.log(`Partial success: ${successfulUploads} uploaded, ${failedUploads} failed`);
        } else {
          // All files failed
          state.uploadStatus = 'error';
          elements.uploadErrorMessage.textContent = `All ${failedUploads} files failed to upload. Check console for details.`;
          elements.uploadError.classList.remove('hidden');
          console.log('All files failed to upload');
        }
      } else {
        // All files uploaded successfully
        createBatchFromUploadedFiles(indexedFile, uploadedFiles);
        state.uploadStatus = 'complete';
        elements.uploadError.classList.add('hidden');
        
        // Show conversion results if applicable
        if (state.pdfConversionEnabled && state.hasPdfFiles && state.pdfConversionResults.converted > 0) {
          console.log(`PDF conversion complete. Converted ${state.pdfConversionResults.converted} PDF file(s) to images.`);
        }
        console.log('All files uploaded successfully');
      }
      
      updateUI();
    }
    
    /**
     * Fetches the upload log from the server and populates the state.
     */
    async function loadServerData() {
      try {
        const response = await fetch(`${state.uploadEndpoint}?action=log`);
        if (!response.ok) {
          throw new Error(`Failed to fetch log: ${response.statusText}`);
        }
        
        const result = await response.json();
        if (result.success && Array.isArray(result.data)) {
          // Server returns: [{ fileNumber, documents: [{ fileName, fileSize, serverPath, date }], date }]
          // Client expects: [{ id, fileNumber, name, documents: [...], date, status, ... }]
          
          const newBatches = result.data.map((serverBatch, index) => {
            // Find the matching indexed file to get the 'name'
            const indexedFile = indexedFiles.find(f => f.fileNumber === serverBatch.fileNumber);
            
            const clientDocuments = serverBatch.documents.map((serverDoc, docIndex) => {
              // This is a file from the server, not a File object.
              return {
                id: `DOC-SERVER-${serverBatch.fileNumber}-${docIndex}`,
                fileName: serverDoc.fileName,
                fileSize: serverDoc.fileSize, // This is in bytes
                paperSize: 'A4', // Default, server doesn't know
                documentType: 'Other', // Default
                date: serverDoc.date,
                serverPath: serverDoc.serverPath, // This is the web-accessible path for deletion
                serverFileName: serverDoc.serverFileName, // Server filename
                file: null, // No client-side File object
                isConvertedPdf: false 
              };
            });
            
            return {
              id: `BATCH-SERVER-${index}`,
              fileNumber: serverBatch.fileNumber,
              name: indexedFile ? indexedFile.name : 'Unknown File', // Get name from hardcoded list
              documents: clientDocuments,
              date: serverBatch.date,
              status: 'Ready for page typing'
            };
          });
          
          state.documentBatches = newBatches;
          console.log('Loaded server data:', state.documentBatches);
          
          // Debug the loaded paths
          debugDeletePaths();
        }
      } catch (error) {
        console.error('Failed to load server data:', error);
        // Don't show an alert, just log it.
      }
    }
    
    /**
     * Deletes a single document from the server and updates the state.
     * @param {string} serverPath The web-accessible path to the file (e.g., /storage/FILE-123/unique_name.jpg)
     */
    async function deleteDocument(serverPath) {
      if (!confirm('Are you sure you want to delete this document?\nThis action cannot be undone.')) {
        return;
      }
      
      try {
        console.log('=== DELETE ATTEMPT ===');
        console.log('Deleting file with path:', serverPath);
        
        const response = await fetch(state.uploadEndpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'delete',
            path: serverPath
          })
        });
        
        const result = await response.json();
        
        if (!response.ok || !result.success) {
          throw new Error(result.message || 'Failed to delete file.');
        }
        
        console.log('✅ Successfully deleted:', serverPath);
        
        // Remove the file from the client state
        removeDocumentFromState(serverPath);
        
      } catch (error) {
        console.error('❌ Delete failed:', error);
        alert(`Error: ${error.message}`);
      }
    }

    /**
     * Removes a document from the client state by server path
     */
    function removeDocumentFromState(serverPath) {
      state.documentBatches = state.documentBatches.map(batch => {
        return {
          ...batch,
          documents: batch.documents.filter(doc => doc.serverPath !== serverPath)
        };
      }).filter(batch => batch.documents.length > 0); // Remove empty batches
      
      updateUI();
    }

    // NEW: Handle Upload More functionality for specific file numbers
    function handleUploadMoreToFolder(fileNumber) {
      console.log(`Uploading more documents to file number: ${fileNumber}`);
      
      // Find the indexed file by file number
      const selectedFile = indexedFiles.find(f => f.fileNumber === fileNumber);
      
      if (selectedFile) {
        // Set the selected file for upload
        state.selectedIndexedFile = selectedFile.id;
        
        // Switch to upload tab
        switchTab('upload');
        
        // Update UI to show the selected file
        updateUI();
        
        // Show success notification
        showNotification(`Ready to upload more documents to <strong>${fileNumber}</strong>`);
      } else {
        console.error(`File number ${fileNumber} not found in indexed files`);
        alert(`Error: File number ${fileNumber} not found.`);
      }
    }

    // NEW: Show toast notification
    function showNotification(message) {
      const notification = document.createElement('div');
      notification.className = 'toast-notification';
      notification.innerHTML = `
        <div class="flex items-center gap-2">
          <i data-lucide="check-circle" class="h-5 w-5"></i>
          <span>${message}</span>
        </div>
      `;
      document.body.appendChild(notification);
      lucide.createIcons();
      
      // Auto-remove after 5 seconds
      setTimeout(() => {
        notification.remove();
      }, 5000);
    }

    // Image Preview Functions
    function createFileIcon(fileType) {
      const isImage = fileType.startsWith('image/');
      const isPDF = fileType === 'application/pdf' || fileType.endsWith('.pdf');
      
      if (isImage) {
        return `
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="32" height="32" rx="4" fill="#E5E5E5"/>
            <path d="M22 12H10V20H22V12Z" fill="#9CA3AF"/>
            <circle cx="13" cy="14" r="2" fill="#6B7280"/>
            <path d="M22 16L18 12L10 20L14 16L16 18L22 12V16Z" fill="#6B7280"/>
          </svg>
        `;
      } else if (isPDF) {
        return `
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="32" height="32" rx="4" fill="#E5E5E5"/>
            <path d="M24 28H8C4.68629 28 2 27.3137 2 24V8C2 4.68629 4.68629 2 8 2H24C27.3137 2 30 4.68629 30 8V24C30 27.3137 27.3137 28 24 28Z" fill="#E5E5E5"/>
            <path d="M18 10H12V16H18V10Z" fill="#999999"/>
            <path d="M20 22H22C22.5523 22 23 21.5523 23 21V14C23 13.4477 22.5523 13 22 13H20C19.4477 13 19 13.4477 19 14V21C19 21.5523 19.4477 22 20 22Z" fill="#999999"/>
            <path d="M12 22H14C14.5523 22 15 21.5523 15 21V14C15 13.4477 14.5523 13 14 13H12C11.4477 13 11 13.4477 11 14V21C11 21.5523 11.4477 22 12 22Z" fill="#999999"/>
            <path d="M8 22H10C10.5523 22 11 21.5523 11 21V14C11 13.4477 10.5523 13 10 13H8C7.44772 13 7 13.4477 7 14V21C7 21.5523 7.44772 22 8 22Z" fill="#999999"/>
          </svg>
        `;
      } else {
        return `
          <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="32" height="32" rx="4" fill="#E5E5E5"/>
            <path d="M19 12H9V14H19V12Z" fill="#9CA3AF"/>
            <path d="M19 16H9V18H19V16Z" fill="#9CA3AF"/>
            <path d="M13 20H9V22H13V20Z" fill="#9CA3AF"/>
            <path d="M23 12V20H21V14H17V12H23Z" fill="#6B7280"/>
          </svg>
        `;
      }
    }

    async function renderSelectedFiles() {
      elements.selectedFilesList.innerHTML = '';
      
      for (const [index, doc] of state.uploadDocuments.entries()) {
        const fileItem = document.createElement('div');
        fileItem.className = 'flex items-center justify-between p-3 border-b last:border-b-0';
        
        // Generate preview URL for image files
        const isImage = doc.file.type.startsWith('image/');
        const isPDF = doc.file.name.toLowerCase().endsWith('.pdf');
        
        let previewUrl = createFileIcon(doc.file.type);
        let thumbnailContent = previewUrl;
        
        if (isImage) {
          try {
            const imageUrl = await fileToUrl(doc.file);
            thumbnailContent = `
              <div class="file-preview-container">
                <div class="file-preview-thumbnail">
                  <img 
                    src="${imageUrl}" 
                    alt="${doc.file.name}"
                    class="w-full h-full object-contain"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block'"
                  >
                  <div style="display: none;">${previewUrl}</div>
                </div>
                <div class="file-preview-overlay">
                  <button class="bg-white bg-opacity-90 rounded-full p-2 shadow-lg preview-file-btn" data-index="${index}">
                    <i data-lucide="zoom-in" class="h-4 w-4"></i>
                  </button>
                </div>
              </div>
            `;
          } catch (error) {
            console.error('Failed to load image preview:', error);
            thumbnailContent = previewUrl;
          }
        } else {
          thumbnailContent = `
            <div class="file-preview-thumbnail">
              ${previewUrl}
            </div>
          `;
        }
        
        fileItem.innerHTML = `
          <div class="flex items-center gap-3 flex-1">
            <!-- Preview Thumbnail -->
            ${thumbnailContent}
            
            <!-- File Info -->
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm truncate" title="${doc.file.name}">${doc.file.name}</p>
              <div class="flex items-center gap-2 mt-1 flex-wrap">
                <span class="badge ${getPaperSizeColor(doc.paperSize)} text-white text-xs">${doc.paperSize}</span>
                <span class="badge badge-outline text-xs">${doc.documentType}</span>
                <span class="text-xs text-muted-foreground">${formatFileSize(doc.file.size)}</span>
                ${isPDF ? '<span class="badge bg-red-500 text-white text-xs">PDF</span>' : ''}
                ${doc.isConvertedPdf ? '<span class="badge bg-green-500 text-white text-xs">PDF Converted</span>' : ''}
              </div>
              <p class="text-xs text-gray-500 mt-1">
                ${isImage ? 'Image' : isPDF ? 'PDF Document' : 'File'} • ${doc.file.type || 'Unknown type'}
              </p>
            </div>
          </div>
          
          <!-- Actions -->
          <div class="flex items-center gap-2 ml-4">
            ${isImage ? `
              <button class="btn btn-outline btn-sm preview-file" data-index="${index}" title="Preview">
                <i data-lucide="eye" class="h-4 w-4"></i>
              </button>
            ` : ''}
            <button class="btn btn-outline btn-sm edit-details" data-index="${index}" title="Edit Details">
              <i data-lucide="edit" class="h-4 w-4"></i>
            </button>
            <button class="btn btn-ghost btn-sm text-red-500 remove-file" data-index="${index}" title="Remove">
              <i data-lucide="trash-2" class="h-4 w-4"></i>
            </button>
          </div>
        `;
        
        elements.selectedFilesList.appendChild(fileItem);
      }
      
      // Initialize icons for the new elements
      lucide.createIcons();
      
      // Add event listeners
      document.querySelectorAll('.edit-details').forEach(btn => {
        btn.addEventListener('click', () => {
          const index = parseInt(btn.getAttribute('data-index'));
          openDocumentDetails(index);
        });
      });
      
      document.querySelectorAll('.remove-file').forEach(btn => {
        btn.addEventListener('click', () => {
          const index = parseInt(btn.getAttribute('data-index'));
          removeFile(index);
        });
      });
      
      document.querySelectorAll('.preview-file').forEach(btn => {
        btn.addEventListener('click', () => {
          const index = parseInt(btn.getAttribute('data-index'));
          previewSelectedFile(index);
        });
      });
      
      document.querySelectorAll('.preview-file-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const index = parseInt(btn.getAttribute('data-index'));
          previewSelectedFile(index);
        });
      });
    }

    // Function to preview selected file in modal
    function previewSelectedFile(index) {
      const doc = state.uploadDocuments[index];
      if (!doc || !doc.file.type.startsWith('image/')) return;
      
      // Create preview modal
      const previewModal = document.createElement('div');
      previewModal.className = 'full-preview-modal';
      previewModal.innerHTML = `
        <div class="full-preview-content">
          <div class="flex justify-between items-center p-4 border-b">
            <h3 class="text-lg font-semibold">${doc.file.name}</h3>
            <button class="btn btn-ghost btn-sm close-preview">
              <i data-lucide="x" class="h-5 w-5"></i>
            </button>
          </div>
          <div class="p-4 flex items-center justify-center max-h-[70vh] overflow-auto">
            <img 
              src="${URL.createObjectURL(doc.file)}" 
              alt="${doc.file.name}"
              class="full-preview-image"
            >
          </div>
          <div class="p-4 border-t bg-gray-50">
            <div class="flex justify-between items-center text-sm">
              <div class="flex gap-4">
                <span class="badge ${getPaperSizeColor(doc.paperSize)} text-white">${doc.paperSize}</span>
                <span class="badge badge-outline">${doc.documentType}</span>
                <span class="text-gray-600">${formatFileSize(doc.file.size)}</span>
              </div>
              <button class="btn btn-primary btn-sm close-preview-btn">
                Close
              </button>
            </div>
          </div>
        </div>
      `;
      
      document.body.appendChild(previewModal);
      
      // Initialize icons
      lucide.createIcons();
      
      // Close on backdrop click
      previewModal.addEventListener('click', (e) => {
        if (e.target === previewModal) {
          previewModal.remove();
        }
      });
      
      // Close on button click
      previewModal.querySelector('.close-preview').addEventListener('click', () => {
        previewModal.remove();
      });
      
      previewModal.querySelector('.close-preview-btn').addEventListener('click', () => {
        previewModal.remove();
      });
      
      // Close on escape key
      const handleEscape = (e) => {
        if (e.key === 'Escape') {
          previewModal.remove();
          document.removeEventListener('keydown', handleEscape);
        }
      };
      document.addEventListener('keydown', handleEscape);
    }

    // Function to pre-generate image previews
    async function pregenerateImagePreviews() {
      for (const doc of state.uploadDocuments) {
        if (doc.file.type.startsWith('image/')) {
          const previewKey = `selected-${doc.file.name}-${doc.file.lastModified}`;
          await storeFilePreview(doc.file, previewKey);
        }
      }
    }

    // UI update functions
    function updateUI() {
      // Update tabs
      elements.tabs.forEach(tab => {
        const tabId = tab.getAttribute('data-tab');
        tab.setAttribute('aria-selected', tabId === state.activeTab);
      });
      
      elements.tabContents.forEach(content => {
        const contentId = content.getAttribute('data-tab-content');
        content.setAttribute('aria-hidden', contentId !== state.activeTab);
      });

      // Update selected file badge
      if (state.selectedIndexedFile) {
        const selectedFile = indexedFiles.find(f => f.id === state.selectedIndexedFile);
        elements.selectedFileBadge.classList.remove('hidden');
        elements.selectedFileNumber.textContent = selectedFile ? selectedFile.fileNumber : 'No file selected';
        elements.changeFileText.textContent = 'Change File';
        elements.browseFilesBtn.disabled = false;
        elements.selectFileWarning.classList.add('hidden');
      } else {
        elements.selectedFileBadge.classList.add('hidden');
        elements.changeFileText.textContent = 'Select File';
        elements.browseFilesBtn.disabled = true;
        elements.selectFileWarning.classList.remove('hidden');
      }

      // Update upload status
      elements.uploadIdle.classList.toggle('hidden', state.uploadStatus !== 'idle');
      elements.uploadProgress.classList.toggle('hidden', state.uploadStatus !== 'uploading');
      elements.uploadComplete.classList.toggle('hidden', state.uploadStatus !== 'complete');
      elements.uploadError.classList.toggle('hidden', state.uploadStatus !== 'error');
      
      // Update buttons based on upload status
      elements.startUploadBtn.classList.toggle('hidden', 
        !(state.uploadStatus === 'idle' && state.uploadDocuments.length > 0));
      elements.cancelUploadBtn.classList.toggle('hidden', state.uploadStatus !== 'uploading');
      elements.uploadMoreBtn.classList.toggle('hidden', state.uploadStatus !== 'complete' && state.uploadStatus !== 'error');
      elements.viewUploadedBtn.classList.toggle('hidden', state.uploadStatus !== 'complete' && state.uploadStatus !== 'error');

      // Update selected files
      elements.selectedFilesContainer.classList.toggle('hidden', state.uploadDocuments.length === 0);
      elements.selectedFilesCount.textContent = state.uploadDocuments.length;
      
      if (state.uploadDocuments.length > 0) {
        renderSelectedFiles();
      }

      // Update upload progress
      if (state.uploadStatus === 'uploading') {
        elements.uploadingCount.textContent = state.uploadDocuments.length;
        elements.uploadPercentage.textContent = `${state.uploadProgress}%`;
        elements.progressBar.style.width = `${state.uploadProgress}%`;
      }

      // Update uploaded files tab
      // Get today's date in MM/DD/YYYY format
      const today = new Date().toLocaleDateString();
      const todaysUploads = state.documentBatches.filter(batch => batch.date === today).length;
      
      elements.uploadsCount.textContent = todaysUploads;
      elements.pendingCount.textContent = state.documentBatches.reduce(
        (total, batch) => total + batch.documents.length, 0
      );

      elements.noDocuments.classList.toggle('hidden', state.documentBatches.length > 0);
      elements.batchActions.classList.toggle('hidden', state.documentBatches.length === 0);
      
      // Update view toggle
      elements.toggleViewBtn.textContent = state.showFolderView ? 'List View' : 'Folder View';
      elements.listView.classList.toggle('hidden', state.showFolderView);
      elements.folderView.classList.toggle('hidden', !state.showFolderView);

      if (state.documentBatches.length > 0) {
        renderBatches();
      }

      // Update dialogs
      elements.previewDialog.classList.toggle('hidden', !state.previewOpen);
      elements.fileSelectorDialog.classList.toggle('hidden', !state.showFileSelector);
      elements.documentDetailsDialog.classList.toggle('hidden', !state.showDocumentDetails);

      // Update preview
      if (state.previewOpen && state.selectedFile) {
        updatePreview();
      }

      // Update file selector
      if (state.showFileSelector) {
        renderIndexedFiles();
      }

      // Update document details
      if (state.showDocumentDetails && state.currentDocumentIndex !== null) {
        updateDocumentDetails();
      }
    }

    function renderBatches() {
      const filteredBatches = getFilteredBatches();
      
      if (state.showFolderView) {
        renderFolderView(filteredBatches);
      } else {
        renderListView(filteredBatches);
      }
    }

    function renderListView(batches) {
      elements.listView.innerHTML = '';
      
      batches.forEach(batch => {
        // Check if this batch contains PDF folders
        const hasPdfFolders = batch.documents.some(doc => doc.isPdfFolder);
        
        if (hasPdfFolders) {
          // Render PDF folders separately
          batch.documents.forEach(doc => {
            if (doc.isPdfFolder) {
              const folderItem = document.createElement('div');
              folderItem.className = 'flex items-center justify-between p-4 border-b';
              
              folderItem.innerHTML = `
                <div class="flex items-center gap-3">
                  <i data-lucide="folder" class="h-8 w-8 text-yellow-500"></i>
                  <div>
                    <p class="font-medium text-blue-600">${batch.fileNumber} - ${doc.folderName}</p>
                    <p class="text-sm text-gray-600">PDF Document (${doc.pageCount} pages)</p>
                    <div class="flex items-center gap-2 mt-1">
                      <span class="badge bg-green-500 text-white text-xs">PDF Converted</span>
                      <span class="text-xs text-muted-foreground">${doc.date}</span>
                      <span class="badge badge-outline text-xs">
                        ${doc.pageCount} ${doc.pageCount === 1 ? 'page' : 'pages'}
                      </span>
                    </div>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <!-- ADD UPLOAD MORE BUTTON -->
                  <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                    <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                    Upload More
                  </button>
                  <button class="btn btn-outline btn-sm preview-batch" data-id="${batch.id}" data-folder="${doc.id}">
                    <i data-lucide="zoom-in" class="h-4 w-4 mr-1"></i>
                    Preview
                  </button>
                  <button class="btn btn-outline btn-sm start-typing" data-id="${batch.id}" data-folder="${doc.id}">
                    Start Page Typing
                  </button>
                </div>
              `;
              
              elements.listView.appendChild(folderItem);
            } else if (!doc.parentFolder) {
              // Render individual files that are not part of PDF folders
              const fileItem = document.createElement('div');
              fileItem.className = 'flex items-center justify-between p-4 border-b';
              
              fileItem.innerHTML = `
                <div class="flex items-center gap-3">
                  <i data-lucide="file" class="h-8 w-8 text-blue-500"></i>
                  <div>
                    <p class="font-medium text-blue-600">${batch.fileNumber}</p>
                    <p class="text-sm text-gray-600">${doc.fileName}</p>
                    <div class="flex items-center gap-2 mt-1">
                      <span class="badge ${getPaperSizeColor(doc.paperSize)} text-white text-xs">${doc.paperSize}</span>
                      <span class="badge badge-outline text-xs">${doc.documentType}</span>
                      <span class="text-xs text-muted-foreground">${formatFileSize(doc.fileSize)}</span>
                    </div>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <!-- ADD UPLOAD MORE BUTTON -->
                  <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                    <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                    Upload More
                  </button>
                  <button class="btn btn-outline btn-sm preview-batch" data-id="${batch.id}" data-doc-index="${batch.documents.indexOf(doc)}">
                    <i data-lucide="zoom-in" class="h-4 w-4 mr-1"></i>
                    Preview
                  </button>
                  <button class="btn btn-outline btn-sm start-typing" data-id="${batch.id}" data-doc-index="${batch.documents.indexOf(doc)}">
                    Start Page Typing
                  </button>
                  <button class="btn btn-ghost btn-sm text-red-500 delete-document" 
                     data-server-path="${doc.serverPath}">
                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                  </button>
                </div>
              `;
              
              elements.listView.appendChild(fileItem);
            }
          });
        } else {
          // Original rendering for non-PDF batches
          const batchItem = document.createElement('div');
          batchItem.className = 'flex items-center justify-between p-4';
          
          // Get unique paper sizes
          const uniquePaperSizes = Array.from(new Set(batch.documents.map(d => d.paperSize)));
          
          batchItem.innerHTML = `
            <div class="flex items-center gap-3">
              <i data-lucide="file-text" class="h-8 w-8 text-blue-500"></i>
              <div>
                <p class="font-medium text-blue-600">${batch.fileNumber}</p>
                <p class="text-sm text-gray-600">${batch.name}</p>
                <div class="flex items-center gap-2 mt-1">
                  <span class="badge badge-outline text-xs">
                    ${batch.documents.length} ${batch.documents.length === 1 ? 'document' : 'documents'}
                  </span>
                  <span class="text-xs text-muted-foreground">${batch.date}</span>
                  <div class="flex gap-1">
                    ${uniquePaperSizes.map(size => 
                      `<span class="badge ${getPaperSizeColor(size)} text-white text-xs">${size}</span>`
                    ).join('')}
                  </div>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <!-- ADD UPLOAD MORE BUTTON -->
              <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                Upload More
              </button>
              <button class="btn btn-outline btn-sm preview-batch" data-id="${batch.id}">
                <i data-lucide="zoom-in" class="h-4 w-4 mr-1"></i>
                Preview
              </button>
              <button class="btn btn-outline btn-sm start-typing" data-id="${batch.id}">
                Start Page Typing
              </button>
            </div>
          `;
          
          elements.listView.appendChild(batchItem);
        }
      });
      
      // Initialize icons for the new elements
      lucide.createIcons();
      
      // Add event listeners
      document.querySelectorAll('.preview-batch').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-id');
          const folderId = btn.getAttribute('data-folder');
          const docIndex = btn.getAttribute('data-doc-index');
          openPreview(id, docIndex ? parseInt(docIndex) : 0, folderId);
        });
      });
      
      document.querySelectorAll('.start-typing').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-id');
          const folderId = btn.getAttribute('data-folder');
          window.location.href = `/file-digital-registry/page-typing?fileId=${id}${folderId ? `&folder=${folderId}` : ''}`;
        });
      });
      
      document.querySelectorAll('.delete-document').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const serverPath = btn.getAttribute('data-server-path');
          if (serverPath) {
            console.log('Delete button clicked for:', serverPath);
            deleteDocument(serverPath);
          } else {
            alert('No file path found for deletion. Please check console for details.');
            console.error('No server path found for delete button');
          }
        });
      });

      // NEW: Add event listeners for upload more buttons
      document.querySelectorAll('.upload-more-to-folder').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const fileNumber = btn.getAttribute('data-file-number');
          handleUploadMoreToFolder(fileNumber);
        });
      });
    }

    async function renderFolderView(batches) {
      elements.folderView.innerHTML = '';
      
      for (const batch of batches) {
        const hasPdfFolders = batch.documents.some(doc => doc.isPdfFolder);
        
        if (hasPdfFolders) {
          // Render PDF folders in folder view
          for (const doc of batch.documents) {
            if (doc.isPdfFolder) {
              const folderItem = document.createElement('div');
              folderItem.className = 'border rounded-md overflow-hidden mb-4';
              
              folderItem.innerHTML = `
                <div class="p-4 bg-yellow-50 border-b">
                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                      <i data-lucide="folder" class="h-6 w-6 text-yellow-500"></i>
                      <div>
                        <p class="font-medium text-blue-600">${batch.fileNumber} - ${doc.folderName}</p>
                        <p class="text-sm">PDF Document (${doc.pageCount} pages)</p>
                      </div>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="badge bg-green-500 text-white text-xs">PDF Converted</span>
                      <!-- ADD UPLOAD MORE BUTTON -->
                      <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                        <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                        Upload More
                      </button>
                      <button class="btn btn-outline btn-sm start-typing" data-id="${batch.id}" data-folder="${doc.id}">
                        Start Page Typing
                      </button>
                    </div>
                  </div>
                </div>
                <div class="p-4">
                  <h4 class="text-sm font-medium mb-3">PDF Pages (${doc.pageCount} pages)</h4>
                  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 documents-grid" data-batch-id="${batch.id}" data-folder-id="${doc.id}">
                    <!-- PDF pages will be added here -->
                  </div>
                </div>
              `;
              
              elements.folderView.appendChild(folderItem);
              
              // Add PDF pages to the grid
              const documentsGrid = folderItem.querySelector('.documents-grid');
              
              // Get all pages for this PDF folder
              const pdfPages = batch.documents.filter(page => page.parentFolder === doc.id);
              
              for (const [index, page] of pdfPages.entries()) {
                const pageItem = document.createElement('div');
                pageItem.className = 'border rounded-md overflow-hidden cursor-pointer hover:border-blue-500 transition-colors document-item';
                pageItem.setAttribute('data-batch-id', batch.id);
                pageItem.setAttribute('data-folder-id', doc.id);
                pageItem.setAttribute('data-page-index', index);
                
                // Get the actual image URL from stored files
                const previewKey = `${batch.id}-${doc.id}-${index}`;
                let imageUrl = '/placeholder.svg';
                
                if (page.file && page.file instanceof File) {
                  imageUrl = await storeFilePreview(page.file, previewKey);
                } else if (page.serverPath) {
                  imageUrl = page.serverPath;
                }
                
                pageItem.innerHTML = `
                  <div class="h-40 bg-muted flex items-center justify-center">
                    <img
                      src="${imageUrl}"
                      alt="Page ${index + 1}"
                      class="document-image max-h-full max-w-full object-contain"
                      onerror="this.src='/placeholder.svg'"
                    >
                  </div>
                  <div class="p-2 bg-gray-50 border-t">
                    <div class="flex justify-between items-center">
                      <span class="text-sm font-medium">Page ${index + 1}</span>
                      <span class="badge ${getPaperSizeColor(page.paperSize)} text-white text-xs">${page.paperSize}</span>
                    </div>
                    <div class="mt-1">
                      <span class="badge badge-outline text-xs w-full justify-center">${page.documentType}</span>
                    </div>
                    <div class="mt-1 flex justify-between items-center">
                      <span class="badge bg-blue-500 text-white text-xs overflow-hidden text-ellipsis">
                        ${batch.fileNumber}-P${(index + 1).toString().padStart(2, '0')}
                      </span>
                      <button class="btn btn-ghost btn-sm text-red-500 delete-document" data-server-path="${page.serverPath}" style="padding: 2px; height: auto;">
                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                      </button>
                    </div>
                  </div>
                `;
                
                documentsGrid.appendChild(pageItem);
              }
            }
          }
          
          // Also render individual files that are not part of PDF folders
          const individualFiles = batch.documents.filter(doc => !doc.isPdfFolder && !doc.parentFolder);
          if (individualFiles.length > 0) {
            const filesItem = document.createElement('div');
            filesItem.className = 'border rounded-md overflow-hidden mb-4';
            
            filesItem.innerHTML = `
              <div class="p-4 bg-blue-50 border-b">
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <i data-lucide="file-text" class="h-6 w-6 text-blue-500"></i>
                    <div>
                      <p class="font-medium text-blue-600">${batch.fileNumber}</p>
                      <p class="text-sm">Individual Files</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <span class="badge badge-outline text-xs">
                      ${individualFiles.length} ${individualFiles.length === 1 ? 'file' : 'files'}
                    </span>
                    <!-- ADD UPLOAD MORE BUTTON -->
                    <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                      <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                      Upload More
                    </button>
                  </div>
                </div>
              </div>
              <div class="p-4">
                <h4 class="text-sm font-medium mb-3">Files</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 documents-grid" data-batch-id="${batch.id}">
                  <!-- Individual files will be added here -->
                </div>
              </div>
            `;
            
            elements.folderView.appendChild(filesItem);
            
            // Add individual files to the grid
            const documentsGrid = filesItem.querySelector('.documents-grid');
            
            for (const [index, doc] of individualFiles.entries()) {
              const docItem = document.createElement('div');
              docItem.className = 'border rounded-md overflow-hidden cursor-pointer hover:border-blue-500 transition-colors document-item';
              docItem.setAttribute('data-batch-id', batch.id);
              docItem.setAttribute('data-index', batch.documents.indexOf(doc));
              
              // Get the actual image URL from stored files
              const previewKey = `${batch.id}-${index}`;
              let imageUrl = '/placeholder.svg';
              
              if (doc.file && doc.file instanceof File) {
                imageUrl = await storeFilePreview(doc.file, previewKey);
              } else if (doc.serverPath) {
                imageUrl = doc.serverPath;
              }
              
              docItem.innerHTML = `
                <div class="h-40 bg-muted flex items-center justify-center">
                  <img
                    src="${imageUrl}"
                    alt="${doc.fileName}"
                    class="document-image max-h-full max-w-full object-contain"
                    onerror="this.src='/placeholder.svg'"
                  >
                </div>
                <div class="p-2 bg-gray-50 border-t">
                  <div class="flex justify-between items-center">
                    <span class="text-sm font-medium overflow-hidden text-ellipsis whitespace-nowrap" style="max-width: 100px;">${doc.fileName}</span>
                    <span class="badge ${getPaperSizeColor(doc.paperSize)} text-white text-xs">${doc.paperSize}</span>
                  </div>
                  <div class="mt-1">
                    <span class="badge badge-outline text-xs w-full justify-center">${doc.documentType}</span>
                  </div>
                  <div class="mt-1 flex justify-between items-center">
                    <span class="badge bg-blue-500 text-white text-xs mt-1 overflow-hidden text-ellipsis">
                      ${batch.fileNumber}-${(index + 1).toString().padStart(2, '0')}
                    </span>
                    <button class="btn btn-ghost btn-sm text-red-500 delete-document" data-server-path="${doc.serverPath}" style="padding: 2px; height: auto;">
                      <i data-lucide="trash-2" class="h-4 w-4"></i>
                    </button>
                  </div>
                </div>
              `;
              
              documentsGrid.appendChild(docItem);
            }
          }
        } else {
          // Original folder view for non-PDF batches
          const folderItem = document.createElement('div');
          folderItem.className = 'border rounded-md overflow-hidden';
          
          folderItem.innerHTML = `
            <div class="p-4 bg-muted/20 border-b">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <i data-lucide="folder-open" class="h-6 w-6 text-blue-500"></i>
                  <div>
                    <p class="font-medium text-blue-600">${batch.fileNumber}</p>
                    <p class="text-sm">${batch.name}</p>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <span class="badge badge-outline text-xs">
                    ${batch.documents.length} ${batch.documents.length === 1 ? 'document' : 'documents'}
                  </span>
                  <!-- ADD UPLOAD MORE BUTTON -->
                  <button class="btn btn-outline btn-sm upload-more-to-folder" data-file-number="${batch.fileNumber}">
                    <i data-lucide="upload" class="h-4 w-4 mr-1"></i>
                    Upload More
                  </button>
                  <button class="btn btn-outline btn-sm start-typing" data-id="${batch.id}">
                    Start Page Typing
                  </button>
                </div>
              </div>
            </div>
            <div class="p-4">
              <h4 class="text-sm font-medium mb-3">Documents</h4>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-4 documents-grid" data-batch-id="${batch.id}">
                <!-- Documents will be added here dynamically -->
              </div>
            </div>
          `;
          
          elements.folderView.appendChild(folderItem);
          
          // Add documents to the grid
          const documentsGrid = folderItem.querySelector('.documents-grid');
          
          for (const [index, doc] of batch.documents.entries()) {
            if (state.filterPaperSize === 'All' || doc.paperSize === state.filterPaperSize) {
              const docItem = document.createElement('div');
              docItem.className = 'border rounded-md overflow-hidden cursor-pointer hover:border-blue-500 transition-colors document-item';
              docItem.setAttribute('data-batch-id', batch.id);
              docItem.setAttribute('data-index', index);
              
              // Get the actual image URL from stored files
              const previewKey = `${batch.id}-${index}`;
              let imageUrl = '/placeholder.svg';
              
              if (doc.file && doc.file instanceof File) {
                imageUrl = await storeFilePreview(doc.file, previewKey);
              } else if (doc.serverPath) {
                imageUrl = doc.serverPath;
              }
              
              docItem.innerHTML = `
                <div class="h-40 bg-muted flex items-center justify-center">
                  <img
                    src="${imageUrl}"
                    alt="Document ${index + 1}"
                    class="document-image max-h-full max-w-full object-contain"
                    onerror="this.src='/placeholder.svg'"
                  >
                </div>
                <div class="p-2 bg-gray-50 border-t">
                  <div class="flex justify-between items-center">
                    <span class="text-sm font-medium">Document ${index + 1}</span>
                    <span class="badge ${getPaperSizeColor(doc.paperSize)} text-white text-xs">${doc.paperSize}</span>
                  </div>
                  <div class="mt-1">
                    <span class="badge badge-outline text-xs w-full justify-center">${doc.documentType}</span>
                  </div>
                  <div class="mt-1 flex justify-between items-center">
                    <span class="badge bg-blue-500 text-white text-xs mt-1 overflow-hidden text-ellipsis">
                      ${batch.fileNumber}-${(index + 1).toString().padStart(2, '0')}
                    </span>
                    <button class="btn btn-ghost btn-sm text-red-500 delete-document" data-server-path="${doc.serverPath}" style="padding: 2px; height: auto;">
                      <i data-lucide="trash-2" class="h-4 w-4"></i>
                    </button>
                  </div>
                </div>
              `;
              
              documentsGrid.appendChild(docItem);
            }
          }
        }
      }
      
      // Initialize icons for the new elements
      lucide.createIcons();
      
      // Add event listeners
      document.querySelectorAll('.start-typing').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-id');
          const folderId = btn.getAttribute('data-folder');
          window.location.href = `/file-digital-registry/page-typing?fileId=${id}${folderId ? `&folder=${folderId}` : ''}`;
        });
      });
      
      document.querySelectorAll('.document-item').forEach(item => {
        item.addEventListener('click', () => {
          const batchId = item.getAttribute('data-batch-id');
          const folderId = item.getAttribute('data-folder-id');
          const index = parseInt(item.getAttribute('data-index') || item.getAttribute('data-page-index') || 0);
          openPreview(batchId, index, folderId);
        });
      });
      
      document.querySelectorAll('.delete-document').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation(); // Prevent preview from opening
          const serverPath = btn.getAttribute('data-server-path');
          if (serverPath) {
            console.log('Delete button clicked for:', serverPath);
            deleteDocument(serverPath);
          } else {
            alert('No file path found for deletion. Please check console for details.');
            console.error('No server path found for delete button');
          }
        });
      });

      // NEW: Add event listeners for upload more buttons
      document.querySelectorAll('.upload-more-to-folder').forEach(btn => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const fileNumber = btn.getAttribute('data-file-number');
          handleUploadMoreToFolder(fileNumber);
        });
      });
    }

    function updatePreview() {
      const batch = state.documentBatches.find(b => b.id === state.selectedFile);
      
      if (!batch) return;
      
      let currentDocument;
      let totalPages;
      
      if (state.currentFolderId) {
        // Show PDF page from folder
        const folderPages = batch.documents.filter(doc => doc.parentFolder === state.currentFolderId);
        currentDocument = folderPages[state.currentPreviewPage - 1];
        totalPages = folderPages.length;
      } else {
        // Show regular document
        currentDocument = batch.documents[state.currentPreviewPage - 1];
        totalPages = batch.documents.filter(doc => !doc.parentFolder).length;
      }
      
      // Update title
      let title = `${batch.name}`;
      if (state.currentFolderId) {
        const folder = batch.documents.find(doc => doc.id === state.currentFolderId);
        title += ` - ${folder ? folder.folderName : 'PDF'} - Page ${state.currentPreviewPage} of ${totalPages}`;
      } else {
        title += ` - Document ${state.currentPreviewPage} of ${totalPages}`;
      }
      elements.previewTitle.textContent = title;
      
      // Update image with actual file
      if (currentDocument) {
          if (currentDocument.file) { // Prioritize client-side file
              const previewKey = state.currentFolderId 
                ? `${batch.id}-${state.currentFolderId}-${state.currentPreviewPage - 1}`
                : `${batch.id}-${state.currentPreviewPage - 1}`;
              
              const imageUrl = state.filePreviews.get(previewKey) || '/placeholder.svg';
              elements.previewImage.src = imageUrl;
          } else if (currentDocument.serverPath) { // Fallback to server path
              elements.previewImage.src = currentDocument.serverPath;
          } else {
              elements.previewImage.src = '/placeholder.svg';
          }
      } else {
          elements.previewImage.src = '/placeholder.svg';
      }
      
      elements.previewImage.style.transform = `scale(${state.zoomLevel / 100}) rotate(${state.rotation}deg)`;
      
      // Update document info
      elements.documentInfo.innerHTML = '';
      
      const fileNumberBadge = document.createElement('span');
      fileNumberBadge.className = 'badge mr-2';
      
      if (state.currentFolderId) {
        fileNumberBadge.textContent = `${batch.fileNumber}-P${state.currentPreviewPage.toString().padStart(2, '0')}`;
      } else {
        fileNumberBadge.textContent = `${batch.fileNumber}-${state.currentPreviewPage.toString().padStart(2, '0')}`;
      }
      
      elements.documentInfo.appendChild(fileNumberBadge);
      
      if (currentDocument) {
        const paperSizeBadge = document.createElement('span');
        paperSizeBadge.className = `badge mr-2 ${getPaperSizeColor(currentDocument.paperSize)}`;
        paperSizeBadge.textContent = currentDocument.paperSize;
        elements.documentInfo.appendChild(paperSizeBadge);
        
        const typeBadge = document.createElement('span');
        typeBadge.className = 'badge badge-outline';
        typeBadge.textContent = currentDocument.documentType;
        elements.documentInfo.appendChild(typeBadge);
        
        // Add PDF conversion badge if applicable
        if (currentDocument.isConvertedPdf || state.currentFolderId) {
          const pdfBadge = document.createElement('span');
          pdfBadge.className = 'badge bg-green-500 text-white ml-2';
          pdfBadge.textContent = 'PDF Converted';
          elements.documentInfo.appendChild(pdfBadge);
        }
      }
      
      // Update navigation buttons
      elements.prevPageBtn.disabled = state.currentPreviewPage <= 1;
      elements.nextPageBtn.disabled = state.currentPreviewPage >= totalPages;
      
      // Update zoom level
      elements.zoomLevel.textContent = `${state.zoomLevel}%`;
    }

    function renderIndexedFiles() {
      elements.indexedFilesList.innerHTML = '';
      
      indexedFiles.forEach(file => {
        const fileItem = document.createElement('div');
        fileItem.className = `flex items-center p-4 cursor-pointer hover:bg-muted/50 ${
          state.selectedIndexedFile === file.id ? 'bg-muted' : ''
        }`;
        fileItem.setAttribute('data-id', file.id);
        
        fileItem.innerHTML = `
          <i data-lucide="folder" class="h-6 w-6 mr-3 ${
            state.selectedIndexedFile === file.id ? 'text-blue-500' : 'text-gray-400'
          }"></i>
          <div>
            <p class="font-medium text-blue-600">${file.fileNumber}</p>
            <p class="text-sm">${file.name}</p>
            <div class="flex items-center gap-2 mt-1">
              <span class="badge badge-secondary text-xs">${file.landUseType}</span>
              <span class="badge badge-outline text-xs">${file.district}</span>
            </div>
          </div>
        `;
        
        elements.indexedFilesList.appendChild(fileItem);
      });
      
      // Initialize icons for the new elements
      lucide.createIcons();
      
      // Add event listeners
      document.querySelectorAll('#indexed-files-list > div').forEach(item => {
        item.addEventListener('click', () => {
          const id = item.getAttribute('data-id');
          selectIndexedFileTemp(id);
        });
      });
      
      // Update confirm button
      elements.confirmFileSelectBtn.disabled = !state.selectedIndexedFile;
    }

    function updateDocumentDetails() {
      const doc = state.uploadDocuments[state.currentDocumentIndex];
      
      if (!doc) return;
      
      // Update file name
      elements.documentName.textContent = doc.file.name;
      
      // Update paper size
      elements.paperSizeRadios.forEach(radio => {
        radio.checked = radio.value === doc.paperSize;
      });
      
      // Update document type
      elements.documentType.value = doc.documentType;
      
      // Update notes
      elements.documentNotes.value = doc.notes || '';
    }

    function getFilteredBatches() {
      let filtered = state.documentBatches;
      
      // Filter by paper size
      if (state.filterPaperSize !== 'All') {
        filtered = filtered.filter(batch => 
          batch.documents.some(doc => doc.paperSize === state.filterPaperSize)
        );
      }
      
      // Filter by search query
      if (state.searchQuery) {
        const query = state.searchQuery.toLowerCase();
        filtered = filtered.filter(batch => 
          batch.fileNumber.toLowerCase().includes(query) ||
          batch.name.toLowerCase().includes(query) ||
          batch.documents.some(doc => doc.fileName.toLowerCase().includes(query))
        );
      }
      
      return filtered;
    }

    // Event handlers
    function switchTab(tabId) {
      state.activeTab = tabId;
      updateUI();
    }

    async function handleFileSelect(e) {
      if (e.target.files && e.target.files.length > 0) {
        const files = Array.from(e.target.files);
        state.selectedUploadFiles = files;
        
        // Detect file types for PDF conversion
        detectFileTypes(files);
        
        // Process files (convert PDFs if enabled)
        let filesToProcess = files;
        
        if (state.pdfConversionEnabled && state.hasPdfFiles) {
          try {
            const processedFiles = await processPdfFilesForUpload(files);
            
            // Convert processed files to upload documents
            state.uploadDocuments = [];
            
            for (const item of processedFiles) {
              if (item.type === 'folder' && item.isPdfFolder) {
                // For PDF folders, add all pages as separate upload documents
                for (const page of item.children) {
                  state.uploadDocuments.push({
                    file: page.file,
                    paperSize: 'A4',
                    documentType: 'Certificate',
                    isPdfFolder: true,
                    folderName: item.name,
                    pageCount: item.children.length,
                    originalFile: item.originalPdfFile
                  });
                }
              } else {
                state.uploadDocuments.push({
                  file: item.file,
                  paperSize: 'A4',
                  documentType: 'Other',
                  isConvertedPdf: item.isConvertedPdf || false
                });
              }
            }
            
          } catch (error) {
            console.error("PDF conversion failed:", error);
            alert("PDF conversion failed. Uploading original files instead.");
            // Continue with original files if conversion fails
            state.uploadDocuments = files.map(file => ({
              file,
              paperSize: 'A4',
              documentType: 'Other',
            }));
          }
        } else {
          // Process files without conversion
          state.uploadDocuments = files.map(file => ({
            file,
            paperSize: 'A4',
            documentType: 'Other',
          }));
        }
        
        // Pre-generate previews for images
        await pregenerateImagePreviews();
        
        updateUI();
      }
    }

    function openDocumentDetails(index) {
      state.currentDocumentIndex = index;
      state.showDocumentDetails = true;
      updateUI();
    }

    function updateDocumentDetails(index, updates) {
      state.uploadDocuments = state.uploadDocuments.map((doc, i) => 
        i === index ? { ...doc, ...updates } : doc
      );
      updateUI();
    }

    function removeFile(index) {
      state.selectedUploadFiles = state.selectedUploadFiles.filter((_, i) => i !== index);
      state.uploadDocuments = state.uploadDocuments.filter((_, i) => i !== index);
      updateUI();
    }

    function resetUpload() {
      state.uploadStatus = 'idle';
      state.uploadProgress = 0;
      state.selectedUploadFiles = [];
      state.uploadDocuments = [];
      elements.fileUpload.value = '';
      elements.uploadError.classList.add('hidden');
      updateUI();
    }

    function sendToPageTyping() {
      if (state.documentBatches.length === 0) {
        alert('No files to send to page typing');
        return;
      }
      
      window.location.href = '/file-digital-registry/page-typing';
    }

    function openPreview(batchId, documentIndex = 0, folderId = null) {
      state.selectedFile = batchId;
      state.currentPreviewPage = documentIndex + 1;
      state.zoomLevel = 100;
      state.rotation = 0;
      state.previewOpen = true;
      state.currentFolderId = folderId;
      updateUI();
    }

    function closePreview() {
      state.previewOpen = false;
      state.currentFolderId = null;
      updateUI();
    }

    function nextPage() {
      const batch = state.documentBatches.find(b => b.id === state.selectedFile);
      if (!batch) return;
      
      let totalPages;
      if (state.currentFolderId) {
        const folderPages = batch.documents.filter(doc => doc.parentFolder === state.currentFolderId);
        totalPages = folderPages.length;
      } else {
        totalPages = batch.documents.filter(doc => !doc.parentFolder).length;
      }
      
      if (state.currentPreviewPage < totalPages) {
        state.currentPreviewPage++;
        updateUI();
      }
    }

    function prevPage() {
      if (state.currentPreviewPage > 1) {
        state.currentPreviewPage--;
        updateUI();
      }
    }

    function zoomIn() {
      state.zoomLevel = Math.min(state.zoomLevel + 25, 200);
      updateUI();
    }

    function zoomOut() {
      state.zoomLevel = Math.max(state.zoomLevel - 25, 50);
      updateUI();
    }

    function rotate() {
      state.rotation = (state.rotation + 90) % 360;
      updateUI();
    }

    function selectIndexedFileTemp(fileId) {
      // This is just for UI updates in the dialog
      document.querySelectorAll('#indexed-files-list > div').forEach(item => {
        const id = item.getAttribute('data-id');
        const folderIcon = item.querySelector('[data-lucide="folder"]');
        
        if (id === fileId) {
          item.classList.add('bg-muted');
          folderIcon.classList.add('text-blue-500');
          folderIcon.classList.remove('text-gray-400');
        } else {
          item.classList.remove('bg-muted');
          folderIcon.classList.remove('text-blue-500');
          folderIcon.classList.add('text-gray-400');
        }
      });
      
      // Update confirm button
      elements.confirmFileSelectBtn.disabled = !fileId;
      
      // Store the selected ID temporarily
      state.selectedIndexedFile = fileId;
    }

    function selectIndexedFile() {
      state.showFileSelector = false;
      updateUI();
    }

    function deleteBatch(id) {
      if (confirm('Are you sure you want to delete this batch?')) {
        state.documentBatches = state.documentBatches.filter(batch => batch.id !== id);
        updateUI();
      }
    }

    function saveDocumentDetails() {
      if (state.currentDocumentIndex === null) return;
      
      const paperSize = Array.from(elements.paperSizeRadios).find(radio => radio.checked)?.value || 'A4';
      const documentType = elements.documentType.value;
      const notes = elements.documentNotes.value;
      
      updateDocumentDetails(state.currentDocumentIndex, {
        paperSize: paperSize,
        documentType: documentType,
        notes: notes
      });
      
      state.showDocumentDetails = false;
      updateUI();
    }

    // Initialize the page
    async function init() {
      // Set up event listeners
      
      // Tab switching
      elements.tabs.forEach(tab => {
        tab.addEventListener('click', () => {
          const tabId = tab.getAttribute('data-tab');
          switchTab(tabId);
        });
      });
      
      // File upload
      elements.fileUpload.addEventListener('change', handleFileSelect);
      elements.browseFilesBtn.addEventListener('click', () => elements.fileUpload.click());
      
      // PDF Conversion checkbox
      if (elements.convertPdfs) {
        elements.convertPdfs.addEventListener('change', (e) => {
          state.pdfConversionEnabled = e.target.checked;
          if (state.selectedUploadFiles.length > 0) {
            detectFileTypes(state.selectedUploadFiles);
          }
        });
      }
      
      // Select file
      elements.selectFileBtn.addEventListener('click', () => {
        state.showFileSelector = true;
        updateUI();
      });
      
      // Upload actions
      elements.clearAllBtn.addEventListener('click', resetUpload);
      elements.startUploadBtn.addEventListener('click', startUpload);
      elements.cancelUploadBtn.addEventListener('click', resetUpload);
      elements.uploadMoreBtn.addEventListener('click', resetUpload);
      elements.viewUploadedBtn.addEventListener('click', () => switchTab('uploaded-files'));
      elements.proceedToTypingBtn.addEventListener('click', sendToPageTyping);
      elements.goToUploadBtn.addEventListener('click', () => switchTab('upload'));
      
      // Search functionality
      elements.fileSearch.addEventListener('input', (e) => {
        state.searchQuery = e.target.value;
        updateUI();
      });
      
      // Preview dialog
      elements.previewDialog.addEventListener('click', e => {
        if (e.target === elements.previewDialog) {
          closePreview();
        }
      });
      elements.prevPageBtn.addEventListener('click', prevPage);
      elements.nextPageBtn.addEventListener('click', nextPage);
      elements.zoomInBtn.addEventListener('click', zoomIn);
      elements.zoomOutBtn.addEventListener('click', zoomOut);
      elements.rotateBtn.addEventListener('click', rotate);
      elements.proceedToTypingFromPreviewBtn.addEventListener('click', () => {
        if (state.selectedFile) {
          window.location.href = `/file-digital-registry/page-typing?fileId=${state.selectedFile}`;
        }
      });
      
      // File selector dialog
      elements.fileSelectorDialog.addEventListener('click', e => {
        if (e.target === elements.fileSelectorDialog) {
          state.showFileSelector = false;
          updateUI();
        }
      });
      elements.cancelFileSelectBtn.addEventListener('click', () => {
        state.showFileSelector = false;
        updateUI();
      });
      elements.confirmFileSelectBtn.addEventListener('click', selectIndexedFile);
      
      // Document details dialog
      elements.documentDetailsDialog.addEventListener('click', e => {
        if (e.target === elements.documentDetailsDialog) {
          state.showDocumentDetails = false;
          updateUI();
        }
      });
      elements.cancelDetailsBtn.addEventListener('click', () => {
        state.showDocumentDetails = false;
        updateUI();
      });
      elements.saveDetailsBtn.addEventListener('click', saveDocumentDetails);
      
      // Toggle view
      elements.toggleViewBtn.addEventListener('click', () => {
        state.showFolderView = !state.showFolderView;
        updateUI();
      });
      
      // Paper size filter
      elements.paperSizeFilter.addEventListener('change', () => {
        state.filterPaperSize = elements.paperSizeFilter.value;
        updateUI();
      });
      
      // Test server configuration first
      await testServerConfig();
      
      // Load data from server
      await loadServerData();
      
      // Initial UI update
      updateUI();
      
      // Render indexed files
      renderIndexedFiles();
      
      console.log('Application initialized successfully');
    }
    
    // Initialize the page when DOM is loaded
    document.addEventListener('DOMContentLoaded', init);
  </script>
</body>
</html>