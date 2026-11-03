let foundFiles = [];
let filteredFiles = [];
let progressInterval = null;
let isCrawling = false;
let currentPage = 1;
let filesPerPage = 10;
let selectedCategories = ['all'];
let currentSearchTerm = '';
let allCheckedFiles = new Set();
let searchTimeout = null;

const DEBUG_MODE = new URLSearchParams(window.location.search).get('debug') === '1';

function appendDebug(label, payload) {
    if (!DEBUG_MODE) return;
    const out = document.getElementById('debugOutput');
    if (!out) return;

    const ts = new Date().toISOString();
    let text;
    try {
        if (typeof payload === 'string') text = payload;
        else text = JSON.stringify(payload, null, 2);
    } catch (e) {
        text = String(payload);
    }

    out.textContent = `${out.textContent}${out.textContent ? "\n\n" : ""}[${ts}] ${label}\n${text}`;
    out.scrollTop = out.scrollHeight;
}

function setDebugHint(text) {
    if (!DEBUG_MODE) return;
    const hint = document.getElementById('debugHint');
    if (hint) hint.textContent = text;
}

// Progress bar UI removed by request (keep progress endpoint for debugging only)

// Get base API URL
function getApiUrl(endpoint) {
    // Get current directory path
    let path = window.location.pathname;
    
    // Remove filename if present (index.html)
    if (path.endsWith('.html') || path.endsWith('.php')) {
        path = path.substring(0, path.lastIndexOf('/'));
    }
    
    // Ensure trailing slash
    if (!path.endsWith('/')) {
        path += '/';
    }
    
    const fullUrl = window.location.origin + path + 'api/' + endpoint;
    console.log('[API] Generated URL:', fullUrl);
    return fullUrl;
}

// Show error message instead of alert
function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    const errorText = document.getElementById('errorText');
    if (errorDiv && errorText) {
        errorText.textContent = message;
        errorDiv.classList.remove('hidden');
        // Auto-hide after 5 seconds
        setTimeout(() => errorDiv.classList.add('hidden'), 5000);
    }
    console.error('[ERROR]', message);
}

function hideError() {
    const errorDiv = document.getElementById('errorMessage');
    if (errorDiv) errorDiv.classList.add('hidden');
}

document.addEventListener('DOMContentLoaded', function() {
    const startButton = document.getElementById('startCrawl');
    const downloadButton = document.getElementById('downloadFiles');
    const searchInput = document.getElementById('searchFiles');
    const toggleBtn = document.getElementById('toggleSelectionBtn');
    
    if (startButton) startButton.addEventListener('click', startCrawling);
    if (downloadButton) downloadButton.addEventListener('click', handleDownloadSelected);
    if (searchInput) searchInput.addEventListener('input', searchFiles);
    if (toggleBtn) toggleBtn.addEventListener('click', toggleSelection);

    if (DEBUG_MODE) {
        document.getElementById('debugSection')?.classList.remove('hidden');
        setDebugHint('Debug mode is ON (add ?debug=1)');
        appendDebug('page', { href: window.location.href, apiBase: getApiUrl('') });

        const copyBtn = document.getElementById('copyDebugBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', async () => {
                const out = document.getElementById('debugOutput');
                const text = out ? out.textContent : '';
                try {
                    await navigator.clipboard.writeText(text);
                    setDebugHint('Copied debug to clipboard');
                } catch (e) {
                    setDebugHint('Copy failed: ' + e.message);
                }
            });
        }
    }
    
    
    document.querySelectorAll('.category-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const category = this.id.replace('select', '').toLowerCase();
            const children = document.querySelectorAll(`input[data-category="${category}"]`);
            children.forEach(cb => cb.checked = this.checked);
            updateToggleButtonState();
        });
    });

    document.querySelectorAll('input[name="extensions"]').forEach(cb => {
        cb.addEventListener('change', updateToggleButtonState);
    });

    const modal = document.getElementById('fileModal');
    const closeBtn = document.querySelector('.modal-close');
    
    // Function to pause all media in modal
    function pauseAllMedia() {
        if (!modal) return;
        const videos = modal.querySelectorAll('video');
        const audios = modal.querySelectorAll('audio');
        videos.forEach(v => { v.pause(); v.currentTime = 0; });
        audios.forEach(a => { a.pause(); a.currentTime = 0; });
    }
    
    if (modal) {
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                pauseAllMedia();
                modal.classList.add('hidden');
                document.body.style.overflow = '';
            });
        }
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                pauseAllMedia();
                modal.classList.add('hidden');
                document.body.style.overflow = '';
            }
        });
    }
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            pauseAllMedia();
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    });
    
    updateToggleButtonState();
});

function toggleSelection() {
    const btn = document.getElementById('toggleSelectionBtn');
    const uncheckedBoxes = document.querySelectorAll('input[name="extensions"]:not(:checked)');
    const allBoxes = document.querySelectorAll('input[type="checkbox"]');
    const shouldSelect = uncheckedBoxes.length > 0;
    
    allBoxes.forEach(cb => cb.checked = shouldSelect);
    updateToggleButtonState();
}

function updateToggleButtonState() {
    const btn = document.getElementById('toggleSelectionBtn');
    if (!btn) return;
    const uncheckedBoxes = document.querySelectorAll('input[name="extensions"]:not(:checked)');
    btn.textContent = uncheckedBoxes.length === 0 ? 'Deselect All' : 'Select All';
}

