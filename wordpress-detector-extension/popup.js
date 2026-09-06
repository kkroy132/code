const VERDICT_LABELS = {
  wordpress: "✅ WordPress সাইট",
  uncertain: "❓ নিশ্চিত নয়",
  "not-wordpress": "❌ WordPress নয়",
};

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = String(str);
  return div.innerHTML;
}

function renderResult(container, result) {
  container.hidden = false;
  container.className = `result ${result.verdict}`;

  const signalsHtml = result.signals.length
    ? `<ul class="signals">${result.signals
        .map((s) => `<li>${escapeHtml(s.name)} (+${s.weight}) — ${escapeHtml(s.detail)}</li>`)
        .join("")}</ul>`
    : `<p>কোনো WordPress সিগন্যাল পাওয়া যায়নি।</p>`;

  const errorsHtml = result.errors.length
    ? `<div class="errors">⚠️ ${result.errors.map(escapeHtml).join(", ")}</div>`
    : "";

  container.innerHTML = `
    <div class="verdict">${VERDICT_LABELS[result.verdict]} <span class="score">(স্কোর: ${result.score}/100)</span></div>
    ${signalsHtml}
    ${errorsHtml}
  `;
}

function renderLoading(container) {
  container.hidden = false;
  container.className = "result loading";
  container.textContent = "চেক করা হচ্ছে...";
}

function renderError(container, message) {
  container.hidden = false;
  container.className = "result not-wordpress";
  container.textContent = `ত্রুটি: ${message}`;
}

async function detect(url) {
  return chrome.runtime.sendMessage({ type: "DETECT", url });
}

async function runDetection(url, resultEl) {
  renderLoading(resultEl);
  const response = await detect(url);
  if (response?.ok) {
    renderResult(resultEl, response.result);
  } else {
    renderError(resultEl, response?.error || "অজানা সমস্যা হয়েছে।");
  }
  await refreshHistory();
}

function faviconUrl(pageUrl) {
  const u = new URL(chrome.runtime.getURL("/_favicon/"));
  u.searchParams.set("pageUrl", pageUrl);
  u.searchParams.set("size", "16");
  return u.toString();
}

function formatHistoryItem(item) {
  const li = document.createElement("li");
  const favicon = document.createElement("img");
  favicon.className = "favicon";
  favicon.src = faviconUrl(item.url);
  favicon.alt = "";
  const badge = document.createElement("span");
  badge.className = `badge ${item.verdict}`;
  const urlSpan = document.createElement("span");
  urlSpan.className = "hist-url";
  urlSpan.textContent = item.url;
  urlSpan.title = item.url;
  const scoreSpan = document.createElement("span");
  scoreSpan.textContent = `${item.score}`;
  li.append(favicon, badge, urlSpan, scoreSpan);
  return li;
}

async function refreshHistory() {
  const { history = [] } = await chrome.storage.local.get("history");
  const list = document.getElementById("history-list");
  list.innerHTML = "";
  if (!history.length) {
    const li = document.createElement("li");
    li.className = "empty";
    li.textContent = "এখনো কিছু চেক করা হয়নি।";
    list.appendChild(li);
    return;
  }
  for (const item of history) {
    list.appendChild(formatHistoryItem(item));
  }
}

async function runWithConcurrency(items, limit, worker) {
  const results = new Array(items.length);
  let next = 0;
  async function runNext() {
    const i = next++;
    if (i >= items.length) return;
    results[i] = await worker(items[i], i);
    await runNext();
  }
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, runNext));
  return results;
}

function parseBulkInput(text) {
  return [...new Set(text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean))];
}

