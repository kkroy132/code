import { detectWordPress, quickCheck } from "./detector.js";

const HISTORY_KEY = "history";
const MAX_HISTORY = 50;
const CONTEXT_MENU_ID = "check-wp-link";

const BADGE_COLORS = {
  wordpress: "#00a32a",
  uncertain: "#dba617",
  "not-wordpress": "#d63638",
};

const BADGE_TEXT = {
  wordpress: "WP",
  uncertain: "?",
  "not-wordpress": "",
};

// Bulk checks fire several saves concurrently; a plain read-modify-write
// would lose entries when two saves overlap, so they're queued through
// this chain to run one at a time.
let historyQueue = Promise.resolve();

function saveToHistory(result) {
  historyQueue = historyQueue.then(async () => {
    const { [HISTORY_KEY]: history = [] } = await chrome.storage.local.get(HISTORY_KEY);
    const filtered = history.filter((item) => item.url !== result.url);
    filtered.unshift(result);
    await chrome.storage.local.set({ [HISTORY_KEY]: filtered.slice(0, MAX_HISTORY) });
  });
  return historyQueue;
}

async function setBadgeForTab(tabId, verdict) {
  try {
    await chrome.action.setBadgeText({ tabId, text: BADGE_TEXT[verdict] ?? "" });
    await chrome.action.setBadgeBackgroundColor({ tabId, color: BADGE_COLORS[verdict] ?? "#8c8f94" });
  } catch {
    // Tab may have been closed before we finished checking; ignore.
  }
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== "DETECT") return false;

  (async () => {
    try {
      const result = await detectWordPress(message.url);
      if (!message.skipHistory) {
        await saveToHistory(result);
      }
      sendResponse({ ok: true, result });
    } catch (err) {
      sendResponse({ ok: false, error: String(err?.message || err) });
    }
  })();

  return true; // keep the message channel open for the async response
});

// --- Toolbar badge: quick auto-check whenever a tab finishes loading ---

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status !== "complete") return;
  if (!tab.url || !/^https?:\/\//i.test(tab.url)) return;

  chrome.action.setBadgeText({ tabId, text: "" });
  quickCheck(tab.url)
    .then((result) => setBadgeForTab(tabId, result.verdict))
    .catch(() => chrome.action.setBadgeText({ tabId, text: "" }).catch(() => {}));
});

// --- Right-click a link to check it without leaving the page ---

chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.create({
    id: CONTEXT_MENU_ID,
    title: "এই লিংকটি WordPress কিনা চেক করুন",
    contexts: ["link"],
  });
});

chrome.contextMenus.onClicked.addListener(async (info) => {
  if (info.menuItemId !== CONTEXT_MENU_ID || !info.linkUrl) return;

  let result;
  try {
    result = await detectWordPress(info.linkUrl);
    await saveToHistory(result);
  } catch (err) {
    chrome.notifications.create({
      type: "basic",
      iconUrl: "icons/icon128.png",
      title: "চেক ব্যর্থ হয়েছে",
      message: String(err?.message || err),
    });
    return;
  }

  const titleByVerdict = {
    wordpress: "✅ WordPress সাইট",
    uncertain: "❓ নিশ্চিত নয়",
    "not-wordpress": "❌ WordPress নয়",
  };

  chrome.notifications.create({
    type: "basic",
    iconUrl: "icons/icon128.png",
    title: titleByVerdict[result.verdict] ?? "ফলাফল",
    message: `${result.url}\nস্কোর: ${result.score}/100`,
  });
});
