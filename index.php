<?php

declare(strict_types=1);

mb_internal_encoding('UTF-8');

$videosDir = __DIR__ . DIRECTORY_SEPARATOR . 'videos';
$imagesDir = __DIR__ . DIRECTORY_SEPARATOR . 'images';

if (!is_dir($videosDir)) {
    mkdir($videosDir, 0775, true);
}

if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0775, true);
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sanitize_ref(?string $ref): string {
    $ref = trim((string)$ref);
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $ref) ?? '';
}

function upload_error_message(int $code): string {
    return match ($code) {
        UPLOAD_ERR_OK => '',
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件太大',
        UPLOAD_ERR_PARTIAL => '文件只上传了一部分',
        UPLOAD_ERR_NO_FILE => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
        UPLOAD_ERR_EXTENSION => '上传被扩展中断',
        default => '未知上传错误',
    };
}

function is_ajax_request(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function json_response(array $data, int $statusCode = 200): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function detect_upload_kind(string $originalName, string $tmpName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpName) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    $imageExtMap = [
        'jpg' => 'jpg',
        'jpeg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
    ];

    if ($ext === 'mp4' && ($mime === 'video/mp4' || $mime === 'application/mp4')) {
        return [
            'ok' => true,
            'kind' => 'video',
            'ext' => 'mp4',
            'dir' => 'videos',
            'message' => '',
        ];
    }

    if (isset($imageExtMap[$ext]) && is_string($mime) && str_starts_with($mime, 'image/')) {
        return [
            'ok' => true,
            'kind' => 'image',
            'ext' => $imageExtMap[$ext],
            'dir' => 'images',
            'message' => '',
        ];
    }

    return [
        'ok' => false,
        'kind' => '',
        'ext' => '',
        'dir' => '',
        'message' => '目前支持 mp4、jpg、jpeg、png、webp',
    ];
}

function get_media_list(string $videosDir, string $imagesDir): array {
    $items = [];

    if (is_dir($videosDir)) {
        $videoFiles = glob($videosDir . DIRECTORY_SEPARATOR . '*.mp4') ?: [];
        foreach ($videoFiles as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $filename = basename($filePath);
            $ref = sanitize_ref(pathinfo($filename, PATHINFO_FILENAME));
            if ($ref === '') {
                continue;
            }

            $items[] = [
                'ref' => $ref,
                'name' => pathinfo($filename, PATHINFO_FILENAME),
                'url' => 'videos/' . rawurlencode($ref) . '.mp4',
                'mtime' => filemtime($filePath) ?: 0,
                'type' => 'video',
                'label' => '视频',
            ];
        }
    }

    if (is_dir($imagesDir)) {
        $patterns = ['*.jpg', '*.jpeg', '*.png', '*.webp', '*.JPG', '*.JPEG', '*.PNG', '*.WEBP'];
        $imageFiles = [];
        foreach ($patterns as $pattern) {
            $imageFiles = array_merge($imageFiles, glob($imagesDir . DIRECTORY_SEPARATOR . $pattern) ?: []);
        }

        foreach ($imageFiles as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $filename = basename($filePath);
            $rawName = pathinfo($filename, PATHINFO_FILENAME);
            $ref = sanitize_ref($rawName);
            if ($ref === '') {
                continue;
            }

            $realExt = pathinfo($filename, PATHINFO_EXTENSION);
            $items[] = [
                'ref' => $ref,
                'name' => $rawName,
                'url' => 'images/' . rawurlencode($filename),
                'mtime' => filemtime($filePath) ?: 0,
                'type' => 'image',
                'label' => '照片',
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        return $b['mtime'] <=> $a['mtime'];
    });

    $deduped = [];
    $seen = [];
    foreach ($items as $item) {
        $key = $item['type'] . ':' . $item['ref'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $item;
    }

    return $deduped;
}

$message = '';
$isAjax = is_ajax_request();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['media'])) {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            $message = '上传失败，通常是文件超过了服务器请求体限制';
        } else {
            $message = '没有收到文件';
        }
    } else {
        $file = $_FILES['media'];

        if (!is_array($file)) {
            $message = '上传数据格式不正确';
        } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message = upload_error_message((int)$file['error']);
        } else {
            $tmpName = (string)$file['tmp_name'];
            $originalName = (string)$file['name'];
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $postRef = sanitize_ref($baseName);

            if ($postRef === '') {
                $message = '文件名无效，无法生成 ref';
            } else {
                $detected = detect_upload_kind($originalName, $tmpName);
                if (!$detected['ok']) {
                    $message = (string)$detected['message'];
                } else {
                    $targetBaseDir = $detected['dir'] === 'videos' ? $videosDir : $imagesDir;
                    $targetPath = $targetBaseDir . DIRECTORY_SEPARATOR . $postRef . '.' . $detected['ext'];

                    if (!move_uploaded_file($tmpName, $targetPath)) {
                        $message = '保存文件失败，请检查目录权限';
                    } else {
                        $redirect = '?ref=' . rawurlencode($postRef) . '&type=' . rawurlencode((string)$detected['kind']) . '&uploaded=1';

                        if ($isAjax) {
                            json_response([
                                'ok' => true,
                                'ref' => $postRef,
                                'type' => $detected['kind'],
                                'redirect' => $redirect,
                            ]);
                        }

                        header('Location: ' . $redirect);
                        exit;
                    }
                }
            }
        }
    }

    if ($isAjax) {
        json_response([
            'ok' => false,
            'message' => $message !== '' ? $message : '上传失败',
        ], 400);
    }
}

