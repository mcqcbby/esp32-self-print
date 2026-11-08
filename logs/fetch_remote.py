import ssl, urllib.request, hashlib, io, os
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
out = []
for name, url in [("index", "https://list.zhixiwif.top/index.php"),
                  ("render", "https://list.zhixiwif.top/assets/render.js"),
                  ("app", "https://list.zhixiwif.top/assets/app.js")]:
    try:
        r = urllib.request.urlopen(url, context=ctx, timeout=20)
        data = r.read()
        out.append("%s status=%s len=%d md5=%s" % (name, r.status, len(data), hashlib.md5(data).hexdigest()))
        open(os.path.join(r"D:\wwwroot\esp32\logs", "remote_%s.txt" % name), "wb").write(data)
    except Exception as e:
        out.append("%s ERR %r" % (name, e))
open(r"D:\wwwroot\esp32\logs\remote2.txt","w",encoding="utf-8").write("\n".join(out))
print("\n".join(out))