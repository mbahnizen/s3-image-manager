<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nizen Image Manager</title>
  <?php
    require_once __DIR__ . '/../src/bootstrap.php';
    use App\AuthService;
    $isLoggedIn = AuthService::isLoggedIn();
    $csrfToken = AuthService::getCsrfToken();
  ?>
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="workspace-sync-threshold" content="<?php echo defined('WORKSPACE_SYNC_THRESHOLD') ? (int)WORKSPACE_SYNC_THRESHOLD : 20; ?>">
  <link rel="stylesheet" href="app.css">
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
</head>
<body>

<div class="container">
  <div class="dynamic-island">
    <div class="pill">
      <span class="dot"></span>
      <span>Active Workspace</span>
      <span class="chip" id="islandWorkspaceLabel">-</span>
    </div>
    <div class="pill">
      <span class="chip status" id="islandStatusLabel">Idle</span>
      <span class="chip" id="islandQueueCount">Queue: 0</span>
      <span class="chip" id="islandProgressLabel">In Progress: 0</span>
      <span class="chip" id="islandModeLabel">Mode: Upload</span>
    </div>
  </div>
  <header>
    <h1 class="brand">
      <img src="heading.svg" alt="Logo">
      <span>Nizen Image Manager</span>
    </h1>
    <div>
      <button class="theme-toggle" id="themeToggle" title="Toggle Theme">
        <svg class="theme-icon" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M21 12.8A8.2 8.2 0 0 1 11.2 3a9 9 0 1 0 9.8 9.8z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
        </svg>
        <span>Theme</span>
      </button>
    </div>
  </header>

  <div class="tabs">
    <div class="tab active" data-tab="upload">Upload</div>
    <div class="tab" data-tab="gallery">Gallery</div>
    <div class="tab" data-tab="workspaces">Workspaces</div>
  </div>
  <div id="firstLoginTip" class="banner is-hidden">
    Tip: Buat workspace baru untuk memisahkan gambar berdasarkan artikel.
  </div>

  <div id="uploadView" class="tab-content">
    <div class="card">
      <div class="workspace-pill mb-12">
        <span class="hint">Target Workspace</span>
        <select id="uploadWorkspaceSelect"></select>
      </div>
      <div id="dropZone" class="upload-zone">
        <p>Drop images here, paste from clipboard, or click to select</p>
        <input type="file" id="fileInput" multiple class="is-hidden">
      </div>
      <div id="pendingList" class="pending-list"></div>
      <div class="pending-actions">
        <button id="uploadAllBtn" class="btn btn-primary is-hidden">Upload All</button>
        <span class="hint">Isi nama file sebelum upload. Spasi akan diubah menjadi strip dan huruf kecil.</span>
      </div>
      <div id="uploadStatus" class="mt-15"></div>
    </div>
  </div>

  <div id="galleryView" class="tab-content is-hidden">
    <div class="card">
      <div class="workspace-pill mb-12">
        <span class="hint">Workspace</span>
        <select id="galleryWorkspaceSelect"></select>
      </div>
      <div id="galleryGrid" class="gallery-grid"></div>
    </div>
  </div>

  <div id="workspacesView" class="tab-content is-hidden">
    <div class="card">
      <h3>Create Workspace</h3>
      <div class="row-gap-10 mb-12">
        <input type="text" id="wsTitle" placeholder="Article Title" class="input-flex">
        <button class="btn btn-primary" id="createWorkspaceBtn">Create</button>
      </div>
      <div class="workspace-toolbar mt-4">
        <input type="text" id="workspaceSearch" placeholder="Search workspace...">
        <select id="workspaceSort">
          <option value="recent">Sort: Recent</option>
          <option value="az">Sort: A-Z</option>
          <option value="count">Sort: Most Files</option>
        </select>
      </div>
      <hr class="divider">
      <div id="workspaceList" class="workspace-grid"></div>
      <div id="workspaceEmpty" class="workspace-empty is-hidden">
        Belum ada workspace. Buat yang pertama untuk mulai mengelompokkan gambar.
      </div>
    </div>
    <div class="card">
      <div class="workspace-toolbar space-between">
        <div class="font-700">Workspace Jobs</div>
        <div class="workspace-toolbar gap-8">
          <select id="workspaceJobsFilter">
            <option value="">All</option>
            <option value="active">Active</option>
            <option value="failed">Failed</option>
          </select>
          <label class="hint row-gap-6">
            <input type="checkbox" id="workspaceJobsHideOld" checked>
            Hide history >24h
          </label>
          <button class="btn btn-secondary" id="refreshWorkspaceJobsBtn">Refresh</button>
        </div>
      </div>
      <div id="workspaceJobsList" class="workspace-grid"></div>
      <div id="workspaceJobsEmpty" class="workspace-empty is-hidden">
        Belum ada job workspace.
      </div>
    </div>
  </div>
</div>

<?php if (!$isLoggedIn): ?>
<div id="loginOverlay">
  <div class="card login-box">
    <h2>Admin Login</h2>
    <input type="password" id="adminPassword" placeholder="Enter Password" class="login-input">
    <button class="btn btn-primary full-width" id="loginBtn">Login</button>
    <div id="loginError" class="text-error mt-10 text-sm"></div>
  </div>
</div>
<?php endif; ?>

<div id="toastContainer" class="toast-container"></div>
<div id="progressOverlay" class="progress-overlay">
  <div class="progress-card">
    <div id="progressTitle" class="progress-title">Processing...</div>
    <div id="progressDesc" class="progress-desc">Please wait</div>
    <div class="progress-bar"><span id="progressFill" class="progress-fill progress-p0"></span></div>
  </div>
</div>
<div id="workspaceModal" class="modal-overlay">
    <div class="modal">
    <h4 id="workspaceModalTitle">Workspace</h4>
    <p id="workspaceModalDesc"></p>
    <p id="workspaceModalMeta" class="hint mt-neg-6"></p>
    <input id="workspaceModalInput" type="text" class="is-hidden">
    <div class="modal-actions">
      <button class="btn btn-secondary" id="workspaceModalCancelBtn">Cancel</button>
      <button id="workspaceModalConfirm" class="btn btn-primary">Confirm</button>
    </div>
  </div>
</div>

  <script src="app.js"></script>
</body>
</html>