$mediaList = get_media_list($videosDir, $imagesDir);
$videoItems = array_values(array_filter($mediaList, static fn(array $item): bool => $item['type'] === 'video'));
$imageItems = array_values(array_filter($mediaList, static fn(array $item): bool => $item['type'] === 'image'));

$currentRef = sanitize_ref((string)(filter_input(INPUT_GET, 'ref') ?? ''));
$currentType = trim((string)(filter_input(INPUT_GET, 'type') ?? ''));
$uploaded = filter_input(INPUT_GET, 'uploaded');

if ($currentRef === '' && !empty($mediaList)) {
    $currentRef = $mediaList[0]['ref'];
    $currentType = $mediaList[0]['type'];
}

$currentItem = null;
if ($currentRef !== '') {
    foreach ($mediaList as $item) {
        if ($item['ref'] === $currentRef && ($currentType === '' || $item['type'] === $currentType)) {
            $currentItem = $item;
            break;
        }
    }

    if ($currentItem === null) {
        foreach ($mediaList as $item) {
            if ($item['ref'] === $currentRef) {
                $currentItem = $item;
                break;
            }
        }
    }
}

if ($currentItem === null && $currentRef !== '') {
    $message = '没有找到这个资源';
}

$mediaUrl = $currentItem['url'] ?? '';
$currentMediaName = $currentItem['name'] ?? '';
$currentMediaType = $currentItem['type'] ?? '';