async function fetchFileSizes(files) {
    const urlsToFetch = files.filter(f => f.size === 'Unknown').map(f => f.url);
    if (urlsToFetch.length === 0) return;
    
    console.log(`[SIZE] Fetching sizes for ${urlsToFetch.length} files...`);
    
    const batchSize = 50;
    for (let i = 0; i < urlsToFetch.length; i += batchSize) {
        const batch = urlsToFetch.slice(i, i + batchSize);
        
        try {
            const response = await fetch(getApiUrl('get_file_sizes.php'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ urls: batch })
            });
            
            const data = await response.json();
            
            if (data.sizes) {
                Object.entries(data.sizes).forEach(([url, size]) => {
                    foundFiles.forEach(f => {
                        if (f.url === url && size !== 'Unknown') f.size = size;
                    });
                    filteredFiles.forEach(f => {
                        if (f.url === url && size !== 'Unknown') f.size = size;
                    });
                    
                    document.querySelectorAll('.file-checkbox').forEach(checkbox => {
                        if (checkbox.value === url) {
                            const fileMeta = checkbox.parentElement.querySelector('.file-meta');
                            if (fileMeta && size !== 'Unknown') {
                                const ext = fileMeta.textContent.split(' | ')[0];
                                fileMeta.textContent = `${ext} | ${size}`;
                            }
                        }
                    });
                });
                
                console.log(`[SIZE] Updated batch ${Math.floor(i/batchSize) + 1}/${Math.ceil(urlsToFetch.length/batchSize)}`);
            }
        } catch (error) {
            console.error('[SIZE] Error fetching sizes:', error);
        }
        
        if (i + batchSize < urlsToFetch.length) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    }
    
    console.log('[SIZE] Finished fetching all sizes');
}

function startCrawling() {
    const urlTextarea = document.getElementById('url').value.trim();
    const maxDepth = parseInt(document.getElementById('maxDepth').value) || 2;
    const maxPages = parseInt(document.getElementById('maxPages').value) || 50;
    
    const extensionCheckboxes = document.querySelectorAll('input[name="extensions"]:checked');
    let extensions = Array.from(extensionCheckboxes).map(cb => cb.value);
    
    const customExtInput = document.getElementById('customExtensions');
    if (customExtInput && customExtInput.value.trim()) {
        const customExts = customExtInput.value.split(',').map(ext => {
            ext = ext.trim();
            if (ext && !ext.startsWith('.')) ext = '.' + ext;
            return ext.toLowerCase();
        }).filter(ext => ext.length > 1);
        extensions = extensions.concat(customExts);
    }
    
    const btn = document.getElementById('startCrawl');
    const btnText = document.getElementById('btnText');
    
    if (!urlTextarea) { showError('Please enter a URL'); return; }
    if (extensions.length === 0) { showError('Select at least one file type or enter custom extensions'); return; }
    
    // Auto-detect if headless mode needed (JavaScript-heavy sites)
    const jsHeavySites = ['zing.vn', 'zingmp3.vn', 'mp3.zing.vn', 'nhaccuatui.com', 'tiktok.com', 'shopee.vn', 'tiki.vn', 'lazada.vn', 'sendo.vn'];
    const useHeadless = jsHeavySites.some(site => urlTextarea.toLowerCase().includes(site));
    const useQueue = false; // Queue mode is not available in this build; the server ignores it

    // Zing pages can include many embedded links/ads; keep results focused on captured media
    const isZing = ['zing.vn', 'zingmp3.vn', 'mp3.zing.vn'].some(site => urlTextarea.toLowerCase().includes(site));
    const strictMediaOrigin = useHeadless && isZing;
    
    console.log('[CRAWLER] Starting crawl with:', { 
        url: urlTextarea.split('\n')[0], 
        maxDepth, 
        maxPages, 
        extensions, 
        useHeadless: useHeadless ? 'auto-detected' : 'no'
    });

    
    hideError();
    isCrawling = true;
    btn.disabled = true;
    btnText.textContent = useHeadless ? "Initializing Headless Browser..." : "Initializing..."; 
    btn.classList.add('crawling-active');
    document.getElementById('resultsSection').classList.add('hidden');

    // Parse URL & Proxy
    const lines = urlTextarea.split('\n').map(line => line.trim()).filter(line => line);
    const url = lines[0];
    const proxyList = lines.slice(1).join('\n');
    const useProxy = proxyList.length > 0 ? true : false;
    
    
    // Progress polling disabled (progress bar removed)
    
    console.log('[CRAWLER] Request sent to server...');

    console.log('[CRAWLER] API URL:', getApiUrl('crawl.php'));

    const crawlEndpoint = DEBUG_MODE ? 'crawl.php?debug=1' : 'crawl.php';
    fetch(getApiUrl(crawlEndpoint), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            url: url, 
            maxDepth: maxDepth, 
            maxPages: maxPages,
            extensions: extensions, 
            use_proxy: useProxy, 
            proxy_list: proxyList,
            use_headless: useHeadless,
            use_queue: useQueue,
            strict_media_origin: strictMediaOrigin
        })
    })
    .then(async response => {
        
        const contentType = response.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return response.json();
        } else {
            
            const text = await response.text();
            throw new Error("Server returned non-JSON response: " + text.substring(0, 150) + "...");
        }
    })
    .then(data => {
        if (progressInterval) clearInterval(progressInterval);
        isCrawling = false;
        
        console.log('[CRAWLER] Response received:', data);

        if (DEBUG_MODE) {
            // Avoid dumping giant file arrays into the panel
            const safe = {
                success: data?.success,
                error: data?.error,
                sid: data?.sid,
                files_count: Array.isArray(data?.files) ? data.files.length : null,
                stats: data?.stats,
                debug: data?.debug
            };
            appendDebug('crawl response (summary)', safe);
            if (data?.debug_log_tail) {
                appendDebug('crawl_debug.log (tail)', data.debug_log_tail);
            }
        }
        
        if (data.error) {
            btnText.textContent = "Failed!";
            btn.style.background = "#ef4444";
            console.error('[CRAWLER] Error:', data.error);
            showError(data.error);
            setTimeout(() => resetBtn(), 3000);
        } else {
            foundFiles = data.files || [];
            filteredFiles = foundFiles; 
            selectedCategories = ['all'];
            currentSearchTerm = '';
            currentPage = 1;
            console.log('[CRAWLER] Found', foundFiles.length, 'files');

            if (DEBUG_MODE && data?.sid) {
                setDebugHint('sid: ' + data.sid);
            }
            
            if (data.stats && data.stats.errors && data.stats.errors.length > 0) {
                console.error('[CRAWLER] Errors during crawl:', data.stats.errors);
                data.stats.errors.forEach((err, idx) => {
                    console.error(`Error ${idx + 1}:`, err);
                });
            }
            
            if (foundFiles.length > 0) {
                btnText.textContent = `Success (${foundFiles.length} files)`;
                btn.style.background = "#10b981";
                displayResults(foundFiles, data.stats);
                
                
                fetchFileSizes(foundFiles).then(() => {
                    console.log('[CRAWLER] File sizes updated');
                }).catch(err => {
                    console.error('[CRAWLER] Error updating file sizes:', err);
                });
            } else {
                btnText.textContent = "Finished (0 files)";
                btn.style.background = "#f59e0b";
                displayResults([], null);
                showError("Crawl finished but found 0 files. Try increasing Depth or checking file types.");
            }
            setTimeout(() => resetBtn(), 4000);
        }
    })
    .catch(error => {
        if (progressInterval) clearInterval(progressInterval);
        isCrawling = false;
        
        const btn = document.getElementById('startCrawl');
        const btnText = document.getElementById('btnText');
        
        if (btnText && btn) {
            btnText.textContent = "Error!";
            btn.style.background = "#ef4444";
        }
        
        console.error('[CRAWLER] Fetch error:', error);
        showError("System Error: " + error.message);
        setTimeout(() => resetBtn(), 3000);
    });
}

