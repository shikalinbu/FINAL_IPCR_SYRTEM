  </main><!-- /page-content -->
</div><!-- /main-wrapper -->
</div><!-- /app-shell -->
<div id="toast-container"></div>

<!-- ── GLOBAL FILE PREVIEW MODAL ── -->
<div class="modal fade" id="filePreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width:90vw">
    <div class="modal-content" style="height:90vh;display:flex;flex-direction:column">
      <div class="modal-header" style="flex-shrink:0">
        <h6 class="modal-title" id="preview-filename">
          <i class="bi bi-file-earmark me-2"></i>File Preview
        </h6>
        <div class="d-flex gap-2 align-items-center ms-auto">
          <a id="preview-download-btn" href="#" target="_blank"
             class="btn btn-sm btn-outline-light"
             style="font-size:.75rem">
            <i class="bi bi-download me-1"></i>Download
          </a>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>
      <div class="modal-body preview-modal-body" id="preview-modal-body">
        <div class="preview-unsupported">
          <i class="bi bi-file-earmark"></i>
          <span>Loading...</span>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= time() ?>"></script>
<script>
// ── Global File Preview ──────────────────────────────────────
function previewFile(fileId, fileName, fileType) {
  const url   = '<?= BASE_URL ?>/api/download-evidence.php?id=' + fileId;
  const modal = document.getElementById('filePreviewModal');
  const body  = document.getElementById('preview-modal-body');
  const title = document.getElementById('preview-filename');
  const dlBtn = document.getElementById('preview-download-btn');

  title.innerHTML = '<i class="bi bi-file-earmark me-2"></i>' + fileName;
  dlBtn.href = url;

  const ext      = fileName.split('.').pop().toLowerCase();
  const imgExts  = ['jpg','jpeg','png','gif','webp','bmp','svg'];
  const pdfExts  = ['pdf'];

  if (imgExts.includes(ext)) {
    body.innerHTML = `
      <div style="overflow:auto;height:100%;display:flex;align-items:center;justify-content:center;background:#1a1a1a;padding:12px">
        <img src="${url}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.5)" alt="${fileName}">
      </div>`;

  } else if (pdfExts.includes(ext)) {
    body.innerHTML = `<iframe src="${url}" style="width:100%;height:100%;border:none;" title="${fileName}"></iframe>`;

  } else {
    // Word, Excel, and other files — show preview unavailable + download
    const icons = {
      doc:'bi-file-earmark-word-fill', docx:'bi-file-earmark-word-fill',
      xls:'bi-file-earmark-excel-fill', xlsx:'bi-file-earmark-excel-fill',
      ppt:'bi-file-earmark-ppt-fill', pptx:'bi-file-earmark-ppt-fill',
    };
    const colors = {
      doc:'#2563eb', docx:'#2563eb',
      xls:'#16a34a', xlsx:'#16a34a',
      ppt:'#dc2626', pptx:'#dc2626',
    };
    const icon  = icons[ext]  || 'bi-file-earmark-fill';
    const color = colors[ext] || '#6b7280';

    body.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:16px;padding:40px;text-align:center">
        <i class="bi ${icon}" style="font-size:5rem;color:${color}"></i>
        <div style="font-size:1.1rem;font-weight:700;color:#1e2d22">${fileName}</div>
        <div style="font-size:.88rem;color:#6a8f72;max-width:400px;line-height:1.6">
          <strong>.${ext.toUpperCase()}</strong> files cannot be previewed directly in the browser.<br><br>
          <span style="color:#dc2626;font-weight:600">⚠ Ask faculty to re-upload as PDF for instant preview.</span>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:8px">
          <a href="${url}" target="_blank"
             style="background:#1a5c2a;color:#fff;padding:10px 24px;border-radius:8px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;font-size:.88rem">
            <i class="bi bi-box-arrow-up-right"></i> Open in New Tab
          </a>
          <a href="${url}" download="${fileName}"
             style="background:#fff;color:#1a5c2a;padding:10px 24px;border-radius:8px;font-weight:700;text-decoration:none;border:2px solid #1a5c2a;display:flex;align-items:center;gap:7px;font-size:.88rem">
            <i class="bi bi-download"></i> Download File
          </a>
        </div>
      </div>`;
  }

  new bootstrap.Modal(modal).show();

  // Clear on close to stop any loading
  modal.addEventListener('hidden.bs.modal', () => {
    body.innerHTML = '';
  }, { once: true });
}
</script>

</body>
</html>