$shareUrl = '';
if ($currentRef !== '' && $mediaUrl !== '' && $currentMediaType !== '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?') ?: '/index.php';
    $shareUrl = $scheme . '://' . $host . $script . '?ref=' . rawurlencode($currentRef) . '&type=' . rawurlencode($currentMediaType);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>全景媒体浏览</title>
  <script src="https://aframe.io/releases/1.7.0/aframe.min.js"></script>
  <script>
    AFRAME.registerComponent('zoom-controls', {
      schema: {
        defaultFov: { type: 'number', default: 86 },
        minFov: { type: 'number', default: 55 },
        maxFov: { type: 'number', default: 110 },
        wheelStep: { type: 'number', default: 3 },
        pinchStep: { type: 'number', default: 0.10 }
      },
      init: function () {
        this.camera = null;
        this.lastPinchDistance = 0;
        this.bound = false;
        this.onWheel = this.onWheel.bind(this);
        this.onTouchStart = this.onTouchStart.bind(this);
        this.onTouchMove = this.onTouchMove.bind(this);
        this.onTouchEnd = this.onTouchEnd.bind(this);
        this.trySetup = this.trySetup.bind(this);
        this.onObject3DSet = this.onObject3DSet.bind(this);
        this.trySetup();
        this.el.addEventListener('object3dset', this.onObject3DSet);
      },
      onObject3DSet: function (e) {
        if (e.detail && e.detail.type === 'camera') {
          this.trySetup();
        }
      },
      trySetup: function () {
        if (this.bound) return;
        const cameraComponent = this.el.components.camera;
        this.camera = cameraComponent && cameraComponent.camera
          ? cameraComponent.camera
          : this.el.getObject3D('camera');
        if (!this.camera) return;
        this.setFov(this.data.defaultFov);
        window.addEventListener('wheel', this.onWheel, { passive: false });
        window.addEventListener('touchstart', this.onTouchStart, { passive: false });
        window.addEventListener('touchmove', this.onTouchMove, { passive: false });
        window.addEventListener('touchend', this.onTouchEnd, { passive: false });
        window.addEventListener('touchcancel', this.onTouchEnd, { passive: false });
        this.bound = true;
      },
      clampFov: function (fov) {
        return Math.min(this.data.maxFov, Math.max(this.data.minFov, fov));
      },
      reset: function () {
        this.setFov(this.data.defaultFov);
      },
      setFov: function (fov) {
        if (!this.camera) return;
        this.camera.fov = this.clampFov(fov);
        this.camera.zoom = 1;
        this.camera.updateProjectionMatrix();
      },
      onWheel: function (e) {
        if (!this.camera) return;
        e.preventDefault();
        const direction = Math.sign(e.deltaY);
        if (direction === 0) return;
        this.setFov(this.camera.fov + direction * this.data.wheelStep);
      },
      getTouchDistance: function (touches) {
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.hypot(dx, dy);
      },
      onTouchStart: function (e) {
        if (e.touches.length === 2) {
          this.lastPinchDistance = this.getTouchDistance(e.touches);
        }
      },
      onTouchMove: function (e) {
        if (!this.camera || e.touches.length !== 2) return;
        e.preventDefault();
        const currentDistance = this.getTouchDistance(e.touches);
        if (!this.lastPinchDistance) {
          this.lastPinchDistance = currentDistance;
          return;
        }
        const distanceDelta = currentDistance - this.lastPinchDistance;
        this.setFov(this.camera.fov - distanceDelta * this.data.pinchStep);
        this.lastPinchDistance = currentDistance;
      },
      onTouchEnd: function (e) {
        if (e.touches.length < 2) {
          this.lastPinchDistance = 0;
        }
      },
      remove: function () {
        this.el.removeEventListener('object3dset', this.onObject3DSet);
        window.removeEventListener('wheel', this.onWheel);
        window.removeEventListener('touchstart', this.onTouchStart);
        window.removeEventListener('touchmove', this.onTouchMove);
        window.removeEventListener('touchend', this.onTouchEnd);
        window.removeEventListener('touchcancel', this.onTouchEnd);
        this.bound = false;
      }
    });
  </script>
  <style>
    :root {
      --sidebar-width: 320px;
      --panel-bg: rgba(0, 0, 0, 0.72);
      --panel-border: rgba(255, 255, 255, 0.12);
      --text-main: #ffffff;
      --text-sub: #cfe0ff;
      --danger: #ffd6d6;
      --success: #d8ffd8;
    }

    html, body {
      margin: 0;
      height: 100%;
      background: #000;
      overflow: hidden;
      font-family: sans-serif;
      color: var(--text-main);
    }

    a-scene,
    canvas.a-canvas {
      touch-action: none;
      position: fixed !important;
      inset: 0;
      z-index: 1;
    }

    .sidebar {
      position: fixed;
      top: 0;
      left: 0;
      bottom: 0;
      width: var(--sidebar-width);
      z-index: 50;
      display: flex;
      flex-direction: column;
      background: rgba(8, 12, 18, 0.92);
      border-right: 1px solid var(--panel-border);
      backdrop-filter: blur(10px);
      box-sizing: border-box;
      overflow: hidden;
      transform: translateX(0);
      transition: transform 0.25s ease;
    }

    body.sidebar-collapsed .sidebar {
      transform: translateX(calc(-1 * var(--sidebar-width)));
    }

    .sidebarToggle {
      position: fixed;
      top: 16px;
      left: calc(var(--sidebar-width) + 16px);
      z-index: 60;
      width: 42px;
      height: 42px;
      border: 0;
      border-radius: 999px;
      cursor: pointer;
      background: rgba(0, 0, 0, 0.78);
      color: #fff;
      font-size: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(8px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.28);
      transition: left 0.25s ease, background 0.2s ease;
    }

    body.sidebar-collapsed .sidebarToggle {
      left: 16px;
    }

    body.sidebar-collapsed .sidebarToggle .toggleOpen {
      display: inline;
    }

    body.sidebar-collapsed .sidebarToggle .toggleClose {
      display: none;
    }

    body:not(.sidebar-collapsed) .sidebarToggle .toggleOpen {
      display: none;
    }

    body:not(.sidebar-collapsed) .sidebarToggle .toggleClose {
      display: inline;
    }

    .sidebarHeader {
      padding: 18px 16px 14px;
      border-bottom: 1px solid var(--panel-border);
      flex: 0 0 auto;
    }

    .sidebarTitle {
      font-size: 18px;
      font-weight: 700;
      margin: 0 0 6px;
    }

    .sidebarDesc {
      font-size: 13px;
      line-height: 1.5;
      color: #b8c4d6;
      margin: 0;
    }

    .sidebarSection {
      padding: 14px 16px;
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
      flex: 0 0 auto;
    }

    .sidebarListWrap {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
      overflow-x: hidden;
      padding: 12px;
      box-sizing: border-box;
    }

    .sidebarListTitle {
      font-size: 13px;
      color: #8fa5c5;
      margin: 0 0 10px;
    }

    .sidebarTabs {
      display: flex;
      gap: 8px;
      margin-bottom: 12px;
    }

    .sidebarTab {
      flex: 1;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.04);
      color: #dce8ff;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 13px;
      cursor: pointer;
    }

    .sidebarTab.active {
      background: rgba(77, 163, 255, 0.18);
      border-color: rgba(77, 163, 255, 0.55);
      color: #fff;
    }

    .sidebarPanel {
      display: none;
    }

    .sidebarPanel.active {
      display: block;
    }

    .sidebarList {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .sidebarItem {
      display: block;
      width: 100%;
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.04);
      color: #fff;
      text-decoration: none;
      padding: 12px 14px;
      box-sizing: border-box;
      transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }

    .sidebarItem.active {
      background: rgba(77, 163, 255, 0.18);
      border-color: rgba(77, 163, 255, 0.55);
    }

    .sidebarItemName {
      display: block;
      font-size: 14px;
      line-height: 1.45;
      word-break: break-word;
    }

    .sidebarItemMeta {
      display: block;
      margin-top: 6px;
      font-size: 12px;
      color: #9fb3d1;
    }

    .emptyText,
    .hint,
    .msg,
    .share,
    .fileName {
      font-size: 14px;
      line-height: 1.5;
      word-break: break-all;
    }

    .fileName {
      color: #d5e8ff;
      margin-top: 4px;
    }

    .emptyText {
      color: #b8c4d6;
      padding: 4px 2px;
    }

    .progressWrap {
      display: none;
      margin-top: 10px;
    }

    .progressBar {
      width: 100%;
      height: 14px;
      appearance: none;
      -webkit-appearance: none;
    }

    .progressText {
      margin-top: 6px;
      font-size: 14px;
      color: #fff;
    }

    .inputFile,
    .btn {
      border: 0;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 14px;
      box-sizing: border-box;
    }

    .inputFile {
      flex: 1;
      min-width: 220px;
      background: #fff;
      color: #111;
    }

    .btn {
      cursor: pointer;
      background: #fff;
      color: #111;
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    .row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 10px;
      align-items: center;
    }

    .panel {
      position: fixed;
      top: 16px;
      right: 16px;
      z-index: 40;
      width: min(560px, calc(100vw - 32px));
      background: var(--panel-bg);
      color: #fff;
      padding: 14px;
      border-radius: 12px;
      backdrop-filter: blur(8px);
      box-sizing: border-box;
      border: 1px solid var(--panel-border);
    }

    .msg {
      color: var(--danger);
    }

    .ok {
      color: var(--success);
    }

    #enterBtn {
      position: fixed;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      z-index: 20;
      padding: 12px 18px;
      border: 0;
      border-radius: 10px;
      font-size: 16px;
      cursor: pointer;
      display: none;
    }

    #resetZoomBtn {
      position: fixed;
      left: 50%;
      bottom: 16px;
      transform: translateX(-50%);
      z-index: 30;
      padding: 10px 14px;
      border: 0;
      border-radius: 999px;
      background: rgba(0, 0, 0, 0.58);
      color: #fff;
      font-size: 14px;
      cursor: pointer;
      backdrop-filter: blur(8px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
    }

    @media (max-width: 900px) {
      :root {
        --sidebar-width: min(82vw, 300px);
      }

      .panel {
        width: calc(100vw - 32px);
      }
    }
  </style>
