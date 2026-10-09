import json, subprocess, os, sys
W=sys.argv[1]; out=sys.argv[2]
lock=json.load(open(os.path.join(W,'composer.lock')))
libl=json.load(open(os.path.join(W,'lib/composer.lock')))
pk=[p for p in lock['packages']]+[p for p in libl['packages']]
os.makedirs(out,exist_ok=True)
res=[]
for p in pk:
    name=p['name']; src=p.get('source') or {}
    url=src.get('url'); ref=src.get('reference')
    d=os.path.join(out,name)
    if p.get('dist',{}).get('type')=='path' or not url or 'github.com' not in (url or ''):
        res.append((name,'SKIP',url)); continue
    if os.path.isdir(d) and os.listdir(d):
        res.append((name,'HAVE',ref)); continue
    os.makedirs(d,exist_ok=True)
    cmds=[['git','init','-q',d],['git','-C',d,'fetch','-q','--depth','1',url,ref],['git','-C',d,'checkout','-q','FETCH_HEAD']]
    ok=True
    for c in cmds:
        r=subprocess.run(c,capture_output=True,text=True,timeout=300,env=dict(os.environ,GIT_LFS_SKIP_SMUDGE='1'))
        if r.returncode: ok=False; res.append((name,'FAIL',r.stderr.strip()[:200])); break
    if ok: res.append((name,'OK',ref[:8]))
for r in res: print(*r)
