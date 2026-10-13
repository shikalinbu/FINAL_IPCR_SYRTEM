// ============================================================
// IPCR System — Main JavaScript
// ============================================================

// ── Debounce Utility ─────────────────────────────────────────
function debounce(func, delay = 500) {
  let timeoutId;
  const debounced = function(...args) {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func.apply(this, args), delay);
  };
  debounced.cancel = function() {
    clearTimeout(timeoutId);
  };
  return debounced;
}
window.debounce = debounce;

// ── Toast Notifications ──────────────────────────────────────
const Toast = {
  show(message, type = 'info', title = '', duration = 8000) {
    const icons = { success:'bi-check-circle-fill', error:'bi-exclamation-circle-fill', warning:'bi-exclamation-triangle-fill', info:'bi-info-circle-fill' };
    const container = document.getElementById('toast-container');
    if (!container) return;
    const el = document.createElement('div');
    el.className = `toast-item toast-${type}`;
    el.innerHTML = `
      <i class="bi ${icons[type]||icons.info} toast-icon"></i>
      <div class="toast-body">
        ${title ? `<div class="toast-title">${title}</div>` : ''}
        <div class="toast-msg">${message}</div>
      </div>
      <button class="toast-close" aria-label="Close"><i class="bi bi-x-lg"></i></button>`;
    el.querySelector('.toast-close').addEventListener('click', () => this.dismiss(el));
    container.appendChild(el);
    if (duration > 0) setTimeout(() => this.dismiss(el), duration);
  },
  dismiss(el) {
    el.classList.add('toast-hiding');
    setTimeout(() => el.remove(), 300);
  },
  success: (msg, title='Success') => Toast.show(msg, 'success', title, 8000),
  error:   (msg, title='Error')   => Toast.show(msg, 'error',   title, 0),
  warning: (msg, title='Warning') => Toast.show(msg, 'warning', title, 8000),
  info:    (msg, title='')        => Toast.show(msg, 'info',    title, 8000),
};
window.Toast = Toast;