</head>
<body>
  <aside id="sidebar" class="sidebar" aria-label="媒体侧边栏">
    <div class="sidebarHeader">
      <h2 class="sidebarTitle">媒体列表</h2>
      <p class="sidebarDesc">左侧侧边栏，支持视频和照片切换。</p>
    </div>

    <div class="sidebarSection">
      <form id="uploadForm" method="post" enctype="multipart/form-data">
        <div class="row">
          <input id="mediaInput" class="inputFile" type="file" name="media" accept="video/mp4,image/jpeg,image/png,image/webp" required>
          <button id="uploadBtn" class="btn" type="submit">上传媒体</button>
        </div>
        <div id="selectedFileName" class="fileName">当前未选择文件</div>
        <div id="progressWrap" class="progressWrap">
          <progress id="uploadProgress" class="progressBar" value="0" max="100"></progress>
          <div id="uploadStatus" class="progressText">准备上传</div>
        </div>
      </form>
    </div>

    <div class="sidebarListWrap">
      <div class="sidebarListTitle">共 <?= count($mediaList) ?> 个资源</div>

      <div class="sidebarTabs" role="tablist" aria-label="媒体类型切换">
        <button id="videoTab" class="sidebarTab <?= $currentMediaType !== 'image' ? 'active' : '' ?>" type="button" role="tab" aria-controls="videoPanel" data-target="videoPanel">全景视频 <?= count($videoItems) ?></button>
        <button id="imageTab" class="sidebarTab <?= $currentMediaType === 'image' ? 'active' : '' ?>" type="button" role="tab" aria-controls="imagePanel" data-target="imagePanel">全景照片 <?= count($imageItems) ?></button>
      </div>

      <div id="videoPanel" class="sidebarPanel <?= $currentMediaType !== 'image' ? 'active' : '' ?>" role="tabpanel">
        <div class="sidebarList">
          <?php if (!empty($videoItems)): ?>
            <?php foreach ($videoItems as $item): ?>
              <a
                class="sidebarItem <?= $item['ref'] === $currentRef && $item['type'] === $currentMediaType ? 'active' : '' ?>"
                href="?ref=<?= rawurlencode($item['ref']) ?>&type=<?= rawurlencode($item['type']) ?>"
                data-ref="<?= h($item['ref']) ?>"
                data-type="<?= h($item['type']) ?>"
                data-media-url="<?= h($item['url']) ?>"
                data-media-name="<?= h($item['name']) ?>"
              >
                <span class="sidebarItemName"><?= h($item['name']) ?></span>
                <span class="sidebarItemMeta">视频 · ref: <?= h($item['ref']) ?></span>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="emptyText">还没有全景视频</div>
          <?php endif; ?>
        </div>
      </div>

      <div id="imagePanel" class="sidebarPanel <?= $currentMediaType === 'image' ? 'active' : '' ?>" role="tabpanel">
        <div class="sidebarList">
          <?php if (!empty($imageItems)): ?>
            <?php foreach ($imageItems as $item): ?>
              <a
                class="sidebarItem <?= $item['ref'] === $currentRef && $item['type'] === $currentMediaType ? 'active' : '' ?>"
                href="?ref=<?= rawurlencode($item['ref']) ?>&type=<?= rawurlencode($item['type']) ?>"
                data-ref="<?= h($item['ref']) ?>"
                data-type="<?= h($item['type']) ?>"
                data-media-url="<?= h($item['url']) ?>"
                data-media-name="<?= h($item['name']) ?>"
              >
                <span class="sidebarItemName"><?= h($item['name']) ?></span>
                <span class="sidebarItemMeta">照片 · ref: <?= h($item['ref']) ?></span>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="emptyText">还没有全景照片</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </aside>

  <button id="sidebarToggle" class="sidebarToggle" type="button" aria-label="切换侧边栏" aria-expanded="true">
    <span class="toggleClose">✕</span>
    <span class="toggleOpen">☰</span>
  </button>

  <?php if ($message !== ''): ?>
    <div class="panel">
      <div class="msg"><?= h($message) ?></div>
    </div>
  <?php elseif ($uploaded && $shareUrl): ?>
    <div class="panel">
      <div class="share ok">上传成功，分享链接：<a style="color:#9ad1ff" href="<?= h($shareUrl) ?>"><?= h($shareUrl) ?></a></div>
    </div>
  <?php endif; ?>

  <button id="resetZoomBtn" class="btn" type="button">复原缩放</button>
  <button id="enterBtn">进入全景内容</button>

  <a-scene
    embedded
    renderer="antialias: auto; colorManagement: true; precision: high; maxCanvasWidth: 1920; maxCanvasHeight: 1920"
  >
    <a-assets>
      <video
        id="panoVideo"
        <?= $currentMediaType === 'video' ? 'src="' . h($mediaUrl) . '"' : '' ?>
        preload="auto"
        loop
        controls
        crossorigin="anonymous"
        playsinline
        webkit-playsinline
        x5-video-player-type="h5"
        x5-video-player-fullscreen="false"
      ></video>

      <img
        id="panoImage"
        <?= $currentMediaType === 'image' ? 'src="' . h($mediaUrl) . '"' : '' ?>
        crossorigin="anonymous"
        alt="全景照片"
      >
    </a-assets>

    <a-sky
      id="panoSky"
      <?= $currentMediaType === 'image' ? 'src="#panoImage"' : 'visible="false"' ?>
      rotation="0 -90 0"
      radius="5000"
    ></a-sky>

    <a-videosphere
      id="videoSphere"
      <?= $currentMediaType === 'video' ? 'src="#panoVideo"' : 'visible="false"' ?>
      rotation="0 -90 0"
      radius="5000"
      segments-width="64"
      segments-height="64"
    ></a-videosphere>

    <a-camera
      id="mainCamera"
      camera="fov: 88; near: 0.1; far: 6000"
      look-controls="mouseEnabled: true; touchEnabled: true; reverseMouseDrag: true; reverseTouchDrag: false"
      zoom-controls="defaultFov: 88; minFov: 55; maxFov: 112; wheelStep: 3; pinchStep: 0.12"
    ></a-camera>
  </a-scene>

  <script>
    const uploadForm = document.getElementById('uploadForm');
    const mediaInput = document.getElementById('mediaInput');
    const uploadBtn = document.getElementById('uploadBtn');
    const selectedFileName = document.getElementById('selectedFileName');
    const progressWrap = document.getElementById('progressWrap');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadStatus = document.getElementById('uploadStatus');
    const enterBtn = document.getElementById('enterBtn');
    const video = document.getElementById('panoVideo');
    const image = document.getElementById('panoImage');
    const videoSphere = document.getElementById('videoSphere');
    const panoSky = document.getElementById('panoSky');
    const resetZoomBtn = document.getElementById('resetZoomBtn');
    const mainCameraEl = document.getElementById('mainCamera');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarItems = Array.from(document.querySelectorAll('.sidebarItem'));
    const sidebarTabs = Array.from(document.querySelectorAll('.sidebarTab'));
    const sidebarPanels = Array.from(document.querySelectorAll('.sidebarPanel'));

    const params = new URLSearchParams(window.location.search);
    const refFromUrl = params.get('ref');
    const typeFromUrl = params.get('type');
    let currentType = <?= json_encode($currentMediaType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    if (mediaInput && selectedFileName) {
      mediaInput.addEventListener('change', () => {
        const file = mediaInput.files && mediaInput.files[0];
        selectedFileName.textContent = file ? `已选择文件：${file.name}` : '当前未选择文件';
      });
    }

    if (uploadForm) {
      uploadForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const file = mediaInput.files && mediaInput.files[0];
        if (!file) {
          if (uploadStatus && progressWrap) {
            uploadStatus.textContent = '请先选择文件';
            progressWrap.style.display = 'block';
          }
          return;
        }

        const formData = new FormData(uploadForm);
        const xhr = new XMLHttpRequest();

        if (uploadBtn) uploadBtn.disabled = true;
        if (progressWrap) progressWrap.style.display = 'block';
        if (uploadProgress) uploadProgress.value = 0;
        if (uploadStatus) uploadStatus.textContent = '开始上传...';

        xhr.open('POST', window.location.pathname + window.location.search, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', function (e) {
          if (!uploadStatus) return;
          if (!e.lengthComputable) {
            uploadStatus.textContent = '正在上传...';
            return;
          }
          const percent = Math.round((e.loaded / e.total) * 100);
          if (uploadProgress) uploadProgress.value = percent;
          uploadStatus.textContent = `正在上传 ${percent}%`;
        });

        xhr.addEventListener('load', function () {
          if (uploadBtn) uploadBtn.disabled = false;
          try {
            const data = JSON.parse(xhr.responseText || '{}');
            if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
              if (uploadProgress) uploadProgress.value = 100;
              if (uploadStatus) uploadStatus.textContent = '上传完成，正在跳转...';
              window.location.href = data.redirect;
            } else if (uploadStatus) {
              uploadStatus.textContent = data.message || '上传失败';
            }
          } catch (err) {
            console.error(err);
            if (uploadStatus) uploadStatus.textContent = '服务器返回格式错误';
          }
        });

        xhr.addEventListener('error', function () {
          if (uploadBtn) uploadBtn.disabled = false;
          if (uploadStatus) uploadStatus.textContent = '网络错误，上传失败';
        });

        xhr.addEventListener('abort', function () {
          if (uploadBtn) uploadBtn.disabled = false;
          if (uploadStatus) uploadStatus.textContent = '上传已取消';
        });

        xhr.send(formData);
      });
    }

    function setActiveTab(type) {
      const targetId = type === 'image' ? 'imagePanel' : 'videoPanel';
      sidebarTabs.forEach(tab => {
        const isActive = tab.dataset.target === targetId;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });
      sidebarPanels.forEach(panel => {
        panel.classList.toggle('active', panel.id === targetId);
      });
    }

    async function safePlay() {
      if (currentType !== 'video') {
        enterBtn.style.display = 'none';
        return true;
      }
      if (!video || !video.getAttribute('src')) return false;
      try {
        await video.play();
        enterBtn.style.display = 'none';
        return true;
      } catch (e) {
        console.error(e);
        return false;
      }
    }

    function applyMediaType(type, mediaUrl) {
      currentType = type;
      if (type === 'image') {
        if (video) {
          video.pause();
          video.removeAttribute('src');
          video.load();
        }
        if (image) {
          image.setAttribute('src', mediaUrl);
        }
        if (videoSphere) {
          videoSphere.setAttribute('visible', 'false');
          videoSphere.removeAttribute('src');
        }
        if (panoSky) {
          panoSky.setAttribute('visible', 'true');
          panoSky.setAttribute('src', '#panoImage');
        }
        enterBtn.style.display = 'none';
      } else {
        if (image) {
          image.removeAttribute('src');
        }
        if (panoSky) {
          panoSky.setAttribute('visible', 'false');
          panoSky.removeAttribute('src');
        }
        if (video) {
          video.setAttribute('src', mediaUrl);
          video.load();
        }
        if (videoSphere) {
          videoSphere.setAttribute('visible', 'true');
          videoSphere.setAttribute('src', '#panoVideo');
        }
      }
      setActiveTab(type);
    }

    async function switchMedia(ref, type, mediaUrl, mediaName, pushState = true) {
      if (!mediaUrl) return;
      const wasPaused = video ? video.paused : true;
      applyMediaType(type, mediaUrl);

      sidebarItems.forEach(item => {
        item.classList.toggle('active', item.dataset.ref === ref && item.dataset.type === type);
      });

      if (pushState) {
        history.pushState({ ref, type }, '', `?ref=${encodeURIComponent(ref)}&type=${encodeURIComponent(type)}`);
      }

      if (type === 'video' && (!wasPaused || refFromUrl || typeFromUrl || document.visibilityState === 'visible')) {
        const ok = await safePlay();
        if (!ok) {
          enterBtn.style.display = 'block';
        }
      }
    }

    if (sidebarToggle) {
      const setSidebarCollapsed = (collapsed) => {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      };

      sidebarToggle.addEventListener('click', () => {
        const collapsed = !document.body.classList.contains('sidebar-collapsed');
        setSidebarCollapsed(collapsed);
      });

      setSidebarCollapsed(false);
    }

    sidebarTabs.forEach(tab => {
      tab.addEventListener('click', () => {
        const type = tab.dataset.target === 'imagePanel' ? 'image' : 'video';
        setActiveTab(type);
      });
    });

    sidebarItems.forEach(item => {
      item.addEventListener('click', async (e) => {
        e.preventDefault();
        const { ref, type, mediaUrl, mediaName } = item.dataset;
        await switchMedia(ref, type, mediaUrl, mediaName, true);
      });
    });

    enterBtn.addEventListener('click', async () => {
      const ok = await safePlay();
      if (!ok) {
        enterBtn.style.display = 'block';
      }
    });

    resetZoomBtn.addEventListener('click', () => {
      const zoomControls = mainCameraEl && mainCameraEl.components['zoom-controls'];
      if (zoomControls) {
        zoomControls.reset();
      }
    });

    window.addEventListener('popstate', async () => {
      const currentParams = new URLSearchParams(window.location.search);
      const ref = currentParams.get('ref');
      const type = currentParams.get('type');
      const matchedItem = sidebarItems.find(item => item.dataset.ref === ref && item.dataset.type === type)
        || sidebarItems.find(item => item.dataset.ref === ref);
      if (!matchedItem) return;
      const { mediaUrl, mediaName } = matchedItem.dataset;
      await switchMedia(ref, matchedItem.dataset.type, mediaUrl, mediaName, false);
    });

    async function initPlayback() {
      setActiveTab(currentType === 'image' ? 'image' : 'video');
      if (currentType === 'image') {
        enterBtn.style.display = 'none';
        return;
      }
      if (!video || !video.getAttribute('src')) {
        enterBtn.style.display = 'none';
        return;
      }
      const ok = await safePlay();
      if (!ok) {
        enterBtn.style.display = 'block';
      }
    }

    window.addEventListener('load', initPlayback);
  </script>
</body>
</html>
