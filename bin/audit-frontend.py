#!/usr/bin/env python3
"""Audit the visitor-facing chat for accessibility and small-screen layout.

Measures how many times a live region changes during one streamed answer, which is what a
screen reader would be read, and checks the layout at phone width for overflow and tap
target sizes. Reports findings and fixes nothing.

It found a real defect: streaming rewrote the whole answer into an aria-live region on
every chunk, so one 77 character answer produced fifteen live-region updates. A screen
reader reads the growing answer out on each one. After moving announcements to a dedicated
region that is written once, the same answer produces one update.

Known limitation: this harness does not report the announced text. Reading it here
disagreed with a simpler direct check often enough that the number could not be trusted, so
it is left out rather than reported wrongly.

Preconditions: a running site with the chat on a page, a temporary auto-login helper
writing its key to /tmp/aicfab-demo-key, google-chrome and websocket-client.

Usage: python3 bin/audit-frontend.py [chat-page-path]
"""
import json, os, re, subprocess, sys, time, urllib.parse, urllib.request
import websocket

BASE = "http://localhost:8080"
PAGE = sys.argv[1] if len(sys.argv) > 1 else "/ai-assistant/"
PORT = 9491

# Desktop first, then a common phone size.
VIEWPORTS = [
    ("desktop", 1280, 900),
    ("phone", 390, 844),
]


