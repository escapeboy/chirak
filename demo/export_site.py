"""Turn the rendered public report into a static page for GitHub Pages.
Usage: export_site.py <rendered.html> <app/public/build> <out_dir> <generated_at>"""
import re
import shutil
import sys
from pathlib import Path

src, build, out, at = sys.argv[1], Path(sys.argv[2]), Path(sys.argv[3]), sys.argv[4]
html = Path(src).read_text()

html = html.replace("http://localhost/build/assets/", "assets/")
html = re.sub(r'<a href="http://localhost/runs/new"[^>]*>New run</a>', "", html)
html = re.sub(r'<form method="POST" action="http://localhost/unlock".*?</form>', "", html, flags=re.S)
html = html.replace('<a href="http://localhost" ', '<a href="https://github.com/escapeboy/chirak" ', 1)
html = re.sub(r'<div class="flex items-center gap-3">\s*</div>',
              '<a href="https://github.com/escapeboy/chirak" class="text-sm font-medium text-slate-700 hover:text-brand-600">Source on GitHub</a>', html, count=1)
banner = ('<div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">'
          f'Static snapshot of the Chirak public report, generated {at} from the stored results of run '
          '<code>merchant-triage-g4oirl</code>. The live playground and new runs need the GPU worker, so they are not part of this page. '
          'Every number can be re-scored with <code>make eval</code>; see the '
          '<a class="underline" href="https://github.com/escapeboy/chirak/blob/main/docs/demo-run-log.md">run log</a>.</div>')
html = html.replace('<main class="mx-auto max-w-6xl px-4 py-8">', '<main class="mx-auto max-w-6xl px-4 py-8">\n' + banner, 1)

left = sorted(set(re.findall(r'(?:href|action|src)="(http://localhost[^"]*)"', html)))
if left:
    sys.exit(f"local links left: {left}")

(out / "assets").mkdir(parents=True, exist_ok=True)
for name in re.findall(r'assets/(app-[A-Za-z0-9_-]+\.(?:css|js))', html):
    shutil.copy(build / "assets" / name, out / "assets" / name)
(out / "index.html").write_text(html)
(out / ".nojekyll").write_text("")
print("ok", len(html), sorted(p.name for p in (out / "assets").iterdir()))
