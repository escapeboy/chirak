"""Turn rendered public reports into static pages for GitHub Pages.
Usage: export_site.py <app/public/build> <out_dir> <generated_at> <page>=<slug>=<label>=<rendered.html> ...
The first page is the start page; every page links to all the others and to the live demo."""
import re
import shutil
import sys
from pathlib import Path

build, out, at = Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3]
pages = [arg.split("=", 3) for arg in sys.argv[4:]]


def nav(current: str) -> str:
    links = " · ".join(f"<strong>{label}</strong>" if page == current else f'<a class="underline" href="{page}">{label}</a>'
                       for page, _, label, _ in pages)
    return f"Runs: {links}."


(out / "assets").mkdir(parents=True, exist_ok=True)
for page, slug, label, src in pages:
    html = Path(src).read_text()
    html = html.replace("http://localhost/build/assets/", "assets/")
    html = re.sub(r'<a href="http://localhost/runs/new"[^>]*>New run</a>', "", html)
    html = re.sub(r'<form method="POST" action="http://localhost/unlock".*?</form>', "", html, flags=re.S)
    html = html.replace('<a href="http://localhost" ', '<a href="https://github.com/escapeboy/chirak" ', 1)
    html = re.sub(r'<div class="flex items-center gap-3">\s*</div>',
                  '<a href="https://github.com/escapeboy/chirak" class="text-sm font-medium text-slate-700 hover:text-brand-600">Source on GitHub</a>', html, count=1)
    # links to other runs' reports point to their static pages; anything else local goes to the live demo
    for other, other_slug, _, _ in pages:
        html = html.replace(f'href="http://localhost/r/{other_slug}"', f'href="{other}"')
    html = re.sub(r'href="http://localhost/', 'href="https://chirak.fleetq.net/', html)
    banner = ('<div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">'
              'Live demo with a CPU playground: <a class="underline font-medium" href="https://chirak.fleetq.net">chirak.fleetq.net</a>. '
              f'This page is a static snapshot of {label} (<code>{slug}</code>), generated {at} from its stored results. '
              f'{nav(page)} Every number can be re-scored with <code>make eval</code>; see the '
              '<a class="underline" href="https://github.com/escapeboy/chirak/blob/main/docs/demo-run-log.md">run log</a>.</div>')
    html = html.replace('<main class="mx-auto max-w-6xl px-4 py-8">', '<main class="mx-auto max-w-6xl px-4 py-8">\n' + banner, 1)

    left = sorted(set(re.findall(r'(?:href|action|src)="(http://localhost[^"]*)"', html)))
    if left:
        sys.exit(f"{page}: local links left: {left}")
    for name in re.findall(r'assets/(app-[A-Za-z0-9_-]+\.(?:css|js))', html):
        shutil.copy(build / "assets" / name, out / "assets" / name)
    (out / page).write_text(html)
    print("ok", page, len(html))
(out / ".nojekyll").write_text("")