function downloadCsv(rows) {
  const header = ["URL", "Verdict", "Score"];
  const csvLines = [header.join(",")];
  for (const row of rows) {
    const cells = [row.url, row.verdict, row.score].map((v) => `"${String(v).replace(/"/g, '""')}"`);
    csvLines.push(cells.join(","));
  }
  const blob = new Blob([csvLines.join("\n")], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `wordpress-check-${Date.now()}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function setupBulkCheck() {
  const textarea = document.getElementById("bulk-urls");
  const checkBtn = document.getElementById("bulk-check-btn");
  const exportBtn = document.getElementById("bulk-export-btn");
  const progressEl = document.getElementById("bulk-progress");
  const table = document.getElementById("bulk-table");
  const tbody = document.getElementById("bulk-tbody");

  let lastResults = [];

  checkBtn.addEventListener("click", async () => {
    const urls = parseBulkInput(textarea.value);
    if (!urls.length) return;

    checkBtn.disabled = true;
    exportBtn.disabled = true;
    table.hidden = false;
    tbody.innerHTML = "";
    progressEl.hidden = false;

    const rows = new Map();
    for (const url of urls) {
      const tr = document.createElement("tr");
      tr.innerHTML = `<td class="bulk-url" title="${escapeHtml(url)}">${escapeHtml(url)}</td><td>চেক করা হচ্ছে...</td><td>—</td>`;
      tbody.appendChild(tr);
      rows.set(url, tr);
    }

    let done = 0;
    lastResults = await runWithConcurrency(urls, 4, async (url) => {
      const response = await detect(url);
      done += 1;
      progressEl.textContent = `${done}/${urls.length} সম্পন্ন`;
      const tr = rows.get(url);
      if (response?.ok) {
        const { result } = response;
        tr.innerHTML = `
          <td class="bulk-url" title="${escapeHtml(result.url)}">${escapeHtml(result.url)}</td>
          <td><span class="verdict-cell"><span class="badge ${result.verdict}"></span>${VERDICT_LABELS[result.verdict]}</span></td>
          <td>${result.score}</td>
        `;
        return { url: result.url, verdict: result.verdict, score: result.score };
      }
      tr.innerHTML = `<td class="bulk-url" title="${escapeHtml(url)}">${escapeHtml(url)}</td><td>ত্রুটি</td><td>—</td>`;
      return { url, verdict: "error", score: "" };
    });

    progressEl.textContent = `সম্পন্ন: ${urls.length}টি সাইট`;
    checkBtn.disabled = false;
    exportBtn.disabled = false;
    await refreshHistory();
  });

  exportBtn.addEventListener("click", () => {
    if (lastResults.length) downloadCsv(lastResults);
  });
}

async function init() {
  const currentUrlEl = document.getElementById("current-tab-url");
  const currentResultEl = document.getElementById("current-tab-result");
  const checkCurrentBtn = document.getElementById("check-current-btn");

  let currentTabOrigin = null;
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (tab?.url && /^https?:\/\//i.test(tab.url)) {
      const url = new URL(tab.url);
      currentTabOrigin = url.origin;
      currentUrlEl.textContent = currentTabOrigin;
    } else {
      currentUrlEl.textContent = "এই ট্যাবে চেক করা যাচ্ছে না।";
      checkCurrentBtn.disabled = true;
    }
  } catch {
    currentUrlEl.textContent = "ট্যাবের তথ্য পাওয়া যায়নি।";
    checkCurrentBtn.disabled = true;
  }

  checkCurrentBtn.addEventListener("click", async () => {
    if (!currentTabOrigin) return;
    checkCurrentBtn.disabled = true;
    await runDetection(currentTabOrigin, currentResultEl);
    checkCurrentBtn.disabled = false;
  });

  const manualForm = document.getElementById("manual-form");
  const manualUrlInput = document.getElementById("manual-url");
  const manualResultEl = document.getElementById("manual-result");
  const checkManualBtn = document.getElementById("check-manual-btn");

  manualForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const value = manualUrlInput.value.trim();
    if (!value) return;
    checkManualBtn.disabled = true;
    await runDetection(value, manualResultEl);
    checkManualBtn.disabled = false;
  });

  document.getElementById("clear-history-btn").addEventListener("click", async () => {
    await chrome.storage.local.set({ history: [] });
    await refreshHistory();
  });

  setupBulkCheck();
  await refreshHistory();
}

init();
