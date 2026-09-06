import { detectWordPress } from "./detector.js";

const HISTORY_KEY = "history";
const MAX_HISTORY = 50;

async function saveToHistory(result) {
  const { [HISTORY_KEY]: history = [] } = await chrome.storage.local.get(HISTORY_KEY);
  const filtered = history.filter((item) => item.url !== result.url);
  filtered.unshift(result);
  await chrome.storage.local.set({ [HISTORY_KEY]: filtered.slice(0, MAX_HISTORY) });
}

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type !== "DETECT") return false;

  (async () => {
    try {
      const result = await detectWordPress(message.url);
      await saveToHistory(result);
      sendResponse({ ok: true, result });
    } catch (err) {
      sendResponse({ ok: false, error: String(err?.message || err) });
    }
  })();

  return true; // keep the message channel open for the async response
});
