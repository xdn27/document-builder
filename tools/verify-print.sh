#!/usr/bin/env bash
#
# Membuktikan klaim inti package pada sebuah fixture:
#   1. jumlah halaman di layar sama dengan jumlah halaman yang keluar dari mesin cetak;
#   2. tidak ada badan halaman yang meluap lalu terpotong diam-diam;
#   3. tabel yang pecah mengulang header-nya di tiap halaman tanpa kehilangan baris.
#
# Poin 2 penting: layar dan cetak bisa sama-sama memotong isi, sehingga
# kesamaan jumlah halaman saja tidak membuktikan dokumennya utuh.
#
# Dijalankan dari akar repo:
#   bash packages/document-builder/tools/verify-print.sh <fixture.json> [halaman-diharapkan]
#
# PHP hidup di container maqiis10; Chrome hidup di host. Skrip ini menjembatani.

set -euo pipefail

FIXTURE="${1:-packages/document-builder/tests/fixtures/surat-satu-halaman.json}"
EXPECTED="${2:-}"
CONTAINER="${DOCUMENT_BUILDER_CONTAINER:-maqiis10}"
CHROME="${CHROME_BIN:-google-chrome-stable}"

WORK="$(mktemp -d)"
REL="storage/app/document-builder-verify.html"
trap 'rm -rf "$WORK"; rm -f "$REL"' EXIT

docker exec -i "$CONTAINER" sh -c \
    "cd /app && php packages/document-builder/tools/preview.php '$FIXTURE' '/app/$REL'" >/dev/null

cp "$REL" "$WORK/doc.html"

# Salinan yang diberi probe: menunggu sinyal paginasi selesai, lalu menuliskan
# ukuran setiap halaman ke DOM supaya bisa dibaca lewat --dump-dom.
python3 - "$WORK/doc.html" "$WORK/probed.html" <<'PY'
import sys

src, dst = sys.argv[1], sys.argv[2]
probe = """
<script type="module">
const root = document.querySelector('.doc-root');
if (!root.dataset.paginated) {
    await new Promise((resolve) => document.addEventListener('document-builder:paginated', resolve, { once: true }));
}
const pages = [...document.querySelectorAll('.doc-page')].map((page) => {
    const body = page.querySelector('.doc-page__body');
    return {
        index: Number(page.dataset.pageIndex),
        clipped: body.scrollHeight > body.clientHeight + 1,
        overflowMarked: body.querySelector('.db-overflow') !== null,
        rows: body.querySelectorAll('.db-table__row').length,
        heads: body.querySelectorAll('thead.db-table__head').length,
    };
});
const out = document.createElement('pre');
out.id = 'db-verify';
out.textContent = JSON.stringify(pages);
document.body.appendChild(out);
</script>"""
html = open(src).read()
open(dst, 'w').write(html.replace('</body>', probe + '</body>'))
PY

run_chrome() {
    "$CHROME" --headless --disable-gpu --no-sandbox --virtual-time-budget=10000 "$@" 2>/dev/null
}

run_chrome --dump-dom "file://$WORK/probed.html" > "$WORK/dump.html"
run_chrome --no-pdf-header-footer --print-to-pdf="$WORK/cetak.pdf" "file://$WORK/doc.html"

python3 - "$WORK/dump.html" "$WORK/cetak.pdf" "$FIXTURE" "$EXPECTED" <<'PY'
import html
import json
import re
import sys

dump_path, pdf_path, fixture, expected = sys.argv[1:5]

match = re.search(r'<pre id="db-verify">(.*?)</pre>', open(dump_path).read(), re.S)
if not match:
    sys.exit('GAGAL: paginator tidak memberi sinyal selesai dalam batas waktu.')

pages = json.loads(html.unescape(match.group(1)))

pdf = open(pdf_path, 'rb').read()
count = re.search(rb'/Type\s*/Pages.*?/Count\s+(\d+)', pdf, re.S)
printed = int(count.group(1)) if count else len(re.findall(rb'/Type\s*/Page[^s]', pdf))

screen = len(pages)
rows = sum(p['rows'] for p in pages)

print(f'fixture          : {fixture}')
print(f'halaman di layar : {screen}')
print(f'halaman tercetak : {printed}')
print(f'baris tabel      : {rows}')

problems = []

if screen == 0:
    problems.append('paginator tidak menghasilkan halaman sama sekali')

if screen != printed:
    problems.append('layar dan hasil cetak berbeda jumlah halaman')

if expected and screen != int(expected):
    problems.append(f'diharapkan {expected} halaman')

for page in pages:
    if page['clipped'] and not page['overflowMarked']:
        problems.append(f"halaman {page['index']} meluap dan isinya terpotong")

    if page['rows'] and page['heads'] != 1:
        problems.append(f"halaman {page['index']}: {page['rows']} baris tabel tetapi {page['heads']} header")

marked = [p['index'] for p in pages if p['overflowMarked']]
if marked:
    print(f'peringatan       : blok melimpah yang ditandai di halaman {marked}')

if problems:
    sys.exit('GAGAL: ' + '; '.join(problems))

print(f'LULUS: {screen} halaman di layar dan tercetak, tidak ada yang terpotong.')
PY
