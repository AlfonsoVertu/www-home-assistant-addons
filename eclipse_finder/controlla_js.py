"""Controlla la sintassi del JavaScript che la pagina servira' davvero.

PERCHE' ESISTE. La pagina sta dentro una stringa Python: py_compile dice solo
che il Python e' valido, e un JS rotto passa indisturbato fino al browser, dove
si vede come "la mappa non si carica" e basta - nessun errore da nessuna parte
che non sia la console. E' gia' successo, con un apostrofo scappato che Python
si e' mangiato prima che arrivasse al browser.
"""
import re, io, sys, subprocess, tempfile, os
src = io.open(sys.argv[1], encoding='utf-8').read()
blocchi = re.findall(r'<script>(.*?)</script>', src, re.S)
if not blocchi:
    print('nessuno <script> trovato'); sys.exit(1)
male = 0
for i, b in enumerate(blocchi):
    if not b.strip(): continue
    # I pezzi cuciti da Python ("' + str(X) + '") diventano un numero: qui si
    # controlla la forma del JS, non il valore che ci finira' dentro.
    b = re.sub(r"'\s*\+\s*str\([^)]*\)\s*\+\s*'", "0", b)
    b = re.sub(r"'\s*\+\s*str\([^)]*\)", "0", b)
    f = os.path.join(tempfile.gettempdir(), 'ecl_%d.js' % i)
    io.open(f, 'w', encoding='utf-8').write(b)
    r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
    if r.returncode:
        male += 1
        print('script %d (%d caratteri): ROTTO' % (i, len(b)))
        print(r.stderr.strip()[:600])
print('js: tutto a posto (%d blocchi)' % len(blocchi) if not male else 'js: %d blocchi rotti' % male)
sys.exit(1 if male else 0)
