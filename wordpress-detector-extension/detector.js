// Core WordPress detection engine.
// Runs in the background service worker, which holds the host_permissions
// needed to fetch cross-origin pages without hitting CORS restrictions.

const FETCH_TIMEOUT_MS = 8000;

function normalizeUrl(input) {
  let value = input.trim();
  if (!value) throw new Error("Empty URL");
  if (!/^https?:\/\//i.test(value)) {
    value = "https://" + value;
  }
  const url = new URL(value);
  return url.origin;
}

async function fetchWithTimeout(url, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    return response;
  } finally {
    clearTimeout(timer);
  }
}

async function safeFetchText(url) {
  try {
    const res = await fetchWithTimeout(url, { credentials: "omit" });
    if (!res.ok) return { ok: false, status: res.status, text: "" };
    const text = await res.text();
    return { ok: true, status: res.status, text };
  } catch (err) {
    return { ok: false, status: 0, text: "", error: String(err) };
  }
}

function addSignal(signals, name, weight, detail) {
  signals.push({ name, weight, detail });
}

function analyzeHomepage(html, signals) {
  const lower = html.toLowerCase();

  const generatorMatch = html.match(/<meta[^>]+name=["']generator["'][^>]+content=["']([^"']*)["']/i);
  if (generatorMatch && /wordpress/i.test(generatorMatch[1])) {
    addSignal(signals, "meta-generator", 40, generatorMatch[1]);
  }

  if (lower.includes("wp-content/")) {
    addSignal(signals, "wp-content path", 25, "Found 'wp-content/' reference");
  }

  if (lower.includes("wp-includes/")) {
    addSignal(signals, "wp-includes path", 20, "Found 'wp-includes/' reference");
  }

  if (/rel=["']https:\/\/api\.w\.org\/["']/i.test(html)) {
    addSignal(signals, "REST API link tag", 30, "Found <link rel=\"https://api.w.org/\">");
  }

  if (lower.includes("wp-emoji-release") || lower.includes("wpemojisettings")) {
    addSignal(signals, "WP emoji script", 15, "Found wp-emoji script/settings");
  }

  if (lower.includes("/wp-json/")) {
    addSignal(signals, "wp-json reference", 10, "Found '/wp-json/' reference in markup");
  }

  if (/class=["'][^"']*\bwordpress\b/i.test(html)) {
    addSignal(signals, "wordpress body class", 10, "Found a 'wordpress' CSS class");
  }
}

function analyzeWpJson(json, signals) {
  if (!json) return;
  const namespaces = json.namespaces;
  if (Array.isArray(namespaces) && namespaces.some((ns) => /^wp\/v\d+/i.test(ns))) {
    addSignal(signals, "REST API namespaces", 45, `namespaces: ${namespaces.join(", ")}`);
  } else if (json.name || json.description) {
    addSignal(signals, "REST API root responded", 15, "wp-json root returned JSON but no wp/v* namespace");
  }
}

function analyzeReadme(text, signals) {
  if (/semantic personal publishing platform/i.test(text) || /wordpress/i.test(text)) {
    addSignal(signals, "readme.html", 35, "readme.html matches WordPress signature");
  }
}

function analyzeXmlrpc(status, text, signals) {
  if (status === 405 || /xml-rpc server accepts post requests only/i.test(text)) {
    addSignal(signals, "xmlrpc.php", 20, "xmlrpc.php responded like WordPress");
  }
}

function classify(score) {
  if (score >= 40) return "wordpress";
  if (score >= 15) return "uncertain";
  return "not-wordpress";
}

export async function detectWordPress(rawUrl) {
  const origin = normalizeUrl(rawUrl);
  const signals = [];
  const errors = [];

  const homepage = await safeFetchText(origin + "/");
  if (homepage.ok) {
    analyzeHomepage(homepage.text, signals);
  } else {
    errors.push(`Homepage fetch failed${homepage.status ? " (HTTP " + homepage.status + ")" : ""}`);
  }

  const [wpJsonRes, readmeRes, xmlrpcRes] = await Promise.all([
    safeFetchText(origin + "/wp-json/"),
    safeFetchText(origin + "/readme.html"),
    safeFetchText(origin + "/xmlrpc.php"),
  ]);

  if (wpJsonRes.ok) {
    try {
      analyzeWpJson(JSON.parse(wpJsonRes.text), signals);
    } catch (e) {
      // Not JSON, ignore.
    }
  }

  if (readmeRes.ok) {
    analyzeReadme(readmeRes.text, signals);
  }

  if (xmlrpcRes.ok || xmlrpcRes.status === 405) {
    analyzeXmlrpc(xmlrpcRes.status, xmlrpcRes.text, signals);
  }

  const score = Math.min(100, signals.reduce((sum, s) => sum + s.weight, 0));
  const verdict = classify(score);

  return {
    url: origin,
    verdict,
    score,
    signals,
    errors,
    checkedAt: Date.now(),
  };
}
