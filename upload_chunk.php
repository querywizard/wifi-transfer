<?php
// Chunked, resumable upload endpoint
// - POST with fields: fileId, fileName, totalChunks, chunkIndex, chunk
// - GET  ?fileId=...               -> returns {have:[indices...]}
// - GET  ?finalize=1&fileId=...&fileName=...&totalChunks=N -> assembles parts
//
// Make sure server limits are raised (mod_fcgid + php.ini):
//   FcgidMaxRequestLen 10G, upload_max_filesize/post_max_size >= file size.

header('Content-Type: application/json');

$baseDir = __DIR__;
$tmpRoot = $baseDir . DIRECTORY_SEPARATOR . 'uploads_tmp';
$finalRoot = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($tmpRoot)) @mkdir($tmpRoot, 0777, true);
if (!is_dir($finalRoot)) @mkdir($finalRoot, 0777, true);

function sanitizeName($name) {
    $name = preg_replace('/[^\w\-.]+/u', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'upload_' . date('Ymd_His');
    }
    return $name;
}

try {
    // Query: status of existing chunks
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fileId']) && !isset($_GET['finalize'])) {
        $fileId = $_GET['fileId'];
        $dir = $GLOBALS['tmpRoot'] . DIRECTORY_SEPARATOR . $fileId;
        $have = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if (preg_match('/\.part(\d+)$/', $f, $m)) $have[] = intval($m[1]);
            }
        }
        echo json_encode(['ok' => true, 'have' => $have]);
        exit;
    }

    // Finalize: assemble all parts
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['finalize'])) {
        $fileId = $_GET['fileId'] ?? '';
        $fileName = sanitizeName($_GET['fileName'] ?? 'upload_' . date('Ymd_His'));
        $total = intval($_GET['totalChunks'] ?? -1);
        if ($fileId === '' || $total < 1) throw new RuntimeException('Invalid finalize params.');

        $dir = $tmpRoot . DIRECTORY_SEPARATOR . $fileId;
        if (!is_dir($dir)) throw new RuntimeException('No temp directory for file.');

        // Verify we have all parts
        for ($i = 0; $i < $total; $i++) {
            if (!file_exists($dir . DIRECTORY_SEPARATOR . $fileName . ".part{$i}")) {
                throw new RuntimeException("Missing chunk {$i}.");
            }
        }

        // Avoid collisions
        $finalName = sanitizeName($fileName);
        $finalPath = $finalRoot . DIRECTORY_SEPARATOR . $finalName;
        $pi = pathinfo($finalName);
        $i = 1;
        while (file_exists($finalPath)) {
            $finalName = $pi['filename'] . "_$i" . (isset($pi['extension']) ? '.' . $pi['extension'] : '');
            $finalPath = $finalRoot . DIRECTORY_SEPARATOR . $finalName;
            $i++;
        }

        // Concatenate
        $out = fopen($finalPath, 'wb');
        if (!$out) throw new RuntimeException('Cannot create final file.');
        for ($i = 0; $i < $total; $i++) {
            $part = $dir . DIRECTORY_SEPARATOR . $fileName . ".part{$i}";
            $in = fopen($part, 'rb');
            if (!$in) { fclose($out); throw new RuntimeException("Cannot open chunk {$i}."); }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        // Cleanup temp parts
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            @unlink($dir . DIRECTORY_SEPARATOR . $f);
        }
        @rmdir($dir);

        echo json_encode(['ok' => true, 'name' => $finalName, 'url' => 'uploads/' . rawurlencode($finalName)]);
        exit;
    }

    // Upload a chunk
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    $fileId = $_POST['fileId'] ?? '';
    $fileName = sanitizeName($_POST['fileName'] ?? '');
    $totalChunks = intval($_POST['totalChunks'] ?? -1);
    $chunkIndex = intval($_POST['chunkIndex'] ?? -1);

    if ($fileId === '' || $fileName === '' || $totalChunks < 1 || $chunkIndex < 0) {
        throw new RuntimeException('Missing parameters.');
    }
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['chunk']['error'] ?? -1;
        throw new RuntimeException('Chunk upload error code: ' . $err);
    }

    $dir = $tmpRoot . DIRECTORY_SEPARATOR . $fileId;
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temp directory.');
    }

    $target = $dir . DIRECTORY_SEPARATOR . $fileName . ".part{$chunkIndex}";
    if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $target)) {
        throw new RuntimeException('Failed to save chunk.');
    }

    echo json_encode(['ok' => true, 'received' => $chunkIndex]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