// ── Sidebar Toggle ───────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const toggleBtn = document.getElementById('sidebar-toggle');
  if (toggleBtn) {
    toggleBtn.addEventListener('click', () => {
      if (window.innerWidth < 768) {
        document.body.classList.toggle('sidebar-open');
        document.body.classList.remove('sidebar-collapsed');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
        document.body.classList.remove('sidebar-open');
        // Persist state
        localStorage.setItem('sidebarCollapsed',
          document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
      }
    });
  }

  // Restore sidebar state on desktop
  if (window.innerWidth >= 768) {
    if (localStorage.getItem('sidebarCollapsed') === '1') {
      document.body.classList.add('sidebar-collapsed');
    }
  }

  // Close mobile sidebar on overlay click
  document.addEventListener('click', e => {
    if (window.innerWidth < 768
        && document.body.classList.contains('sidebar-open')
        && !e.target.closest('#sidebar')
        && !e.target.closest('#sidebar-toggle')) {
      document.body.classList.remove('sidebar-open');
    }
  });

  // Close sidebar on nav link click (mobile)
  document.querySelectorAll('#sidebar .sidebar-nav a').forEach(link => {
    link.addEventListener('click', (e) => {
      // Save sidebar scroll position before navigation
      const sidebar = document.getElementById('sidebar');
      if (sidebar) {
        sessionStorage.setItem('sidebarScrollPos', sidebar.scrollTop);
      }
      
      if (window.innerWidth < 768) {
        document.body.classList.remove('sidebar-open');
      }
    });
  });
  
  // Restore sidebar scroll position after page load - with delay for proper DOM rendering
  setTimeout(() => {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
      const savedScrollPos = sessionStorage.getItem('sidebarScrollPos');
      if (savedScrollPos !== null) {
        // Use requestAnimationFrame for smooth restore
        requestAnimationFrame(() => {
          sidebar.scrollTop = parseInt(savedScrollPos);
        });
        // Don't clear immediately - keep for potential re-renders
        setTimeout(() => sessionStorage.removeItem('sidebarScrollPos'), 1000);
      }
    }
  }, 100);

  // Handle resize — reset classes
  window.addEventListener('resize', () => {
    if (window.innerWidth >= 768) {
      document.body.classList.remove('sidebar-open');
    } else {
      document.body.classList.remove('sidebar-collapsed');
    }
  });

  // MFO accordion toggles
  document.querySelectorAll('.mfo-header').forEach(header => {
    header.addEventListener('click', () => {
      header.classList.toggle('collapsed');
      const body = header.nextElementSibling;
      if (body) body.classList.toggle('d-none');
    });
  });

  // ── Q/E/T Rating Average Computation ──────────────────────
  function computeQETAvg(q, e, t) {
    const qW = parseFloat(document.getElementById('qet-q-weight')?.value || 40);
    const eW = parseFloat(document.getElementById('qet-e-weight')?.value || 40);
    const tW = parseFloat(document.getElementById('qet-t-weight')?.value || 20);
    const total = qW + eW + tW;
    if (total === 0) return ((q + e + t) / 3);
    return (q * qW + e * eW + t * tW) / total;
  }

  function updateRatingAvg(siId) {
    const qEl = document.getElementById(`q-${siId}`);
    const eEl = document.getElementById(`e-${siId}`);
    const tEl = document.getElementById(`t-${siId}`);
    const avgEl = document.getElementById(`avg-${siId}`);
    if (!qEl || !eEl || !tEl || !avgEl) return;
    const q = parseFloat(qEl.value);
    const e = parseFloat(eEl.value);
    const t = parseFloat(tEl.value);
    if (!isNaN(q) && !isNaN(e) && !isNaN(t) && q >= 1 && e >= 1 && t >= 1) {
      const avg = computeQETAvg(q, e, t);
      avgEl.textContent = avg.toFixed(2);
      avgEl.style.background = getRatingColor(avg) + '22';
      avgEl.style.color = getRatingColor(avg);
    } else {
      avgEl.textContent = '—';
      avgEl.style.background = '#e9ecef';
      avgEl.style.color = '#6c757d';
    }
    updateLiveScore();
  }

  function getRatingColor(score) {
    const scale = window.RATING_SCALE || [
      {minScore:4.5,color:'#198754'},{minScore:3.5,color:'#0d6efd'},
      {minScore:2.5,color:'#ffc107'},{minScore:1.5,color:'#fd7e14'},{minScore:0,color:'#dc3545'}
    ];
    const sorted = [...scale].sort((a,b) => b.minScore - a.minScore);
    return sorted.find(l => score >= l.minScore)?.color || '#dc3545';
  }

  // Bind Q/E/T inputs
  document.querySelectorAll('.q-rating-input, .e-rating-input, .t-rating-input').forEach(input => {
    input.addEventListener('input', () => {
      const val = parseFloat(input.value);
      if (input.value && (val < 1 || val > 5)) {
        input.classList.add('is-invalid');
      } else {
        input.classList.remove('is-invalid');
      }
      updateRatingAvg(input.dataset.siId);
    });
  });

  // N/A toggles
  document.querySelectorAll('.na-toggle').forEach(cb => {
    cb.addEventListener('change', () => {
      const siId = cb.dataset.siId;
      const body = document.getElementById(`si-body-${siId}`);
      if (body) {
        body.classList.toggle('opacity-50', cb.checked);
        body.classList.toggle('pe-none', cb.checked);
      }
      updateLiveScore();
    });
  });

  // Verify status change → update card border class
  document.querySelectorAll('.verify-status-select').forEach(sel => {
    sel.addEventListener('change', () => {
      const card = sel.closest('.ev-review-card');
      if (card) {
        card.className = card.className.replace(/ev-\w+-card/, `ev-${sel.value}-card`);
      }
    });
  });

  // ── Live Score Panel ──────────────────────────────────────
  function updateLiveScore() {
    if (!window.FORM_ID) return;
    const mfoData = window.MFO_DATA || [];
    let compositeTotal = 0;
    let compositeWeightUsed = 0;

    mfoData.forEach(mfo => {
      let mfoWeightedSum = 0;
      let mfoValidKRAWeight = 0;

      mfo.kras.forEach(kra => {
        const validSIs = kra.siIds.filter(siId => {
          const naCb = document.getElementById(`na-${siId}`);
          if (naCb?.checked) return false;
          const q = parseFloat(document.getElementById(`q-${siId}`)?.value);
          const e = parseFloat(document.getElementById(`e-${siId}`)?.value);
          const t = parseFloat(document.getElementById(`t-${siId}`)?.value);
          return !isNaN(q) && !isNaN(e) && !isNaN(t);
        });
        if (!validSIs.length) return;

        const kraAvg = validSIs.reduce((sum, siId) => {
          const q = parseFloat(document.getElementById(`q-${siId}`).value);
          const e = parseFloat(document.getElementById(`e-${siId}`).value);
          const t = parseFloat(document.getElementById(`t-${siId}`).value);
          return sum + computeQETAvg(q, e, t);
        }, 0) / validSIs.length;

        mfoWeightedSum += kraAvg * (kra.weight / 100);
        mfoValidKRAWeight += kra.weight;
      });

      const mfoAvg = mfoValidKRAWeight > 0 ? mfoWeightedSum / (mfoValidKRAWeight / 100) : 0;
      compositeTotal += mfoAvg * (mfo.weight / 100);

      // Update MFO row in panel
      const mfoLbl = document.getElementById(`lsp-mfo-${mfo.id}`);
      const mfoBar = document.getElementById(`lsp-bar-${mfo.id}`);
      if (mfoLbl) mfoLbl.textContent = mfoAvg > 0 ? mfoAvg.toFixed(2) : '—';
      if (mfoBar) mfoBar.style.width = mfoAvg > 0 ? `${(mfoAvg/5)*100}%` : '0%';
    });

    const compEl  = document.getElementById('lsp-composite');
    const adjEl   = document.getElementById('lsp-adjectival');
    if (compEl) compEl.textContent = compositeTotal > 0 ? compositeTotal.toFixed(2) : '—';
    if (adjEl && compositeTotal > 0) {
      const adj = getAdjectival(compositeTotal);
      adjEl.textContent = adj.label;
      adjEl.style.color = adj.color;
    }

    // Update rated count
    const ratedEl = document.getElementById('lsp-rated');
    if (ratedEl) {
      let rated = 0, total = 0;
      document.querySelectorAll('.q-rating-input').forEach(inp => {
        const siId = inp.dataset.siId;
        const isRequired = inp.dataset.required === '1';
        if (!isRequired) return;
        const naCb = document.getElementById(`na-${siId}`);
        if (naCb?.checked) return;
        total++;
        const q = parseFloat(inp.value);
        const e = parseFloat(document.getElementById(`e-${siId}`)?.value);
        const t = parseFloat(document.getElementById(`t-${siId}`)?.value);
        if (!isNaN(q) && !isNaN(e) && !isNaN(t)) rated++;
      });
      ratedEl.textContent = `${rated}/${total}`;
    }
  }

  function getAdjectival(score) {
    const scale = window.RATING_SCALE || [
      {label:'Outstanding',color:'#198754',minScore:4.5},
      {label:'Very Satisfactory',color:'#0d6efd',minScore:3.5},
      {label:'Satisfactory',color:'#ffc107',minScore:2.5},
      {label:'Unsatisfactory',color:'#fd7e14',minScore:1.5},
      {label:'Poor',color:'#dc3545',minScore:0},
    ];
    const sorted = [...scale].sort((a,b)=>b.minScore-a.minScore);
    return sorted.find(l => score >= l.minScore) || sorted[sorted.length-1];
  }

  // ── Auto-Save Draft ──────────────────────────────────────
  window.autoSaveTimer = null;
  function startAutoSave() {
    if (window.autoSaveTimer) clearInterval(window.autoSaveTimer);
    window.autoSaveTimer = setInterval(saveDraft, 30000);
  }

  function saveDraft() {
    const formId = window.FORM_ID;
    if (!formId) return;
    const entries = collectDraftData();
    fetch(window.BASE_URL + '/api/save-draft.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ form_id: formId, entries }),
    })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        const el = document.getElementById('autosave-txt');
        if (el) el.textContent = 'Last saved: ' + new Date().toLocaleTimeString();
      }
    })
    .catch(() => {});
  }

  function collectDraftData() {
    const data = {};
    document.querySelectorAll('[data-si-id]').forEach(el => {
      const siId = el.dataset.siId;
      if (!data[siId]) data[siId] = {};
      if (el.classList.contains('planned-magnitude')) data[siId].planned_magnitude = el.value;
      if (el.classList.contains('planned-budget'))    data[siId].planned_budget    = el.value;
      if (el.classList.contains('planned-date'))      data[siId].planned_date      = el.value;
      if (el.classList.contains('actual-magnitude')) data[siId].actual_magnitude = el.value;
      if (el.classList.contains('actual-budget'))    data[siId].actual_budget    = el.value;
      if (el.classList.contains('actual-date'))      data[siId].actual_date      = el.value;
      if (el.classList.contains('actual-output'))    data[siId].actual_output    = el.value;
    });
    return data;
  }

  if (document.getElementById('btn-save-draft')) {
    document.getElementById('btn-save-draft').addEventListener('click', () => {
      saveDraft();
      Toast.success('Draft saved.');
    });
    startAutoSave();
    // beforeunload removed — auto-save handles persistence
  }

  // ── Dean Save Ratings ────────────────────────────────────
  function saveRatings() {
    const formId = window.FORM_ID;
    if (!formId) return;
    const entries = collectRatingData();
    fetch(window.BASE_URL + '/api/save-ratings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ form_id: formId, entries }),
    })
    .then(r => r.json())
    .then(d => {
      if (d.success) {
        const el = document.getElementById('autosave-txt');
        if (el) el.textContent = 'Ratings saved: ' + new Date().toLocaleTimeString();
      }
    })
    .catch(() => {});
  }

  function collectRatingData() {
    const data = {};
    document.querySelectorAll('.q-rating-input').forEach(el => {
      const siId = el.dataset.siId;
      if (!data[siId]) data[siId] = {};
      data[siId].q = el.value;
      data[siId].e = document.getElementById(`e-${siId}`)?.value || '';
      data[siId].t = document.getElementById(`t-${siId}`)?.value || '';
      data[siId].remarks       = document.getElementById(`remarks-${siId}`)?.value || '';
      data[siId].verify_status = document.getElementById(`verify-${siId}`)?.value || 'pending';
      data[siId].is_na         = document.getElementById(`na-${siId}`)?.checked ? 1 : 0;
    });
    return data;
  }

  if (document.getElementById('btn-save-progress')) {
    document.getElementById('btn-save-progress').addEventListener('click', () => {
      saveRatings();
      Toast.success('Ratings saved.');
    });
    if (window.autoSaveTimer) clearInterval(window.autoSaveTimer);
    window.autoSaveTimer = setInterval(saveRatings, 30000);
  }

  // ── File Upload ──────────────────────────────────────────
  // NOTE: Removed duplicate listener - handled by page-specific code (ipcr-form.page.js)
  // to prevent double uploads

  async function refreshEvidenceList(siId, formId) {
    try {
      const r = await fetch(`${window.BASE_URL}/api/get-evidence.php?si_id=${siId}&form_id=${formId}`);
      const d = await r.json();
      const container = document.getElementById(`ev-list-${siId}`);
      if (container && d.html) {
        container.innerHTML = d.html;
        // Update file count badge
        const badge = container.closest('.evidence-section')?.querySelector('.badge');
        if (badge && d.count > 0) {
          badge.textContent = d.count + ' file(s)';
          badge.style.display = '';
        }
      }
    } catch {}
  }

  // ── Delete Evidence ──────────────────────────────────────
  document.addEventListener('click', async e => {
    const btn = e.target.closest('.btn-delete-evidence');
    if (!btn) return;
    if (!confirm('Remove this evidence file?')) return;
    const fileId = btn.dataset.fileId;
    const siId   = btn.dataset.siId;
    const formId = window.FORM_ID || btn.dataset.formId;
    try {
      const r = await fetch(window.BASE_URL + '/api/delete-evidence.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({file_id: fileId}),
      });
      const d = await r.json();
      if (d.success) {
        document.getElementById(`ev-item-${fileId}`)?.remove();
        Toast.success('Evidence removed.');
      } else {
        Toast.error(d.message || 'Could not delete.');
      }
    } catch { Toast.error('Error removing file.'); }
  });

  // ── Submit IPCR ──────────────────────────────────────────
  const submitBtn = document.getElementById('btn-submit-ipcr');
  if (submitBtn) {
    submitBtn.addEventListener('click', async () => {
      if (!confirm('Submit your IPCR for evaluation? You cannot edit it until the Dean returns it.')) return;
      try {
        const r = await fetch(window.BASE_URL + '/api/submit-ipcr.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: window.FORM_ID}),
        });
        const d = await r.json();
        if (d.success) {
          // Show warning if there are uncertain files
          if (d.uncertain_count && d.uncertain_count > 0) {
            Toast.warning(d.warning || `${d.uncertain_count} evidence file(s) have uncertain AI verification.`);
          }
          Toast.success('IPCR submitted successfully!');
          setTimeout(() => location.href = window.BASE_URL + '/dashboard.php', 1500);
        } else {
          // Check if blocked due to invalid evidence
          if (d.invalid_files && d.invalid_files.length > 0) {
            const fileList = d.invalid_files.map(f => `• ${f.si_code}: ${f.original_name}`).join('<br>');
            const detailedMsg = `
              <div style="text-align:left">
                <strong>Cannot submit IPCR</strong><br>
                <div style="margin-top:8px;font-size:0.9em">
                  <strong>${d.invalid_files.length} file(s) marked as INVALID by AI verification:</strong><br>
                  ${fileList}<br><br>
                  Please remove or replace these files with proper evidence that matches the Success Indicator requirements.
                </div>
              </div>
            `;
            Toast.error(detailedMsg, 'Invalid Evidence', 12000);
          } else {
            Toast.error(d.message || 'Submission failed.');
          }
        }
      } catch { Toast.error('Submission failed.'); }
    });
  }

  // ── Submit Evaluation (Dean) ─────────────────────────────
  const submitEvalBtn = document.getElementById('btn-submit-evaluation');
  if (submitEvalBtn) {
    submitEvalBtn.addEventListener('click', async () => {
      if (!confirm('Submit your evaluation to the Administrator for final approval?')) return;
      const entries = collectRatingData();
      const remarks = document.getElementById('dean-overall-remarks')?.value || '';
      try {
        const r = await fetch(window.BASE_URL + '/api/submit-evaluation.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: window.FORM_ID, entries, dean_remarks: remarks}),
        });
        const d = await r.json();
        if (d.success) {
          Toast.success(`Evaluation submitted! Rating: ${d.composite_rating} — ${d.adjectival_rating}`);
          setTimeout(() => location.href = window.BASE_URL + '/dean/review-queue.php', 2000);
        } else {
          Toast.error(d.message || 'Submission failed.');
        }
      } catch { Toast.error('Submission failed.'); }
    });
  }

  // ── Return Form ──────────────────────────────────────────
  const returnBtn = document.getElementById('btn-return-form');
  if (returnBtn) {
    returnBtn.addEventListener('click', () => {
      const modal = new bootstrap.Modal(document.getElementById('returnModal'));
      modal.show();
    });
  }
  const confirmReturnBtn = document.getElementById('btn-confirm-return');
  if (confirmReturnBtn) {
    let isProcessing = false; // Prevent multiple clicks
    
    confirmReturnBtn.addEventListener('click', async () => {
      // Prevent duplicate clicks
      if (isProcessing) return;
      
      const remarks = document.getElementById('return-remarks')?.value?.trim();
      if (!remarks) { 
        Toast.warning('Please enter return remarks.'); 
        return; 
      }
      
      // Set processing state and disable button
      isProcessing = true;
      confirmReturnBtn.disabled = true;
      const originalText = confirmReturnBtn.innerHTML;
      confirmReturnBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Returning...';
      
      try {
        const r = await fetch(window.BASE_URL + '/api/return-form.php', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: window.FORM_ID, remarks}),
        });
        const d = await r.json();
        if (d.success) {
          Toast.warning('IPCR returned to faculty for revision.');
          setTimeout(() => location.href = window.BASE_URL + '/dean/review-queue.php', 1500);
        } else {
          Toast.error(d.message || 'Return failed.');
          // Re-enable button on error
          confirmReturnBtn.disabled = false;
          confirmReturnBtn.innerHTML = originalText;
          isProcessing = false;
        }
      } catch { 
        Toast.error('Error returning form.'); 
        // Re-enable button on error
        confirmReturnBtn.disabled = false;
        confirmReturnBtn.innerHTML = originalText;
        isProcessing = false;
      }
    });
  }

  // ── Admin Approve/Return/Finalize ─────────────────────────
  document.querySelectorAll('[data-action]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const action = btn.dataset.action;
      const formId = btn.dataset.formId;
      if (!formId) return;

      if (action === 'approve') {
        const remarks = prompt('Admin remarks (optional):') ?? '';
        if (!confirm('Approve this IPCR?')) return;
        const r = await fetch(window.BASE_URL + '/api/approve-form.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: formId, admin_remarks: remarks}),
        });
        const d = await r.json();
        d.success ? (Toast.success('IPCR approved.'), setTimeout(()=>location.reload(),1000)) : Toast.error(d.message);
      }
      if (action === 'finalize') {
        if (!confirm('FINALIZE this IPCR? This action is IRREVERSIBLE and permanently locks the record.')) return;
        const r = await fetch(window.BASE_URL + '/api/finalize-form.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: formId}),
        });
        const d = await r.json();
        d.success ? (Toast.success('IPCR finalized.'), setTimeout(()=>location.reload(),1000)) : Toast.error(d.message);
      }
      if (action === 'admin-return') {
        const remarks = prompt('Return remarks (required):');
        if (!remarks?.trim()) return;
        if (!confirm('Return this IPCR for revision?')) return;
        const r = await fetch(window.BASE_URL + '/api/admin-return-form.php', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({form_id: formId, remarks}),
        });
        const d = await r.json();
        d.success ? (Toast.warning('IPCR returned.'), setTimeout(()=>location.reload(),1000)) : Toast.error(d.message);
      }
    });
  });

  // ── Evidence description update ──────────────────────────
  document.querySelectorAll('.ev-desc-input').forEach(inp => {
    inp.addEventListener('blur', async () => {
      const fileId = inp.dataset.fileId;
      if (!fileId) return;
      await fetch(window.BASE_URL + '/api/update-evidence-desc.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({file_id: fileId, description: inp.value}),
      });
    });
  });

  // ── Live AJAX Filter for Tables ──────────────────────────────
  // Real-time filtering without page reload - smooth typing experience
  document.querySelectorAll('.filter-bar').forEach(form => {
    const textInputs = form.querySelectorAll('input[type="text"], input[type="search"]');
    const selectInputs = form.querySelectorAll('select');
    const dateInputs = form.querySelectorAll('input[type="date"]');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Find the table to update
    const tableContainer = form.parentElement.querySelector('.table-container') || 
                          document.querySelector('.table-container');
    
    if (!tableContainer) {
      // No table found, use regular form submit
      return;
    }
    
    const table = tableContainer.querySelector('table tbody');
    if (!table) return;
    
    // Debounced AJAX filter function
    const performFilter = debounce(async () => {
      const formData = new FormData(form);
      const params = new URLSearchParams(formData);
      
      // Show loading state
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Filtering...';
      }
      
      try {
        // Fetch the same page with filter params
        const response = await fetch(`${window.location.pathname}?${params}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        
        if (!response.ok) throw new Error('Filter failed');
        
        const html = await response.text();
        
        // Parse response and extract table body
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newTableBody = doc.querySelector('.table-container table tbody');
        
        if (newTableBody) {
          // Update table with fade effect
          table.style.opacity = '0.5';
          setTimeout(() => {
            table.innerHTML = newTableBody.innerHTML;
            table.style.opacity = '1';
          }, 150);
        }
        
      } catch (error) {
        console.error('Filter error:', error);
      } finally {
        // Restore button state
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = submitBtn.dataset.originalText || 'Filter';
        }
      }
    }, 600); // 600ms delay - good balance
    
    // Save original button text
    if (submitBtn && !submitBtn.dataset.originalText) {
      submitBtn.dataset.originalText = submitBtn.innerHTML;
    }
    
    // Bind to text inputs - debounced
    textInputs.forEach(input => {
      input.addEventListener('input', performFilter);
      
      // Enter key triggers immediate filter
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          performFilter.cancel && performFilter.cancel();
          performFilter();
        }
      });
    });
    
    // Bind to selects - immediate
    selectInputs.forEach(select => {
      if (!select.getAttribute('onchange')) {
        select.addEventListener('change', performFilter);
      }
    });
    
    // Bind to date inputs - immediate
    dateInputs.forEach(input => {
      input.addEventListener('change', performFilter);
    });
    
    // Prevent default form submission
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      performFilter();
    });
  });

  // Initialize
  updateLiveScore();
});

// ── AI Evidence Verification ─────────────────────────────────
document.addEventListener('click', async function(e) {
  const btn = e.target.closest('.ai-verify-btn');
  if (!btn) return;

  const fileId   = btn.dataset.fileId;
  const siId     = btn.dataset.siId;
  const filename = btn.dataset.filename;

  // Show loading state
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Checking...';
  btn.disabled  = true;
  btn.style.opacity = '.6';

  try {
    const r = await fetch(window.BASE_URL + '/api/ai-verify-evidence.php', {
      method:  'POST',
      headers: {'Content-Type': 'application/json'},
      body:    JSON.stringify({ file_id: parseInt(fileId), si_id: parseInt(siId) }),
    });
    const d = await r.json();

    if (!d.success) {
      // If high demand error, show retry option
      if (d.message && d.message.toLowerCase().includes('demand')) {
        btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Retry AI';
        btn.disabled  = false;
        btn.style.opacity = '1';
        Toast.warning('Gemini is busy. Click "Retry AI" to try again.', 'High Demand');
      } else {
        Toast.error(d.message || 'AI verification failed.');
        btn.innerHTML = '<i class="bi bi-stars"></i> AI';
        btn.disabled  = false;
        btn.style.opacity = '1';
      }
      return;
    }

    // Show result as a styled toast
    const verdictConfig = {
      valid:     { icon: '✅', color: '#16a34a', label: 'Valid',     toastType: 'success' },
      uncertain: { icon: '⚠️', color: '#d97706', label: 'Uncertain', toastType: 'warning' },
      invalid:   { icon: '❌', color: '#dc2626', label: 'Invalid',   toastType: 'error'   },
    };
    const cfg = verdictConfig[d.verdict] || verdictConfig.uncertain;

    // Update button
    btn.innerHTML = `${cfg.icon} ${cfg.label} (${d.confidence}%)`;
    btn.style.borderColor = cfg.color;
    btn.style.color       = cfg.color;
    btn.disabled          = false;
    btn.style.opacity     = '1';

    // Show detailed toast
    const msg = `<strong>${d.document_type || filename}</strong><br>
      ${d.content_summary ? '<div style="font-size:.85rem;margin-top:4px;opacity:.9">' + d.content_summary + '</div>' : ''}
      ${d.relevance_check ? '<div style="font-size:.85rem;margin-top:6px;padding:6px;background:rgba(0,0,0,.05);border-radius:4px;"><strong>Relevance:</strong> ' + d.relevance_check + '</div>' : ''}
      <div style="margin-top:6px;">${d.reason}</div>
      ${d.suggestions ? '<div style="margin-top:6px;font-size:.85rem;opacity:.8"><strong>Suggestion:</strong> ' + d.suggestions + '</div>' : ''}`;

    Toast.show(msg, d.verdict === 'valid' ? 'success' : d.verdict === 'invalid' ? 'error' : 'warning',
      `AI: ${cfg.label} Evidence (${d.confidence}%)`, 10000);

  } catch(err) {
    Toast.error('AI check failed. Check your connection.');
    btn.innerHTML = '<i class="bi bi-stars"></i> AI Check';
    btn.disabled  = false;
    btn.style.opacity = '1';
  }
});
