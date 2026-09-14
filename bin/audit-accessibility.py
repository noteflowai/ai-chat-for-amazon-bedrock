#!/usr/bin/env python3
"""Audit the plugin admin screens for accessibility basics.

Checks that every interactive control has an accessible name, headings are not skipped,
tables have scoped header cells, and status regions are announced. Reports findings and
fixes nothing.

This needs a running site and a headless Chrome, so it is a manual check rather than part
of the release gate. It has found real defects twice: twenty controls with no accessible
name, and four rows of a generated table whose fields all carried the identical name
"Page title", which a screen reader cannot tell apart.

Preconditions:
  - a WordPress site at BASE with the plugin active
  - a temporary auto-login helper writing its key to /tmp/aicfab-demo-key
  - google-chrome, websocket-client

Usage: python3 bin/audit-accessibility.py
"""
import json, os, subprocess, time, urllib.parse, urllib.request
import websocket

PORT = 9461
BASE = "http://localhost:8080"

SCREENS = [
    ("Chat settings (per-role limits)", "/wp-admin/admin.php?page=ai-chat-for-amazon-bedrock-settings&tab=chat"),
    ("Diagnostics (IAM policy)", "/wp-admin/admin.php?page=ai-chat-for-amazon-bedrock-diagnostics"),
    ("Site Pages (scaffold)", "/wp-admin/admin.php?page=ai-chat-for-amazon-bedrock-scaffold"),
]

AUDIT = r"""
(function () {
  function name(el) {
    if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim();
    var labelledby = el.getAttribute('aria-labelledby');
    if (labelledby) {
      var parts = labelledby.split(/\s+/).map(function (id) {
        var n = document.getElementById(id);
        return n ? n.textContent.trim() : '';
      }).filter(Boolean);
      if (parts.length) return parts.join(' ');
    }
    if (el.id) {
      var lab = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
      if (lab && lab.textContent.trim()) return lab.textContent.trim();
    }
    var wrapping = el.closest('label');
    if (wrapping && wrapping.textContent.trim()) return wrapping.textContent.trim();
    if (el.tagName === 'BUTTON' && el.textContent.trim()) return el.textContent.trim();
    if (el.tagName === 'INPUT' && (el.type === 'submit' || el.type === 'button') && el.value) return el.value.trim();
    if (el.getAttribute('title')) return el.getAttribute('title').trim();
    return '';
  }

  var report = { unnamed: [], controls: 0, headings: [], tables: [], status: 0, placeholderOnly: [] };

  document.querySelectorAll('input, select, textarea, button, [role="button"]').forEach(function (el) {
    if (el.type === 'hidden' || el.disabled) return;
    if (el.closest('#adminmenumain, #wpadminbar, #screen-meta, #wpfooter')) return;
    report.controls++;
    var n = name(el);
    if (!n) {
      report.unnamed.push({
        tag: el.tagName.toLowerCase(),
        type: el.type || '',
        id: el.id || '',
        nm: el.name || '',
        placeholder: el.getAttribute('placeholder') || ''
      });
    } else if (!el.id && !el.getAttribute('aria-label') && el.getAttribute('placeholder') && !el.closest('label')) {
      report.placeholderOnly.push(el.name || el.tagName.toLowerCase());
    }
  });

  document.querySelectorAll('.wrap h1, .wrap h2, .wrap h3, .wrap h4').forEach(function (h) {
    if (h.closest('#screen-meta')) return;
    report.headings.push(parseInt(h.tagName.substring(1), 10));
  });

  document.querySelectorAll('.wrap table').forEach(function (t) {
    report.tables.push({
      headerCells: t.querySelectorAll('th').length,
      rowScoped: t.querySelectorAll('th[scope="row"]').length,
      colScoped: t.querySelectorAll('th[scope="col"]').length,
      rows: t.querySelectorAll('tbody tr').length
    });
  });

  report.status = document.querySelectorAll('[role="status"], [aria-live]').length;
  return JSON.stringify(report);
})()
"""


def start():
    subprocess.run(["pkill", "-f", f"remote-debugging-port={PORT}"], check=False)
    proc = subprocess.Popen([
        "google-chrome", "--headless=new", "--no-sandbox", "--disable-gpu",
        "--disable-dev-shm-usage", "--hide-scrollbars", "--window-size=1440,958",
        "--user-data-dir=/tmp/aicfab-chrome-a11y", f"--remote-debugging-port={PORT}",
        f"--remote-allow-origins=http://127.0.0.1:{PORT}", "about:blank",
    ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(60):
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/version", timeout=1).read()
            return proc
        except Exception:
            time.sleep(0.5)
    raise RuntimeError("chrome did not start")


def main():
    proc = start()
    try:
        page = next(t for t in json.load(urllib.request.urlopen(f"http://127.0.0.1:{PORT}/json/list", timeout=5)) if t["type"] == "page")
        ws = websocket.create_connection(page["webSocketDebuggerUrl"], timeout=180)
        counter = [0]

        def send(method, params=None):
            counter[0] += 1
            ws.send(json.dumps({"id": counter[0], "method": method, "params": params or {}}))
            while True:
                msg = json.loads(ws.recv())
                if msg.get("id") == counter[0]:
                    return msg.get("result", {})

        send("Page.enable")
        send("Runtime.enable")

        def ev(expr):
            return send("Runtime.evaluate", {"expression": expr, "returnByValue": True, "awaitPromise": True}).get("result", {}).get("value")

        def go(url, settle=2.5):
            send("Page.navigate", {"url": url})
            time.sleep(settle)

        key = open("/tmp/aicfab-demo-key").read().strip()
        go(f"{BASE}/?aicfab_demo_key={key}&aicfab_to=" + urllib.parse.quote(f"{BASE}/wp-admin/"), 3)

        total_unnamed = 0
        for label, path in SCREENS:
            go(BASE + path, 3)
            report = json.loads(ev(AUDIT))
            print(f"\n=== {label} ===")
            print(f"  interactive controls: {report['controls']}")
            print(f"  without an accessible name: {len(report['unnamed'])}")
            for item in report["unnamed"]:
                print(f"    - <{item['tag']} type={item['type']!r} id={item['id']!r} name={item['nm']!r} placeholder={item['placeholder']!r}>")
            total_unnamed += len(report["unnamed"])
            if report["placeholderOnly"]:
                print(f"  named only by a placeholder: {report['placeholderOnly']}")
            print(f"  heading levels in order: {report['headings']}")
            skips = [
                (a, b) for a, b in zip(report["headings"], report["headings"][1:]) if b - a > 1
            ]
            print(f"  heading level skips: {skips or 'none'}")
            for i, t in enumerate(report["tables"]):
                print(f"  table {i + 1}: rows={t['rows']} th={t['headerCells']} scope=row:{t['rowScoped']} col:{t['colScoped']}")
            print(f"  live status regions: {report['status']}")

        print(f"\nTOTAL controls without an accessible name: {total_unnamed}")
    finally:
        proc.terminate()
        subprocess.run(["pkill", "-f", f"remote-debugging-port={PORT}"], check=False)


main()
