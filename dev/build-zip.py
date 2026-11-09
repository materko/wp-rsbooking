"""Zabalí plugin do dist/rezervacie-<verzia>.zip (priečinok rezervacie/ v koreni archívu)."""
import pathlib
import re
import zipfile

root = pathlib.Path(__file__).resolve().parent.parent
src = root / 'rezervacie'
version = re.search(r"define\('RZV_VERSION', '([^']+)'\)", (src / 'rezervacie.php').read_text(encoding='utf-8')).group(1)
dist = root / 'dist'
dist.mkdir(exist_ok=True)
out = dist / f'rezervacie-{version}.zip'

skip = {'.DS_Store', 'Thumbs.db'}
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for f in sorted(src.rglob('*')):
        if f.is_file() and f.name not in skip:
            z.write(f, pathlib.PurePosixPath('rezervacie', *f.relative_to(src).parts))

print(out, f'{out.stat().st_size / 1024:.0f} kB')