function resetBtn() {
    const btn = document.getElementById('startCrawl');
    const btnText = document.getElementById('btnText');
    
    if (!btn || !btnText) {
        console.warn('[CRAWLER] Reset button: elements not found');
        return;
    }
    
    btn.disabled = false;
    btnText.textContent = "Start Crawling Process";
    btn.style.background = "";
    btn.classList.remove('crawling-active');
}

function checkProgress() {
    
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 3000);
    
    const progressEndpoint = DEBUG_MODE ? 'crawl.php?progress=1&debug=1' : 'crawl.php?progress=1';
    fetch(getApiUrl(progressEndpoint), { signal: controller.signal })
        .then(r => r.json())
        .then(data => {
            clearTimeout(timeoutId);
            if (isCrawling) {
                updateProgressUI(data);
                if (DEBUG_MODE) {
                    appendDebug('progress', {
                        current: data?.current,
                        total: data?.total,
                        status: data?.status,
                        time_elapsed: data?.time_elapsed,
                        sid: data?.sid,
                        debug: data?.debug
                    });
                    if (data?.debug_log_tail) {
                        appendDebug('crawl_debug.log (tail)', data.debug_log_tail);
                    }
                }
            }
        })
        .catch((err) => {
            clearTimeout(timeoutId);
            if (err.name !== 'AbortError') {
                console.warn('[CRAWLER] Progress check failed:', err.message);
            }
        });
}

