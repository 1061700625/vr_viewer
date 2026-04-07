<?php
declare(strict_types=1);

mb_internal_encoding('UTF-8');

$videosDir = __DIR__ . DIRECTORY_SEPARATOR . 'videos';
if (!is_dir($videosDir)) {
    mkdir($videosDir, 0775, true);
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

$message = '';
$isAjax = is_ajax_request();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['video'])) {
      $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
      if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
          $message = '上传失败，通常是文件超过了服务器请求体限制';
      } else {
          $message = '没有收到视频文件';
      }
  } else {
        $file = $_FILES['video'];

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
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                if ($ext !== 'mp4') {
                    $message = '目前只支持 mp4';
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_file($finfo, $tmpName) : false;
                    if ($finfo) {
                        finfo_close($finfo);
                    }

                    if ($mime !== 'video/mp4' && $mime !== 'application/mp4') {
                        $message = '文件类型不是有效的 mp4';
                    } else {
                        $targetPath = $videosDir . DIRECTORY_SEPARATOR . $postRef . '.mp4';

                        if (!move_uploaded_file($tmpName, $targetPath)) {
                            $message = '保存文件失败，请检查 videos 目录权限';
                        } else {
                            $redirect = '?ref=' . rawurlencode($postRef) . '&uploaded=1';

                            if ($isAjax) {
                                json_response([
                                    'ok' => true,
                                    'ref' => $postRef,
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
    }

    if ($isAjax) {
        json_response([
            'ok' => false,
            'message' => $message !== '' ? $message : '上传失败',
        ], 400);
    }
}

$currentRef = sanitize_ref((string)(filter_input(INPUT_GET, 'ref') ?? ''));
$uploaded = filter_input(INPUT_GET, 'uploaded');

$videoUrl = '';
if ($currentRef !== '') {
    $candidate = $videosDir . DIRECTORY_SEPARATOR . $currentRef . '.mp4';
    if (is_file($candidate)) {
        $videoUrl = 'videos/' . rawurlencode($currentRef) . '.mp4';
    } else {
        $message = '没有找到这个 ref 对应的视频';
    }
}

$shareUrl = '';
if ($currentRef !== '' && $videoUrl !== '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = strtok($_SERVER['REQUEST_URI'] ?? '/index.php', '?') ?: '/index.php';
    $shareUrl = $scheme . '://' . $host . $script . '?ref=' . rawurlencode($currentRef);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>全景视频</title>
  <script src="https://aframe.io/releases/1.7.0/aframe.min.js"></script>
  <script>
    AFRAME.registerComponent('zoom-controls', {
      schema: {
        minFov: {type: 'number', default: 10},
        maxFov: {type: 'number', default: 200},
        wheelStep: {type: 'number', default: 4},
        pinchStep: {type: 'number', default: 0.15}
      },

      init: function () {
        this.camera = null;
        this.lastPinchDistance = 0;
        this.onWheel = this.onWheel.bind(this);
        this.onTouchStart = this.onTouchStart.bind(this);
        this.onTouchMove = this.onTouchMove.bind(this);
        this.onTouchEnd = this.onTouchEnd.bind(this);
        this.setup = this.setup.bind(this);

        if (this.el.sceneEl.hasLoaded) {
          this.setup();
        } else {
          this.el.sceneEl.addEventListener('loaded', this.setup, {once: true});
        }
      },

      setup: function () {
        this.camera = this.el.getObject3D('camera');
        if (!this.camera) return;

        window.addEventListener('wheel', this.onWheel, {passive: false});
        window.addEventListener('touchstart', this.onTouchStart, {passive: false});
        window.addEventListener('touchmove', this.onTouchMove, {passive: false});
        window.addEventListener('touchend', this.onTouchEnd, {passive: false});
        window.addEventListener('touchcancel', this.onTouchEnd, {passive: false});
      },

      clampFov: function (fov) {
        return Math.min(this.data.maxFov, Math.max(this.data.minFov, fov));
      },

      reset: function () {
        this.setFov(80);
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
        const delta = e.deltaY > 0 ? this.data.wheelStep : -this.data.wheelStep;
        this.setFov(this.camera.fov + delta);
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
        window.removeEventListener('wheel', this.onWheel);
        window.removeEventListener('touchstart', this.onTouchStart);
        window.removeEventListener('touchmove', this.onTouchMove);
        window.removeEventListener('touchend', this.onTouchEnd);
        window.removeEventListener('touchcancel', this.onTouchEnd);
      }
    });
  </script>
  <style>
    html, body {
      margin: 0;
      height: 100%;
      background: #000;
      overflow: hidden;
      font-family: sans-serif;
    }

    a-scene,
    canvas.a-canvas {
      touch-action: none;
    }

    .panel {
      position: fixed;
      top: 16px;
      left: 16px;
      z-index: 20;
      width: min(560px, calc(100vw - 32px));
      background: rgba(0, 0, 0, 0.68);
      color: #fff;
      padding: 14px;
      border-radius: 12px;
      backdrop-filter: blur(8px);
      box-sizing: border-box;
    }

    .row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 10px;
      align-items: center;
    }

    .inputFile, .btn {
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

    .hint, .msg, .share, .fileName {
      font-size: 14px;
      line-height: 1.5;
      word-break: break-all;
    }

    .msg {
      color: #ffd6d6;
      margin-top: 8px;
    }

    .ok {
      color: #d8ffd8;
    }

    .fileName {
      color: #d5e8ff;
      margin-top: 4px;
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

    #enterBtn {
      position: fixed;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
      z-index: 10;
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

    #resetZoomBtn:hover {
      background: rgba(0, 0, 0, 0.72);
    }
  </style>
</head>
<body>
  <?php if ($currentRef === ''): ?>
    <div class="panel">
      <form id="uploadForm" method="post" enctype="multipart/form-data">
        <div class="row">
          <input id="videoInput" class="inputFile" type="file" name="video" accept="video/mp4" required>
          <button id="uploadBtn" class="btn" type="submit">上传并生成链接</button>
        </div>
        <div id="selectedFileName" class="fileName">当前未选择文件</div>

        <div id="progressWrap" class="progressWrap">
          <progress id="uploadProgress" class="progressBar" value="0" max="100"></progress>
          <div id="uploadStatus" class="progressText">准备上传</div>
        </div>
      </form>
      <div class="hint">
        上传 mp4 后会自动按文件名生成分享链接。桌面端可用鼠标滚轮缩放，手机端可双指捏合缩放
      </div>

      <?php if ($uploaded && $shareUrl): ?>
        <div class="share ok">上传成功，分享链接：<a style="color:#9ad1ff" href="<?= h($shareUrl) ?>"><?= h($shareUrl) ?></a></div>
      <?php endif; ?>

      <?php if ($message !== ''): ?>
        <div class="msg"><?= h($message) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <button id="resetZoomBtn" class="btn" type="button">复原缩放</button>

  <button id="enterBtn">进入全景视频</button>

  <a-scene embedded device-orientation-permission-ui>
    <a-assets>
      <video
        id="panoVideo"
        <?= $videoUrl ? 'src="' . h($videoUrl) . '"' : '' ?>
        preload="auto"
        loop
        controls
        crossorigin="anonymous"
        playsinline
        webkit-playsinline
      ></video>
    </a-assets>

    <a-videosphere src="#panoVideo" rotation="0 -90 0"></a-videosphere>
    <a-camera
      id="mainCamera"
      camera="fov: 80"
      look-controls="mouseEnabled: true; touchEnabled: true; magicWindowTrackingEnabled: true"
      zoom-controls="minFov: 30; maxFov: 100; wheelStep: 4; pinchStep: 0.15">
    </a-camera>
  </a-scene>

  <script>
    const uploadForm = document.getElementById('uploadForm');
    const videoInput = document.getElementById('videoInput');
    const uploadBtn = document.getElementById('uploadBtn');
    const selectedFileName = document.getElementById('selectedFileName');
    const progressWrap = document.getElementById('progressWrap');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadStatus = document.getElementById('uploadStatus');
    const enterBtn = document.getElementById('enterBtn');
    const video = document.getElementById('panoVideo');
    const resetZoomBtn = document.getElementById('resetZoomBtn');
    const mainCameraEl = document.getElementById('mainCamera');
    const defaultFov = 80;

    const params = new URLSearchParams(window.location.search);
    const refFromUrl = params.get('ref');

    videoInput.addEventListener('change', () => {
      const file = videoInput.files && videoInput.files[0];
      selectedFileName.textContent = file ? `已选择文件：${file.name}` : '当前未选择文件';
    });

    uploadForm.addEventListener('submit', function (e) {
      e.preventDefault();

      const file = videoInput.files && videoInput.files[0];
      if (!file) {
        uploadStatus.textContent = '请先选择 mp4 文件';
        progressWrap.style.display = 'block';
        return;
      }

      const formData = new FormData(uploadForm);
      const xhr = new XMLHttpRequest();

      uploadBtn.disabled = true;
      progressWrap.style.display = 'block';
      uploadProgress.value = 0;
      uploadStatus.textContent = '开始上传...';

      xhr.open('POST', window.location.pathname + window.location.search, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', function (e) {
        if (!e.lengthComputable) {
          uploadStatus.textContent = '正在上传...';
          return;
        }

        const percent = Math.round((e.loaded / e.total) * 100);
        uploadProgress.value = percent;
        uploadStatus.textContent = `正在上传 ${percent}%`;
      });

      xhr.addEventListener('load', function () {
        uploadBtn.disabled = false;

        try {
          const data = JSON.parse(xhr.responseText || '{}');

          if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
            uploadProgress.value = 100;
            uploadStatus.textContent = '上传完成，正在跳转...';
            window.location.href = data.redirect;
          } else {
            uploadStatus.textContent = data.message || '上传失败';
          }
        } catch (err) {
          console.error(err);
          uploadStatus.textContent = '服务器返回格式错误';
        }
      });

      xhr.addEventListener('error', function () {
        uploadBtn.disabled = false;
        uploadStatus.textContent = '网络错误，上传失败';
      });

      xhr.addEventListener('abort', function () {
        uploadBtn.disabled = false;
        uploadStatus.textContent = '上传已取消';
      });

      xhr.send(formData);
    });

    async function safePlay() {
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

    enterBtn.addEventListener('click', async () => {
      const ok = await safePlay();
      if (!ok) {
        enterBtn.style.display = 'block';
      }
    });

    async function initPlayback() {
      if (!video || !video.getAttribute('src')) {
        enterBtn.style.display = 'none';
        return;
      }

      if (refFromUrl) {
        enterBtn.style.display = 'none';
        const ok = await safePlay();
        if (!ok) {
          enterBtn.style.display = 'block';
        }
      } else {
        enterBtn.style.display = 'block';
      }
    }

    resetZoomBtn.addEventListener('click', () => {
      const zoomControls = mainCameraEl && mainCameraEl.components['zoom-controls'];
      if (zoomControls) {
        zoomControls.reset();
      }
    });

    window.addEventListener('load', initPlayback);
  </script>
</body>
</html>