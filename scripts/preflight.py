#!/usr/bin/env python3
"""
preflight.py — static scan for common plugin/connector security and quality mistakes.

Usage:
    python preflight.py <plugin_dir> [--json] [--fail-on high|medium|low]

Heuristic grep-level checks: expect false positives. Fix or justify each finding.
Exit code 1 if any finding at or above --fail-on (default: high).
"""
import argparse, json, os, re, sys

SKIP_DIRS = {".git", "node_modules", "vendor", "dist", "build", "__pycache__", ".venv", "venv", "coverage"}
SKIP_FILE_HINTS = ("tests/fixtures", ".min.js", ".lock", "package-lock.json", "composer.lock")
LEVELS = {"low": 1, "medium": 2, "high": 3}

# (id, severity, file extensions or "*", regex, message)
RULES = [
    # Secrets (any file)
    ("secret-stripe", "high", "*", r"sk_live_[0-9A-Za-z]{10,}", "Live Stripe secret key"),
    ("secret-aws", "high", "*", r"AKIA[0-9A-Z]{16}", "AWS access key ID"),
    ("secret-github", "high", "*", r"gh[pousr]_[0-9A-Za-z]{30,}", "GitHub token"),
    ("secret-slack", "high", "*", r"xox[baprs]-[0-9A-Za-z-]{10,}", "Slack token"),
    ("secret-google", "high", "*", r"AIza[0-9A-Za-z_\-]{35}", "Google API key"),
    ("secret-privkey", "high", "*", r"-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----", "Private key in repo"),
    ("secret-assign", "medium", "*", r"(?i)(api[_-]?key|secret|password|token)\s*[:=]\s*['\"][^'\"\s]{12,}['\"]", "Possible hardcoded credential"),

    # PHP
    ("php-eval", "high", (".php",), r"\beval\s*\(", "eval() usage"),  # preflight: ignore detector pattern, not a call
    ("php-unserialize", "high", (".php",), r"\bunserialize\s*\(", "unserialize() — use json_decode or allowed_classes=false"),
    ("php-exec", "high", (".php",), r"\b(shell_exec|exec|system|passthru|popen|proc_open)\s*\(", "Shell execution"),
    ("php-echo-input", "high", (".php",), r"echo\s+[^;]*\$_(GET|POST|REQUEST|COOKIE|SERVER)", "Echoing raw request input (XSS)"),
    ("php-sql-concat", "high", (".php",), r"->(query|get_results|get_row|get_var|get_col)\s*\(\s*[\"'][^\"']*\$", "SQL with interpolated variable — use prepare()"),
    ("php-ssl-off", "high", (".php",), r"(sslverify['\"]?\s*=>\s*false|CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0))", "TLS verification disabled"),
    ("php-file-get-url", "medium", (".php",), r"file_get_contents\s*\(\s*['\"]https?://", "file_get_contents on URL — use HTTP client with timeout"),
    ("wp-rest-open", "high", (".php",), r"permission_callback['\"]?\s*=>\s*['\"]__return_true", "REST route open to everyone — verify it is read-only and public"),
    ("magento-objectmanager", "medium", (".php",), r"ObjectManager::getInstance\(\)", "Direct ObjectManager use — inject via constructor"),
    ("php-request-raw", "low", (".php",), r"\$_(GET|POST|REQUEST)\[", "Raw superglobal access — ensure sanitized + nonce/capability checked"),

    # JS / TS
    ("js-eval", "high", (".js", ".ts", ".jsx", ".tsx", ".mjs", ".cjs"), r"\beval\s*\(|new\s+Function\s*\(", "eval/new Function"),
    ("js-innerhtml", "medium", (".js", ".ts", ".jsx", ".tsx", ".mjs"), r"\.innerHTML\s*=|dangerouslySetInnerHTML|outerHTML\s*=|document\.write\s*\(", "HTML injection sink"),
    ("js-tls-off", "high", (".js", ".ts", ".mjs", ".cjs"), r"rejectUnauthorized\s*:\s*false|NODE_TLS_REJECT_UNAUTHORIZED", "TLS verification disabled"),
    ("js-child-process", "medium", (".js", ".ts", ".mjs", ".cjs"), r"child_process|\bexecSync\s*\(|\bexec\s*\(", "Shell execution — no user input allowed"),
    ("js-fetch-no-timeout", "low", (".js", ".ts", ".mjs"), r"\bfetch\s*\(", "fetch() — ensure AbortController timeout"),
    ("js-hmac-compare", "medium", (".js", ".ts", ".mjs"), r"\b(sig|signature|hmac|digest)\w*\s*===?\s*", "Signature compared with ===; use crypto.timingSafeEqual"),

    # Python
    ("py-eval", "high", (".py",), r"(?<![\w.])(eval|exec)\s*\(", "eval/exec usage"),
    ("py-pickle", "high", (".py",), r"pickle\.loads?\s*\(", "pickle on possibly untrusted data"),
    ("py-yaml", "high", (".py",), r"yaml\.load\s*\((?![^)]*SafeLoader)", "yaml.load without SafeLoader"),
    ("py-shell", "high", (".py",), r"shell\s*=\s*True", "subprocess with shell=True"),  # preflight: ignore detector pattern, not a call
    ("py-tls-off", "high", (".py",), r"verify\s*=\s*False", "TLS verification disabled"),
    ("py-requests-no-timeout", "low", (".py",), r"requests\.(get|post|put|patch|delete)\((?![^)]*timeout)", "requests call without timeout"),
    ("py-hmac-compare", "medium", (".py",), r"\b(sig|signature|hmac|digest)\w*\s*==\s*", "Signature compared with ==; use hmac.compare_digest"),

    # Manifests / config
    ("chrome-all-urls", "medium", ("manifest.json",), r"<all_urls>|\"\*://\*/\*\"", "Broad host permission — narrow it"),
    ("vscode-star-activation", "medium", ("package.json",), r"\"activationEvents\"\s*:\s*\[\s*\"\*\"", "Activates on startup ('*')"),
    ("env-committed", "high", (".env",), r".+=.+", ".env file with values committed"),
]