def start():
    subprocess.run(["pkill", "-f", f"remote-debugging-port={PORT}"], check=False)
    proc = subprocess.Popen([
        "google-chrome", "--headless=new", "--no-sandbox", "--disable-gpu",
        "--disable-dev-shm-usage", "--hide-scrollbars", "--window-size=1280,900",
        "--user-data-dir=/tmp/aicfab-chrome-frontend", f"--remote-debugging-port={PORT}",
        f"--remote-allow-origins=http://127.0.0.1:{PORT}", "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(60):
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/version", timeout=1).read()
            return proc
        except Exception:
            time.sleep(0.5)
    raise RuntimeError("chrome did not start")


class Tab:
    def __init__(self):
        page = next(t for t in json.load(urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/list", timeout=5)) if t["type"] == "page")
        self.ws = websocket.create_connection(page["webSocketDebuggerUrl"], timeout=240)
        self.id = 0
        self.send("Page.enable")
        self.send("Runtime.enable")
        self.send(
            "Page.addScriptToEvaluateOnNewDocument",
            {
                "source": (
                    "window.aicfabRoot = function () {"
                    "  var all = Array.prototype.slice.call(document.querySelectorAll('.ai-chat-bedrock-container'));"
                    "  var inline = all.filter(function (c) { return !c.closest('.ai-chat-bedrock-popup'); });"
                    "  return inline[0] || all[0] || document.body;"
                    "};"
                )
            },
        )

    def send(self, method, params=None):
        self.id += 1
        self.ws.send(json.dumps({"id": self.id, "method": method, "params": params or {}}))
        while True:
            msg = json.loads(self.ws.recv())
            if msg.get("id") == self.id:
                if "error" in msg:
                    raise RuntimeError(f"{method}: {msg['error']}")
                return msg.get("result", {})

    def ev(self, expr):
        return self.send("Runtime.evaluate", {"expression": expr, "returnByValue": True, "awaitPromise": True}).get("result", {}).get("value")

    def go(self, url, settle=3.0):
        self.send("Page.navigate", {"url": url})
        time.sleep(settle)

    def viewport(self, width, height):
        self.send("Emulation.setDeviceMetricsOverride", {
            "width": width, "height": height, "deviceScaleFactor": 1,
            "mobile": width < 600,
        })
        time.sleep(0.8)


ANNOUNCEMENT_PROBE = r"""
(function () {
  window.__aicfabMutations = 0;
  window.__aicfabAnnounced = [];
  window.__aicfabLiveRegions = [];
  var root = window.aicfabRoot ? window.aicfabRoot() : document;
  var live = root.querySelectorAll('[aria-live]');
  live.forEach(function (node) {
    window.__aicfabLiveRegions.push({
      cls: node.className,
      politeness: node.getAttribute('aria-live'),
      atomic: node.getAttribute('aria-atomic'),
      busy: node.getAttribute('aria-busy')
    });
    var observer = new MutationObserver(function (records) {
      window.__aicfabMutations += records.length;
      var text = (node.textContent || '').trim();
      if (text) {
        window.__aicfabAnnounced.push({ region: node.className, text: text });
      }
    });
    observer.observe(node, { childList: true, characterData: true, subtree: true });
  });
  return JSON.stringify(window.__aicfabLiveRegions);
})()
"""


NAMES_PROBE = r"""
(function () {
  var root = window.aicfabRoot ? window.aicfabRoot() : document;

  function name(el) {
    if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim();
    var by = el.getAttribute('aria-labelledby');
    if (by) {
      var parts = by.split(/\s+/).map(function (id) {
        var n = document.getElementById(id);
        return n ? n.textContent.trim() : '';
      }).filter(Boolean);
      if (parts.length) return parts.join(' ');
    }
    if (el.id) {
      var lab = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
      if (lab && lab.textContent.trim()) return lab.textContent.trim();
    }
    var wrap = el.closest('label');
    if (wrap && wrap.textContent.trim()) return wrap.textContent.trim();
    if (el.textContent && el.textContent.trim()) return el.textContent.trim();
    if (el.value) return String(el.value).trim();
    if (el.getAttribute('title')) return el.getAttribute('title').trim();
    return '';
  }

  var unnamed = [];
  var total = 0;
  var duplicates = {};
  root.querySelectorAll('button, a[href], input, select, textarea, [role="button"]').forEach(function (el) {
    if (el.disabled || el.hidden || el.type === 'hidden') return;
    var box = el.getBoundingClientRect();
    if (box.width === 0 && box.height === 0) return;
    total++;
    var label = name(el);
    if (!label) {
      unnamed.push({ tag: el.tagName.toLowerCase(), cls: el.className, type: el.type || '' });
      return;
    }
    duplicates[label] = (duplicates[label] || 0) + 1;
  });

  var repeated = Object.keys(duplicates).filter(function (k) { return duplicates[k] > 1; })
    .map(function (k) { return k.substring(0, 30) + ' x' + duplicates[k]; });

  return JSON.stringify({ total: total, unnamed: unnamed, repeated: repeated });
})()
"""

LAYOUT_PROBE = r"""
(function () {
  var root = document.querySelector('.ai-chat-bedrock-container') || document.querySelector('.ai-chat-bedrock-messages');
  if (!root) return JSON.stringify({ error: 'chat not found' });

  var doc = document.documentElement;
  var report = {
    horizontal_overflow: doc.scrollWidth > doc.clientWidth,
    doc_scroll_width: doc.scrollWidth,
    doc_client_width: doc.clientWidth,
    chat_width: Math.round(root.getBoundingClientRect().width),
    viewport_width: window.innerWidth,
    small_targets: [],
    overflowing: []
  };

  document.querySelectorAll('.ai-chat-bedrock-container button, .ai-chat-bedrock-container a, .ai-chat-bedrock-container textarea, .ai-chat-bedrock-container select').forEach(function (el) {
    var r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) return;
    // WCAG 2.5.8 asks for at least 24 by 24 CSS pixels.
    if (r.height < 24 || r.width < 24) {
      report.small_targets.push({
        tag: el.tagName.toLowerCase(),
        cls: el.className,
        w: Math.round(r.width),
        h: Math.round(r.height)
      });
    }
    if (r.right > window.innerWidth + 1) {
      report.overflowing.push({ cls: el.className, right: Math.round(r.right) });
    }
  });

  var messages = document.querySelector('.ai-chat-bedrock-messages');
  if (messages) {
    report.messages_height = Math.round(messages.getBoundingClientRect().height);
    report.messages_scrolls = messages.scrollHeight > messages.clientHeight;
  }
  return JSON.stringify(report);
})()
"""


def main():
    proc = start()
    try:
        tab = Tab()
        key = open("/tmp/aicfab-demo-key").read().strip()
        tab.go(f"{BASE}/?aicfab_demo_key={key}&aicfab_to=" + urllib.parse.quote(BASE + PAGE), 3)

        print("=== live regions and what a screen reader hears ===")
        # Clear any stored conversation first, then load the page once. Anything set on
        # window has to happen after the last navigation or it is wiped.
        tab.go(BASE + PAGE, 2)
        tab.ev("try { window.localStorage.clear(); window.sessionStorage.clear(); } catch (e) {} true")
        tab.go(BASE + PAGE, 3)
        containers = tab.ev("document.querySelectorAll('.ai-chat-bedrock-container').length")
        print("  chat containers on the page:", containers)
        print("  using the inline chat:", tab.ev("!window.aicfabRoot().closest('.ai-chat-bedrock-popup')"))
        print("  regions:", tab.ev(ANNOUNCEMENT_PROBE))

        before = tab.ev("window.aicfabRoot().querySelectorAll('.ai-chat-bedrock-message.ai-message').length")
        print("  ai messages before asking:", before)

        tab.ev("window.aicfabRoot().querySelector('.ai-chat-bedrock-textarea').value = 'Name three colours, one per line.'; true")
        tab.ev("window.aicfabRoot().querySelector('.ai-chat-bedrock-submit').click(); true")
        for _ in range(40):
            time.sleep(1.0)
            grown = tab.ev("window.aicfabRoot().querySelectorAll('.ai-chat-bedrock-message.ai-message').length") or 0
            settled = tab.ev("window.aicfabRoot().getAttribute('aria-busy')")
            if grown > (before or 0) and 'false' == settled:
                break

        # A failed request must be reported, not read as zeros.
        error = tab.ev(
            "(function(){var e=window.aicfabRoot().querySelector('.ai-chat-bedrock-error');"
            "return e?e.textContent.trim():'';})()"
        )
        if error:
            print("  THE REQUEST FAILED, so the numbers below mean nothing:")
            print("   ", error[:160])

        print("  live-region updates during one answer:", tab.ev("window.__aicfabMutations"))
        print("  answer characters:", tab.ev(
            "(function(){var m=window.aicfabRoot().querySelectorAll('.ai-chat-bedrock-message.ai-message');"
            "var last=m[m.length-1];return last?last.textContent.trim().length:0;})()"
        ))
        print("  aria-busy after finishing:", tab.ev("window.aicfabRoot().getAttribute('aria-busy')"))

        report = json.loads(tab.ev(NAMES_PROBE) or '{}')
        print("  controls after an answer:", report.get("total"))
        print("  without an accessible name:", report.get("unnamed") or "none")
        # Several controls sharing one name is what made the admin page unusable by keyboard
        # navigation, so it is worth reporting even though it is not strictly a failure.
        print("  names used by more than one control:", report.get("repeated") or "none")
        print("  a dedicated status region exists:", tab.ev("!!window.aicfabRoot().querySelector('.ai-chat-bedrock-announce')"))
        # Whether the announcement was actually made is not measured here: attempts to read
        # it from this harness disagreed with a simpler direct check, so the number would
        # have been misleading. Verify announcement content with a focused check instead.
        print("  announce region is visually hidden:", tab.ev(
            "(function(){var e=window.aicfabRoot().querySelector('.ai-chat-bedrock-announce');if(!e)return false;"
            "var r=e.getBoundingClientRect();return r.width<=1 && r.height<=1;})()"
        ))
        print("  message list is still a live region:", tab.ev("!!window.aicfabRoot().querySelector('.ai-chat-bedrock-messages[aria-live]')"))

        for label, width, height in VIEWPORTS:
            print(f"\n=== layout at {label} {width}x{height} ===")
            tab.viewport(width, height)
            tab.go(BASE + PAGE, 3)
            report = json.loads(tab.ev(LAYOUT_PROBE))
            for key_name in ("error", "horizontal_overflow", "doc_scroll_width", "doc_client_width", "chat_width", "viewport_width", "messages_height"):
                if key_name in report:
                    print(f"  {key_name} = {report[key_name]}")
            print("  tap targets under 24px:", report.get("small_targets") or "none")
            print("  controls past the right edge:", report.get("overflowing") or "none")
    finally:
        proc.terminate()
        subprocess.run(["pkill", "-f", f"remote-debugging-port={PORT}"], check=False)


main()
