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

function formatHistoryItem(item) {
  const li = document.createElement("li");
  const badge = document.createElement("span");
  badge.className = `badge ${item.verdict}`;
  const urlSpan = document.createElement("span");
  urlSpan.className = "hist-url";
  urlSpan.textContent = item.url;
  urlSpan.title = item.url;
  const scoreSpan = document.createElement("span");
  scoreSpan.textContent = `${item.score}`;
  li.append(badge, urlSpan, scoreSpan);
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

  await refreshHistory();
}

init();