COMPILED = [(i, s, ext, re.compile(rx), m) for i, s, ext, rx, m in RULES]


def applies(ext_spec, path):
    if ext_spec == "*":
        return True
    name = os.path.basename(path)
    return any(name == e or name.endswith(e) for e in ext_spec)


def scan(root):
    findings = []
    for dp, dns, fns in os.walk(root):
        dns[:] = [d for d in dns if d not in SKIP_DIRS]
        for fn in fns:
            p = os.path.join(dp, fn)
            rel = os.path.relpath(p, root)
            if any(h in rel.replace(os.sep, "/") for h in SKIP_FILE_HINTS):
                continue
            try:
                if os.path.getsize(p) > 2_000_000:
                    continue
                with open(p, "r", encoding="utf-8", errors="ignore") as f:
                    lines = f.readlines()
            except OSError:
                continue
            for n, line in enumerate(lines, 1):
                if "preflight: ignore" in line:
                    continue
                for rid, sev, ext, rx, msg in COMPILED:
                    if applies(ext, p) and rx.search(line):
                        findings.append({"rule": rid, "severity": sev, "file": rel, "line": n,
                                         "message": msg, "code": line.strip()[:160]})
    # Structural checks
    checks = [
        ("README", ["README.md", "readme.txt", "README"], "low", "No README"),
        ("CHANGELOG", ["CHANGELOG.md", "CHANGELOG", "readme.txt"], "low", "No changelog"),
    ]
    top = set(os.listdir(root))
    for rid, names, sev, msg in checks:
        if not any(n in top for n in names):
            findings.append({"rule": f"missing-{rid.lower()}", "severity": sev, "file": ".", "line": 0, "message": msg, "code": ""})
    if ".env" in top and not (os.path.exists(os.path.join(root, ".gitignore")) and ".env" in open(os.path.join(root, ".gitignore")).read()):
        findings.append({"rule": "env-not-ignored", "severity": "high", "file": ".gitignore", "line": 0, "message": ".env not in .gitignore", "code": ""})
    return findings


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("path")
    ap.add_argument("--json", action="store_true")
    ap.add_argument("--fail-on", default="high", choices=LEVELS.keys())
    a = ap.parse_args()
    if not os.path.isdir(a.path):
        sys.exit(f"Not a directory: {a.path}")
    f = scan(a.path)
    f.sort(key=lambda x: (-LEVELS[x["severity"]], x["file"], x["line"]))
    if a.json:
        print(json.dumps(f, indent=2))
    else:
        for x in f:
            loc = f"{x['file']}:{x['line']}" if x["line"] else x["file"]
            print(f"[{x['severity'].upper():6}] {x['rule']:24} {loc}\n         {x['message']}" + (f"\n         > {x['code']}" if x["code"] else ""))
        counts = {k: sum(1 for x in f if x["severity"] == k) for k in LEVELS}
        print(f"\n{len(f)} findings — high: {counts['high']}, medium: {counts['medium']}, low: {counts['low']}")
        print("Suppress a justified line with a trailing comment: preflight: ignore")
    sys.exit(1 if any(LEVELS[x["severity"]] >= LEVELS[a.fail_on] for x in f) else 0)


if __name__ == "__main__":
    main()