function displayResults(files, stats, isFiltered = false) {
    const resultsSection = document.getElementById('resultsSection');
    const filesList = document.getElementById('filesList');
    const fileCount = document.getElementById('fileCount');
    
    if (!resultsSection || !filesList) {
        console.error('[CRAWLER] Display results: required elements not found');
        return;
    }
    
    
    files = [...files].sort((a, b) => {
        const priorityCategories = ['documents', 'videos', 'audio', 'images'];
        const hasInfoA = a.size && a.size !== 'Unknown' ? 1 : 0;
        const hasInfoB = b.size && b.size !== 'Unknown' ? 1 : 0;
        const isPriorityA = priorityCategories.includes(a.category) ? 1 : 0;
        const isPriorityB = priorityCategories.includes(b.category) ? 1 : 0;
        
        
        if (hasInfoA !== hasInfoB) return hasInfoB - hasInfoA;
        
        if (isPriorityA !== isPriorityB) return isPriorityB - isPriorityA;
        
        return 0;
    });
    
    resultsSection.classList.remove('hidden');
    
    if (fileCount) {
        fileCount.textContent = files.length;
    }
    
    // Build category filter dropdown (always show)
    const totalCategories = {};
    foundFiles.forEach(file => {
        const cat = file.category || 'other';
        totalCategories[cat] = (totalCategories[cat] || 0) + 1;
    });
    
    console.log('[DISPLAY] Total categories from foundFiles:', totalCategories);
    
    const categoryNames = {
        'documents': 'Documents',
        'images': 'Images',
        'videos': 'Videos',
        'audio': 'Audio',
        'archives': 'Archives',
        'code': 'Code',
        'other': 'Other'
    };
    
    // Count selected files for display
    const checkedCount = document.querySelectorAll('.file-checkbox:checked').length;
    const totalFilteredCount = files.length;
    
    // Build compact toolbar in single row
    let filterHtml = `
        <div style="margin-bottom:20px; display:flex; gap:12px; align-items:stretch; width:100%;">
            <div class="filter-dropdown-container" style="position:relative;">
                <button onclick="toggleFilterDropdown()" class="btn-secondary" style="padding:12px 20px; display:flex; align-items:center; gap:8px; font-weight:600; background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:white; border:none; box-shadow:0 2px 8px rgba(59,130,246,0.3);">
                    <span>Filter by Type</span>
                    <span style="font-size:0.8em;">▼</span>
                </button>
                <div id="filterDropdown" class="filter-dropdown" style="display:none; position:absolute; top:100%; left:0; margin-top:5px; background:white; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); padding:12px; min-width:200px; z-index:1000;">
                    <label style="display:flex; align-items:center; gap:8px; padding:8px; cursor:pointer; border-radius:6px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <input type="checkbox" ${selectedCategories.includes('all') ? 'checked' : ''} onchange="filterByCategory('all')" style="width:18px; height:18px; cursor:pointer;">
                        <span style="flex:1;">All</span>
                        <span style="color:#64748b; font-size:0.9em;">(${foundFiles.length})</span>
                    </label>
                    ${['documents', 'images', 'videos', 'audio', 'archives', 'code', 'other'].map(cat => {
                        const count = totalCategories[cat] || 0;
                        return `
                            <label style="display:flex; align-items:center; gap:8px; padding:8px; cursor:pointer; border-radius:6px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" ${selectedCategories.includes(cat) ? 'checked' : ''} onchange="filterByCategory('${cat}')" style="width:18px; height:18px; cursor:pointer;">
                                <span style="flex:1;">${categoryNames[cat]}</span>
                                <span style="color:#64748b; font-size:0.9em;">(${count})</span>
                            </label>
                        `;
                    }).join('')}
                </div>
            </div>
            <input type="text" id="searchFiles" placeholder="Search files..." value="${currentSearchTerm}" class="input-styled" style="flex:1; min-width:200px; padding:12px 16px; border:2px solid #cbd5e1; border-radius:8px; font-size:0.95rem;">
            <button onclick="selectAllFiles()" class="btn-secondary" style="white-space:nowrap; padding:12px 24px; font-weight:700; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; border:none; border-radius:8px; box-shadow:0 2px 8px rgba(16,185,129,0.3);">Select ${totalFilteredCount} file${totalFilteredCount !== 1 ? 's' : ''}</button>
            <button onclick="handleDownloadSelected()" class="btn-secondary" style="white-space:nowrap; padding:12px 24px; font-weight:700; background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; border:none; border-radius:8px; box-shadow:0 2px 8px rgba(245,158,11,0.3);">Download</button>
        </div>
    `;
    
    // Handle empty results
    if (!files || files.length === 0) {
        filesList.innerHTML = filterHtml + '<div id="filesContainer"><div style="text-align:center; padding:30px; color:#64748b;">No files found matching your criteria.</div></div>';
        return;
    }
    
    // Pagination and file rendering
    const startIndex = (currentPage - 1) * filesPerPage;
    const endIndex = startIndex + filesPerPage;
    const paginatedFiles = files.slice(startIndex, endIndex);
    const totalPages = Math.ceil(files.length / filesPerPage);
    
    // Preserve checkbox states from allCheckedFiles Set
    let html = filterHtml;
    
    html += '<div id="filesContainer" style="display: grid; gap: 10px;">';
    paginatedFiles.forEach((file, index) => {
        const globalIndex = startIndex + index;
        const isChecked = allCheckedFiles.has(file.url) ? ' checked' : '';
        const isStreaming = isStreamingUrl(file.url);
        const streamBadge = isStreaming ? '<span style="background:#f59e0b; color:white; padding:2px 6px; border-radius:4px; font-size:0.75rem; margin-left:6px; font-weight:600;">STREAM</span>' : '';
        
        html += `
            <div class="file-item" ${isStreaming ? 'style="border-left:3px solid #f59e0b;"' : ''}>
                <input type="checkbox" class="file-checkbox" value="${escapeHtml(file.url)}" data-index="${globalIndex}"${isChecked} onchange="handleCheckboxChange(this)">
                <div class="file-info">
                    <div class="file-name" title="${escapeHtml(file.filename)}">${escapeHtml(file.filename)}${streamBadge}</div>
                    <div class="file-meta">${file.extension.toUpperCase()} | ${file.size || 'Unknown'}${isStreaming ? ' | FFmpeg required' : ''}</div>
                </div>
                <div class="file-actions">
                     <button onclick="viewFile('${escapeHtml(file.url)}', '${escapeHtml(file.filename)}', '${file.extension}')" class="btn-secondary btn-sm">View</button>
                     <button onclick="downloadSingleFile('${escapeHtml(file.url)}', '${escapeHtml(file.filename)}')" class="btn-secondary btn-sm">${isStreaming ? 'Convert' : 'Download'}</button>
                </div>
            </div>
        `;
    });
    html += '</div>';
    
    
    if (totalPages > 1) {
        html += '<div style="margin-top:20px; display:flex; justify-content:center; gap:15px; align-items:center; flex-wrap:wrap;">';
        
        if (currentPage > 1) {
            html += `<button onclick="changePage(${currentPage - 1})" class="btn-secondary">Previous</button>`;
        }
        
        html += `
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="color:var(--text-muted);">Page</span>
                <input type="number" id="pageJumpInput" value="${currentPage}" min="1" max="${totalPages}" 
                       style="width:60px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; text-align:center;"
                       onkeypress="if(event.key==='Enter') jumpToPage()"
                       onchange="jumpToPage()">
                <span style="color:var(--text-muted);">of ${totalPages}</span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="color:var(--text-muted);">(Showing </span>
                <input type="number" id="itemsPerPageInput" value="${filesPerPage}" min="5" max="100" 
                       style="width:60px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; text-align:center;"
                       onkeypress="if(event.key==='Enter') updateItemsPerPage()"
                       onchange="updateItemsPerPage()">
                <span style="color:var(--text-muted);">/${files.length} files total)</span>
            </div>
        `;
        
        if (currentPage < totalPages) {
            html += `<button onclick="changePage(${currentPage + 1})" class="btn-secondary">Next</button>`;
        }
        
        html += '</div>';
    }
    
    filesList.innerHTML = html;
    
    
    const searchInput = document.getElementById('searchFiles');
    if (searchInput && currentSearchTerm) {
        searchInput.value = currentSearchTerm;
    }
}

function closePreview() {
    const modal = document.getElementById('fileModal');
    if (modal) {
        const videos = modal.querySelectorAll('video');
        const audios = modal.querySelectorAll('audio');
        videos.forEach(v => { v.pause(); v.currentTime = 0; });
        audios.forEach(a => { a.pause(); a.currentTime = 0; });
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

// Check if URL is a streaming URL
function isStreamingUrl(url) {
    // Be conservative: only treat URLs as streams when they clearly point to HLS/DASH manifests.
    // Avoid false-positives (e.g. TikTok direct MP4 URLs that contain "stream" in the path/query).
    if (!url) return false;

    const u = String(url);
    return (/\.m3u8(\?|$)/i).test(u) ||
           (/\.mpd(\?|$)/i).test(u) ||
           (/[?&](format|type)=m3u8/i).test(u) ||
           (/[?&](format|type)=mpd/i).test(u);
}

function downloadSingleFile(url, filename) {
    // Check if this is a streaming URL
    if (isStreamingUrl(url)) {
        downloadStreamingFile(url, filename);
        return;
    }
    
    // Regular file download
    const link = document.createElement('a');
    link.download = filename || 'download';
    link.style.display = 'none';
    
    fetch(getApiUrl('download_single.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: url, filename: filename })
    })
    .then(response => {
        const ct = (response.headers.get('content-type') || '').toLowerCase();
        if (ct.includes('application/json')) {
            return response.json().then(j => {
                const msg = (j && (j.message || j.error)) ? (j.message || j.error) : 'Download failed';
                throw new Error(msg);
            });
        }
        if (!response.ok) throw new Error('Download failed');
        return response.blob();
    })
    .then(blob => {
        const blobUrl = window.URL.createObjectURL(blob);
        link.href = blobUrl;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(blobUrl);
    })
    .catch(error => {
        console.error('[DOWNLOAD] Failed:', error);
        showError('Download failed: ' + error.message);
    });
}

// Download streaming file (.m3u8, .mpd) using FFmpeg
function downloadStreamingFile(url, filename) {
    // Show loading notification
    showNotification('Converting stream with FFmpeg...', 'info');
    
    // Determine format from filename or default to mp4
    const ext = filename.split('.').pop().toLowerCase();
    const format = (ext === 'mp3' || ext === 'ogg' || ext === 'oga') ? 'mp3' : 'mp4';
    
    // Preflight: confirm this is a supported streaming URL and FFmpeg is available.
    const infoUrl = getApiUrl('download_stream.php') +
                    '?info=1&url=' + encodeURIComponent(url) +
                    '&format=' + encodeURIComponent(format);

    fetch(infoUrl)
        .then(async (resp) => {
            const data = await resp.json().catch(() => null);
            if (!resp.ok || !data || data.success !== true) {
                const msg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Stream convert failed';
                throw new Error(msg);
            }
            return data;
        })
        .then(() => {
            // Build download URL
            const downloadUrl = getApiUrl('download_stream.php') +
                                '?url=' + encodeURIComponent(url) +
                                '&format=' + format;

            // Create hidden link and trigger download
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename.replace(/\.[^.]+$/, '.' + format);
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            showNotification('Stream download started! This may take a few minutes...', 'info');
        })
        .catch((err) => {
            console.error('[STREAM] Convert failed:', err);
            showError('Convert failed: ' + (err && err.message ? err.message : 'Unknown error'));
        });
}

function viewFile(url, filename, ext) {
    const modal = document.getElementById('fileModal');
    const content = document.getElementById('modalFileContent');
    const modalFileName = document.getElementById('modalFileName');
    
    if (!modal || !content || !modalFileName) {
        console.error('[VIEWER] Modal elements not found');
        console.log('Preview unavailable. Opening in new tab...');
        window.open(url, '_blank');
        return;
    }
    
    modalFileName.textContent = filename;
    ext = ext.toLowerCase();
    
    
    if (['.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.bmp', '.ico', '.tiff', '.jfif', '.avif'].includes(ext)) {
        content.innerHTML = `
            <img src="${escapeHtml(url)}" alt="${escapeHtml(filename)}"
                 onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'preview-error\'><p>Failed to load image</p></div>';">
            <div class="preview-actions">
                <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                <button onclick="closePreview()" title="Close">❌</button>
            </div>
        `;
    } else if (['.pdf'].includes(ext)) {
        content.innerHTML = `
            <iframe src="${escapeHtml(url)}" title="${escapeHtml(filename)}"></iframe>
            <div class="preview-actions">
                <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                <button onclick="closePreview()" title="Close">❌</button>
            </div>
        `;
    } else if (['.mp4', '.webm', '.mov', '.avi', '.mkv', '.wmv', '.flv', '.m4v', '.ogv', '.3gp'].includes(ext)) {
        content.innerHTML = `
            <video controls autoplay style="max-width:100%; max-height:100%;">
                <source src="${escapeHtml(url)}" type="video/${ext.substring(1)}">
                Your browser does not support video playback.
            </video>
            <div class="preview-actions">
                <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                <button onclick="closePreview()" title="Close">❌</button>
            </div>
        `;
    } else if (['.mp3', '.wav', '.m4a', '.aac', '.flac', '.wma', '.mid', '.opus', '.ape', '.alac', '.oga'].includes(ext)) {
        content.innerHTML = `
            <div style="width:100%; padding:40px 20px; text-align:center;">
                <audio controls autoplay style="width:100%; max-width:500px;">
                    <source src="${escapeHtml(url)}" type="audio/${ext.substring(1)}">
                    Your browser does not support audio playback.
                </audio>
                <div class="preview-actions">
                    <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                    <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                    <button onclick="closePreview()" title="Close">❌</button>
                </div>
            </div>
        `;
    } else if (ext === '.ogg') {
        // OGG can be both video or audio - try video first with fallback to audio
        content.innerHTML = `
            <video controls autoplay style="max-width:100%; max-height:100%;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <source src="${escapeHtml(url)}" type="video/ogg">
            </video>
            <audio controls autoplay style="width:100%; max-width:500px; display:none;">
                <source src="${escapeHtml(url)}" type="audio/ogg">
                Your browser does not support OGG playback.
            </audio>
            <div class="preview-actions">
                <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                <button onclick="closePreview()" title="Close">❌</button>
            </div>
        `;
    } else if (['.txt', '.json', '.xml', '.csv', '.log'].includes(ext)) {
        
        content.innerHTML = '<p style="text-align:center; color:#fff;">Loading...</p>';
        fetch(url)
            .then(r => r.text())
            .then(text => {
                content.innerHTML = `
                    <div style="width:100%; height:100%; display:flex; flex-direction:column;">
                        <pre style="flex:1;">${escapeHtml(text.substring(0, 10000))}</pre>
                        <div class="preview-actions">
                            <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                            <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                            <button onclick="closePreview()" title="Close">❌</button>
                        </div>
                    </div>
                `;
            })
            .catch(() => {
                content.innerHTML = `<div class="preview-error"><p>Failed to load file</p></div>`;
            });
    } else if (['.html', '.htm'].includes(ext)) {
        content.innerHTML = `
            <div style="width:100%; height:100%; display:flex; flex-direction:column;">
                <iframe src="${escapeHtml(url)}" style="flex:1; width:100%; height:100%; border:none; background:white;"></iframe>
                <div class="preview-actions">
                    <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                    <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                    <button onclick="closePreview()" title="Close">❌</button>
                </div>
            </div>
        `;
    } else if (['.php', '.js', '.css', '.py', '.java', '.cpp', '.c', '.go', '.rs'].includes(ext)) {
        content.innerHTML = '<p style="text-align:center; color:#fff;">Loading...</p>';
        fetch(url)
            .then(r => r.text())
            .then(text => {
                content.innerHTML = `
                    <div style="width:100%; height:100%; display:flex; flex-direction:column;">
                        <pre style="flex:1; margin:0;">${escapeHtml(text.substring(0, 15000))}</pre>
                        <div class="preview-actions">
                            <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                            <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                            <button onclick="closePreview()" title="Close">❌</button>
                        </div>
                    </div>
                `;
            })
            .catch(() => {
                content.innerHTML = `
                    <div class="preview-error">
                        <p style="font-size:48px; margin-bottom:15px;">✖</p>
                        <p>Unable to load preview</p>
                        <div class="preview-actions">
                            <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                            <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                            <button onclick="closePreview()" title="Close">❌</button>
                        </div>
                    </div>
                `;
            });
    } else if (['.zip', '.rar', '.7z', '.tar', '.gz', '.iso', '.xz', '.bz2'].includes(ext)) {
        content.innerHTML = `
            <div class="preview-error">
                <p style="font-size:72px; margin-bottom:15px;">📦</p>
                <p>Archive File</p>
                <div class="preview-actions">
                    <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                    <button onclick="closePreview()" title="Close">❌</button>
                </div>
            </div>
        `;
    } else {
        content.innerHTML = `
            <div class="preview-error">
                <p style="font-size:72px; margin-bottom:15px;">📄</p>
                <p>Preview not available</p>
                <div class="preview-actions">
                    <a href="${escapeHtml(url)}" target="_blank" title="Open in new tab">🌐</a>
                    <button onclick="downloadSingleFile('${escapeHtml(url)}', '${escapeHtml(filename)}')" title="Download">📥</button>
                    <button onclick="closePreview()" title="Close">❌</button>
                </div>
            </div>
        `;
    }
    
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // Prevent background scroll
}

function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function showNotification(message, type = 'info') {
    
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'warning' ? '#f59e0b' : '#3b82f6'};
        color: white;
        padding: 15px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        max-width: 400px;
        font-size: 14px;
        animation: slideIn 0.3s ease-out;
    `;
    notification.textContent = message;
    
    
    if (!document.getElementById('notificationStyle')) {
        const style = document.createElement('style');
        style.id = 'notificationStyle';
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(notification);
    
    // Auto remove after 4 seconds
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(400px)';
        setTimeout(() => notification.remove(), 300);
    }, 4000);
}

function toggleFilterDropdown() {
    const dropdown = document.getElementById('filterDropdown');
    if (!dropdown) return;
    
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('filterDropdown');
    const container = document.querySelector('.filter-dropdown-container');
    if (dropdown && container && !container.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

function filterByCategory(category) {
    currentPage = 1;
    
    if (category === 'all') {
        
        selectedCategories = ['all'];
    } else {
        // Toggle category
        const index = selectedCategories.indexOf(category);
        
        
        const allIndex = selectedCategories.indexOf('all');
        if (allIndex > -1) {
            selectedCategories.splice(allIndex, 1);
        }
        
        if (index > -1) {
            
            selectedCategories.splice(index, 1);
            
            
            if (selectedCategories.length === 0) {
                selectedCategories = ['all'];
            }
        } else {
            
            selectedCategories.push(category);
        }
    }
    
    
    if (selectedCategories.includes('all')) {
        filteredFiles = foundFiles;
    } else {
        filteredFiles = foundFiles.filter(file => 
            selectedCategories.includes(file.category)
        );
    }
    
    
    if (currentSearchTerm) {
        const searchTerm = currentSearchTerm.toLowerCase();
        filteredFiles = filteredFiles.filter(file => 
            file.filename.toLowerCase().includes(searchTerm) || 
            file.url.toLowerCase().includes(searchTerm)
        );
    }
    
    displayResults(filteredFiles, null, true);
}

function handleCheckboxChange(checkbox) {
    if (checkbox.checked) {
        allCheckedFiles.add(checkbox.value);
    } else {
        allCheckedFiles.delete(checkbox.value);
    }
}

function selectAllFiles() {
    const files = selectedCategories.includes('all') ? (currentSearchTerm ? filteredFiles : foundFiles) : filteredFiles;
    const checkboxes = document.querySelectorAll('.file-checkbox');
    
    // Check if all visible checkboxes are checked
    const allVisibleChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    if (allVisibleChecked) {
        // Deselect all files across all pages
        files.forEach(file => allCheckedFiles.delete(file.url));
        checkboxes.forEach(cb => cb.checked = false);
    } else {
        // Select all files across all pages
        files.forEach(file => allCheckedFiles.add(file.url));
        checkboxes.forEach(cb => cb.checked = true);
    }
}

function changePage(page) {
    currentPage = page;
    const files = selectedCategories.includes('all') ? (currentSearchTerm ? filteredFiles : foundFiles) : filteredFiles;
    
    
    if (currentSearchTerm) {
        updateFilesList(files);
    } else {
        displayResults(files, null, true);
    }
    
    // Scroll to top of results
    document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function jumpToPage() {
    const input = document.getElementById('pageJumpInput');
    if (!input) return;
    
    let page = parseInt(input.value);
    const files = selectedCategories.includes('all') ? (currentSearchTerm ? filteredFiles : foundFiles) : filteredFiles;
    const totalPages = Math.ceil(files.length / filesPerPage);
    
    if (isNaN(page) || page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    
    currentPage = page;
    
    
    if (currentSearchTerm) {
        updateFilesList(files);
    } else {
        displayResults(files, null, true);
    }
    
    document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateItemsPerPage() {
    const select = document.getElementById('itemsPerPageSelect');
    if (!select) return;
    
    let items = parseInt(select.value);
    
    if (isNaN(items) || items < 5) items = 5;
    if (items > 100) items = 100;
    
    filesPerPage = items;
    currentPage = 1; 
    
    const files = selectedCategories.includes('all') ? (currentSearchTerm ? filteredFiles : foundFiles) : filteredFiles;
    
    if (currentSearchTerm) {
        updateFilesList(files);
    } else {
        displayResults(files, null, true);
    }
}

function handleDownloadSelected() {
    const checkedBoxes = document.querySelectorAll('.file-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showError('Please select at least one file to download');
        return;
    }
    
    const urls = Array.from(checkedBoxes).map(cb => cb.value);
    downloadFiles(urls);
}

function downloadFiles(urls) {
    const allButtons = document.querySelectorAll('button');
    allButtons.forEach(btn => btn.disabled = true);

    fetch(getApiUrl('download_zip.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ files: urls })
    })
    .then(r => {
        if (!r.ok) {
            return r.json().then(err => {
                throw new Error(err.error || 'Download failed');
            }).catch(() => {
                throw new Error('Download failed with status: ' + r.status);
            });
        }
        return r.blob();
    })
    .then(blob => {
        if (blob.size < 100) {
            throw new Error('ZIP file is empty or invalid');
        }
        
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'crawled_files_' + new Date().toISOString().slice(0,19).replace(/:/g,'-') + '.zip';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    })
    .catch(err => {
        console.error('[DOWNLOAD] Error:', err);
        showError('Download failed: ' + err.message + '. Please make sure ZIP extension is enabled in PHP.');
    })
    .finally(() => {
        allButtons.forEach(btn => btn.disabled = false);
    });
}


// Debounce search to prevent jumping

function searchFiles() {
    const searchInput = document.getElementById('searchFiles');
    if (!searchInput) return;
    
    currentSearchTerm = searchInput.value;
    
    // Clear previous timeout
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    
    // Debounce search by 300ms
    searchTimeout = setTimeout(() => {
        const searchTerm = searchInput.value.toLowerCase().trim();
        currentPage = 1;
        
        // Always search ALL files first
        let searchResults;
        if (searchTerm) {
            searchResults = foundFiles.filter(file => 
                file.filename.toLowerCase().includes(searchTerm) || 
                file.url.toLowerCase().includes(searchTerm)
            );
        } else {
            searchResults = foundFiles;
        }
        
        // Then apply category filter on search results
        if (selectedCategories.includes('all')) {
            filteredFiles = searchResults;
        } else {
            filteredFiles = searchResults.filter(f => selectedCategories.includes(f.category));
        }
        
        updateFilesList(filteredFiles);
    }, 300);
}


function updateFilesList(files) {
    // Use global allCheckedFiles Set for state management
    
    const startIndex = (currentPage - 1) * filesPerPage;
    const endIndex = startIndex + filesPerPage;
    const paginatedFiles = files.slice(startIndex, endIndex);
    const totalPages = Math.ceil(files.length / filesPerPage);
    
    // Build filter dropdown (always show)
    const totalCategories = {};
    foundFiles.forEach(file => {
        const cat = file.category || 'other';
        totalCategories[cat] = (totalCategories[cat] || 0) + 1;
    });
    
    const categoryNames = {
        'documents': 'Documents',
        'images': 'Images',
        'videos': 'Videos',
        'audio': 'Audio',
        'archives': 'Archives',
        'code': 'Code',
        'other': 'Other'
    };
    
    // Count files for toolbar
    const totalFilteredCount = files.length;
    
    // Build compact toolbar in single row
    let filterHtml = `
        <div style="margin-bottom:20px; display:flex; gap:12px; align-items:stretch; width:100%;">
            <div class="filter-dropdown-container" style="position:relative;">
                <button onclick="toggleFilterDropdown()" class="btn-secondary" style="padding:12px 20px; display:flex; align-items:center; gap:8px; font-weight:600; background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:white; border:none; box-shadow:0 2px 8px rgba(59,130,246,0.3);">
                    <span>Filter by Type</span>
                    <span style="font-size:0.8em;">▼</span>
                </button>
                <div id="filterDropdown" class="filter-dropdown" style="display:none; position:absolute; top:100%; left:0; margin-top:5px; background:white; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.15); padding:12px; min-width:200px; z-index:1000;">
                    <label style="display:flex; align-items:center; gap:8px; padding:8px; cursor:pointer; border-radius:6px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <input type="checkbox" ${selectedCategories.includes('all') ? 'checked' : ''} onchange="filterByCategory('all')" style="width:18px; height:18px; cursor:pointer;">
                        <span style="flex:1;">All</span>
                        <span style="color:#64748b; font-size:0.9em;">(${foundFiles.length})</span>
                    </label>
                    ${['documents', 'images', 'videos', 'audio', 'archives', 'code', 'other'].map(cat => {
                        const count = totalCategories[cat] || 0;
                        return `
                            <label style="display:flex; align-items:center; gap:8px; padding:8px; cursor:pointer; border-radius:6px;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" ${selectedCategories.includes(cat) ? 'checked' : ''} onchange="filterByCategory('${cat}')" style="width:18px; height:18px; cursor:pointer;">
                                <span style="flex:1;">${categoryNames[cat]}</span>
                                <span style="color:#64748b; font-size:0.9em;">(${count})</span>
                            </label>
                        `;
                    }).join('')}
                </div>
            </div>
            <input type="text" id="searchFiles" placeholder="Search files..." value="${currentSearchTerm}" class="input-styled" style="flex:1; min-width:200px; padding:12px 16px; border:2px solid #cbd5e1; border-radius:8px; font-size:0.95rem;">
            <button onclick="selectAllFiles()" class="btn-secondary" style="white-space:nowrap; padding:12px 24px; font-weight:700; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; border:none; border-radius:8px; box-shadow:0 2px 8px rgba(16,185,129,0.3);">Select ${totalFilteredCount} file${totalFilteredCount !== 1 ? 's' : ''}</button>
            <button onclick="handleDownloadSelected()" class="btn-secondary" style="white-space:nowrap; padding:12px 24px; font-weight:700; background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:white; border:none; border-radius:8px; box-shadow:0 2px 8px rgba(245,158,11,0.3);">Download Selected</button>
        </div>
    `;
    
    let html = '';
    
    // Handle empty results
    if (files.length === 0) {
        const filesList = document.getElementById('filesList');
        if (filesList) {
            filesList.innerHTML = filterHtml + '<div id="filesContainer"><div style="text-align:center; padding:30px; color:#64748b;">No files found matching your criteria.</div></div>';
        }
        return;
    }
    
    // Render file items
    paginatedFiles.forEach((file, index) => {
        const globalIndex = startIndex + index;
        const isChecked = allCheckedFiles.has(file.url) ? ' checked' : '';
        html += `
            <div class="file-item">
                <input type="checkbox" class="file-checkbox" value="${escapeHtml(file.url)}" data-index="${globalIndex}"${isChecked} onchange="handleCheckboxChange(this)">
                <div class="file-info">
                    <div class="file-name" title="${escapeHtml(file.filename)}">${escapeHtml(file.filename)}</div>
                    <div class="file-meta">${file.extension.toUpperCase()} | ${file.size || 'Unknown'}</div>
                </div>
                <div class="file-actions">
                     <button onclick="viewFile('${escapeHtml(file.url)}', '${escapeHtml(file.filename)}', '${file.extension}')" class="btn-secondary btn-sm">View</button>
                     <button onclick="downloadSingleFile('${escapeHtml(file.url)}', '${escapeHtml(file.filename)}')" class="btn-secondary btn-sm">Download</button>
                </div>
            </div>
        `;
    });
    
    // Add pagination
    if (totalPages > 1) {
        paginationHtml += '<div style="margin-top:20px; display:flex; justify-content:center; gap:15px; align-items:center; flex-wrap:wrap;">';
        
        if (currentPage > 1) {
            paginationHtml += `<button onclick="changePage(${currentPage - 1})" class="btn-secondary">Previous</button>`;
        }
        
        paginationHtml += `
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="color:var(--text-muted);">Page</span>
                <input type="number" id="pageJumpInput" value="${currentPage}" min="1" max="${totalPages}" 
                       style="width:60px; padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; text-align:center;"
                       onkeypress="if(event.key==='Enter') jumpToPage()"
                       onchange="jumpToPage()">
                <span style="color:var(--text-muted);">of ${totalPages}</span>
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="color:var(--text-muted);">Show</span>
                <select id="itemsPerPageSelect" onchange="updateItemsPerPage()" style="padding:6px 8px; border:1px solid #e2e8f0; border-radius:6px; background:white; cursor:pointer;">
                    <option value="5" ${filesPerPage === 5 ? 'selected' : ''}>5</option>
                    <option value="10" ${filesPerPage === 10 ? 'selected' : ''}>10</option>
                    <option value="20" ${filesPerPage === 20 ? 'selected' : ''}>20</option>
                    <option value="50" ${filesPerPage === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${filesPerPage === 100 ? 'selected' : ''}>100</option>
                </select>
                <span style="color:var(--text-muted);">files (${files.length} total)</span>
            </div>
        `;
        
        if (currentPage < totalPages) {
            paginationHtml += `<button onclick="changePage(${currentPage + 1})" class="btn-secondary">Next</button>`;
        }
        
        paginationHtml += '</div>';
    }
    
    const filesContainer = document.getElementById('filesContainer');
    const filesList = document.getElementById('filesList');
    
    if (filesContainer) {
        filesContainer.innerHTML = html + paginationHtml;
    } else if (filesList) {
        filesList.innerHTML = filterHtml + '<div id="filesContainer">' + html + paginationHtml + '</div>';
    }
}
