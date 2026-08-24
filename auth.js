/* ============================================================
   永恒流光 · 登录前端
   ------------------------------------------------------------
   两个页面各加一行 <script src="auth.js" defer></script> 即可。
   样式和弹窗都由本文件注入，不用改 HTML 结构。

   接口没部署时会自动隐藏登录入口，页面照常显示 —— 静态站
   本来就该能独立打开。
   ============================================================ */
(function () {
  'use strict';

  var API = 'api/';          // 接口目录，和网页同级
  var S = { user: null, csrf: '', feat: {} };

  /* ───── 工具 ───── */
  function $(s, r) { return (r || document).querySelector(s); }
  function el(tag, cls, txt) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt != null) e.textContent = txt;   // 一律用 textContent，不拼 HTML
    return e;
  }
  function api(path, opt) {
    opt = opt || {};
    opt.credentials = 'same-origin';        // 带 cookie
    opt.headers = opt.headers || {};
    if (opt.body) {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = S.csrf;
      opt.body = JSON.stringify(opt.body);
      opt.method = opt.method || 'POST';
    }
    return fetch(API + path, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: '服务器返回了无法解析的内容' }; })
        .then(function (j) { j._status = r.status; return j; });
    });
  }
  function toast(m) {
    var t = $('#toast');
    if (!t) { return; }
    t.textContent = m; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 2600);
  }

  /* ───── 样式 ───── */
  var CSS = [
    /* 实心渐变，跟站点原有的主按钮同一套语言，作为导航栏最右的主操作 */
    '.au-btn{font-family:inherit;font-size:13px;font-weight:800;color:#fff;border:none;border-radius:10px;',
    'padding:9px 20px;cursor:pointer;white-space:nowrap;flex-shrink:0;',
    'background:linear-gradient(135deg,var(--sun),var(--orange));',
    'box-shadow:0 6px 18px rgba(239,125,60,.32);transition:transform .15s,box-shadow .2s;}',
    '.au-btn:hover{transform:translateY(-1px);box-shadow:0 9px 24px rgba(239,125,60,.45);}',
    '.au-btn.off{background:transparent;color:var(--dim);border:1.5px solid var(--line2);',
    'box-shadow:none;font-weight:700;}',
    '.au-btn.off:hover{transform:none;box-shadow:none;border-color:var(--orange);color:var(--orange);}',
    '.au-me{position:relative;display:flex;align-items:center;gap:7px;cursor:pointer;padding:4px 6px;border-radius:20px;}',
    '.au-me:hover{background:rgba(244,165,33,.1);}',
    '.au-av{width:28px;height:28px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;',
    'justify-content:center;font-size:13px;font-weight:900;color:#fff;',
    'background:linear-gradient(135deg,var(--sun),var(--orange));}',
    '.au-nm{font-size:13px;font-weight:800;color:var(--ink);max-width:88px;overflow:hidden;',
    'text-overflow:ellipsis;white-space:nowrap;}',
    '.au-drop{display:none;position:absolute;top:calc(100% + 10px);right:0;min-width:170px;padding:6px;',
    'background:var(--cream2);border:1px solid var(--line2);border-radius:14px;',
    'box-shadow:0 14px 36px rgba(60,40,20,.2);z-index:600;}',
    '.au-drop.open{display:block;}',
    '.au-drop button{display:block;width:100%;text-align:left;font-family:inherit;font-size:13.5px;',
    'font-weight:700;color:var(--ink);background:none;border:0;padding:9px 12px;border-radius:9px;cursor:pointer;}',
    '.au-drop button:hover{background:var(--paper);color:var(--orange);}',
    '.au-drop .sep{height:1px;background:var(--line);margin:5px 0;}',
    '.au-drop .out{color:#c0392b;}',
    /* 遮罩与弹窗 */
    '.au-mask{position:fixed;inset:0;background:rgba(60,40,20,.42);z-index:900;opacity:0;pointer-events:none;',
    'transition:opacity .25s;display:flex;align-items:center;justify-content:center;padding:20px;}',
    '.au-mask.open{opacity:1;pointer-events:auto;}',
    '.au-card{width:100%;max-width:380px;background:var(--cream2);border-radius:22px;padding:30px 28px;',
    'box-shadow:0 30px 70px rgba(60,40,20,.3);transform:translateY(14px) scale(.98);transition:transform .25s;',
    'max-height:88vh;overflow:auto;}',
    '.au-mask.open .au-card{transform:none;}',
    '.au-card.wide{max-width:560px;}',
    '.au-h{font-size:21px;font-weight:900;color:var(--ink);margin-bottom:6px;}',
    '.au-p{font-size:13px;color:var(--ink2);line-height:1.75;margin-bottom:20px;}',
    '.au-f{margin-bottom:14px;}',
    '.au-l{display:block;font-size:12.5px;font-weight:800;color:var(--ink2);margin-bottom:6px;}',
    '.au-i{width:100%;height:44px;padding:0 14px;font:inherit;font-size:15px;color:var(--ink);',
    'background:var(--paper);border:1.5px solid var(--line2);border-radius:11px;outline:none;',
    'transition:border-color .15s,box-shadow .15s;}',
    '.au-i:focus{border-color:var(--sun);box-shadow:0 0 0 3px rgba(244,165,33,.15);}',
    'textarea.au-i{height:auto;min-height:130px;padding:11px 14px;line-height:1.7;resize:vertical;}',
    'select.au-i{cursor:pointer;}',
    '.au-go{width:100%;height:46px;font-family:inherit;font-size:15px;font-weight:900;color:#fff;border:0;',
    'border-radius:12px;cursor:pointer;background:linear-gradient(135deg,var(--sun),var(--orange));',
    'box-shadow:0 8px 22px rgba(239,125,60,.3);transition:transform .15s,opacity .2s;margin-top:4px;}',
    '.au-go:hover{transform:translateY(-1px);}',
    '.au-go:disabled{opacity:.6;cursor:not-allowed;transform:none;}',
    '.au-x{position:absolute;top:14px;right:16px;width:30px;height:30px;border:0;background:none;',
    'font-size:22px;line-height:1;color:var(--dim);cursor:pointer;border-radius:8px;}',
    '.au-x:hover{background:var(--paper);color:var(--ink);}',
    '.au-wrap{position:relative;}',
    '.au-err{font-size:13px;color:#c0392b;background:rgba(192,57,43,.09);border:1px solid rgba(192,57,43,.25);',
    'border-radius:10px;padding:10px 13px;margin-bottom:14px;line-height:1.6;display:none;}',
    '.au-err.show{display:block;}',
    '.au-note{font-size:12px;color:var(--dim);line-height:1.7;margin-top:14px;text-align:center;}',
    /* 资料页 */
    '.au-rows{display:grid;gap:1px;background:var(--line);border-radius:12px;overflow:hidden;margin-bottom:6px;}',
    '.au-row{display:flex;justify-content:space-between;gap:14px;padding:12px 15px;background:var(--cream2);}',
    '.au-row .k{font-size:13px;color:var(--ink2);}',
    '.au-row .v{font-size:13px;font-weight:800;color:var(--ink);text-align:right;}',
    '.au-sub{margin-top:18px;}',
    '.au-sub-i{border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:8px;}',
    '.au-sub-t{font-size:13.5px;font-weight:800;color:var(--ink);margin-bottom:5px;}',
    '.au-tag{display:inline-block;font-size:11px;font-weight:900;padding:2px 8px;border-radius:99px;}',
    '.au-tag.p{background:rgba(244,165,33,.18);color:#a06a10;}',
    '.au-tag.a{background:rgba(108,186,90,.18);color:#3f7e33;}',
    '.au-tag.r{background:rgba(192,57,43,.14);color:#a03028;}',
    '@media(max-width:768px){.au-card{padding:24px 20px;}}'
  ].join('');

  var style = el('style'); style.textContent = CSS;
  document.head.appendChild(style);

  /* ───── 弹窗骨架 ───── */
  var mask = el('div', 'au-mask');
  mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
  document.body.appendChild(mask);

  function open(node) {
    mask.innerHTML = '';
    var wrap = el('div', 'au-card au-wrap');
    if (node._wide) wrap.classList.add('wide');
    var x = el('button', 'au-x', '×');
    x.setAttribute('aria-label', '关闭');
    x.addEventListener('click', close);
    wrap.appendChild(x);
    wrap.appendChild(node);
    mask.appendChild(wrap);
    mask.classList.add('open');
    document.body.style.overflow = 'hidden';
    var f = wrap.querySelector('input,select,textarea');
    if (f) setTimeout(function () { f.focus(); }, 60);
  }
  function close() {
    mask.classList.remove('open');
    document.body.style.overflow = '';
  }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && mask.classList.contains('open')) close();
  });

  /* ───── 登录弹窗 ───── */
  function loginDialog() {
    var box = el('div');
    box.appendChild(el('div', 'au-h', '登录'));
    box.appendChild(el('div', 'au-p', '用你的游戏 ID 和密码登录，和进服用的是同一个账号。'));

    var err = el('div', 'au-err');
    box.appendChild(err);

    var form = el('form');

    var f1 = el('div', 'au-f');
    var l1 = el('label', 'au-l', '游戏 ID');
    l1.setAttribute('for', 'auU');
    var i1 = el('input', 'au-i');
    i1.id = 'auU'; i1.type = 'text'; i1.autocomplete = 'username';
    i1.placeholder = '你在服务器里的 ID';
    f1.appendChild(l1); f1.appendChild(i1);

    var f2 = el('div', 'au-f');
    var l2 = el('label', 'au-l', '密码');
    l2.setAttribute('for', 'auP');
    var i2 = el('input', 'au-i');
    i2.id = 'auP'; i2.type = 'password'; i2.autocomplete = 'current-password';
    i2.placeholder = '进服时用的密码';
    f2.appendChild(l2); f2.appendChild(i2);

    var go = el('button', 'au-go', '登录');
    go.type = 'submit';

    form.appendChild(f1); form.appendChild(f2); form.appendChild(go);
    box.appendChild(form);

    box.appendChild(el('div', 'au-note',
      '忘记密码请在游戏里用 /changepassword 修改，或联系管理组。网站不能改密码。'));

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.classList.remove('show');
      var u = i1.value.trim(), p = i2.value;
      if (!u || !p) { err.textContent = '请填写游戏 ID 和密码'; err.classList.add('show'); return; }

      go.disabled = true; go.textContent = '登录中…';
      api('login.php', { body: { username: u, password: p } }).then(function (j) {
        go.disabled = false; go.textContent = '登录';
        if (!j.ok) {
          err.textContent = j.message || '登录失败';
          err.classList.add('show');
          i2.value = ''; i2.focus();
          return;
        }
        S.user = j.user;
        if (j.csrf) S.csrf = j.csrf;
        render();
        close();
        toast('欢迎回来，' + j.user.name);
      }).catch(function () {
        go.disabled = false; go.textContent = '登录';
        err.textContent = '连不上服务器，请检查网络后重试';
        err.classList.add('show');
      });
    });

    return box;
  }

  /* ───── 个人资料 ───── */
  function fmtDate(sec) {
    if (!sec) return '—';
    var d = new Date(sec * 1000);
    return d.getFullYear() + '-' +
      String(d.getMonth() + 1).padStart(2, '0') + '-' +
      String(d.getDate()).padStart(2, '0');
  }
  function profileDialog() {
    var box = el('div');
    box._wide = true;
    box.appendChild(el('div', 'au-h', '我的资料'));
    var p = el('div', 'au-p', '加载中…');
    box.appendChild(p);

    api('me.php?profile=1').then(function (j) {
      if (!j.ok || !j.profile) { p.textContent = '读取失败，请稍后再试'; return; }
      p.remove();
      var d = j.profile;

      var rows = el('div', 'au-rows');
      function row(k, v) {
        var r = el('div', 'au-row');
        r.appendChild(el('span', 'k', k));
        r.appendChild(el('span', 'v', v));
        rows.appendChild(r);
      }
      row('游戏 ID', d.name);
      row('注册时间', fmtDate(d.regdate));
      row('最后登录', fmtDate(d.lastlogin));
      if (d.days != null) row('入住天数', d.days + ' 天');
      if (d.regip)  row('注册 IP', d.regip);
      if (d.lastip) row('最后登录 IP', d.lastip);
      box.appendChild(rows);

      /* 我的投稿 */
      if (S.feat.wiki_submit) {
        var sec = el('div', 'au-sub');
        sec.appendChild(el('div', 'au-h', '我的投稿'));
        var list = el('div');
        list.appendChild(el('div', 'au-p', '加载中…'));
        sec.appendChild(list);
        box.appendChild(sec);

        api('wiki.php?action=mine').then(function (r) {
          list.innerHTML = '';
          if (!r.ok) { list.appendChild(el('div', 'au-p', '读取失败')); return; }
          if (!r.items.length) {
            list.appendChild(el('div', 'au-p', '还没有投过词条。看到 Wiki 里缺什么，欢迎补一条。'));
            return;
          }
          r.items.forEach(function (it) {
            var c = el('div', 'au-sub-i');
            c.appendChild(el('div', 'au-sub-t', it.title));
            var meta = el('div');
            var map = { pending: ['p', '待审核'], approved: ['a', '已发布'], rejected: ['r', '已退回'] };
            var m = map[it.status] || ['p', it.status];
            var tag = el('span', 'au-tag ' + m[0], m[1]);
            meta.appendChild(tag);
            meta.appendChild(el('span', '', '  ' + String(it.created).slice(0, 10)));
            meta.style.fontSize = '12px';
            meta.style.color = 'var(--dim)';
            c.appendChild(meta);
            if (it.note) {
              var n = el('div', '', '审核意见：' + it.note);
              n.style.cssText = 'font-size:12.5px;color:var(--ink2);margin-top:7px;line-height:1.6;';
              c.appendChild(n);
            }
            list.appendChild(c);
          });
        });
      }
    }).catch(function () { p.textContent = '连不上服务器'; });

    return box;
  }

  /* ───── 投稿弹窗 ───── */
  var CATS = [
    ['start', '进服与客户端'], ['land', '领地与家园'], ['money', '经济与交易'],
    ['play', '玩法与世界'], ['build', '建造与红石'], ['trouble', '设置与疑难'],
    ['rule', '规则与反馈']
  ];
  function submitDialog() {
    var box = el('div');
    box._wide = true;
    box.appendChild(el('div', 'au-h', '投一条词条'));
    box.appendChild(el('div', 'au-p',
      '把你踩过的坑写下来，管理组看过就会发布。下一个卡在同样问题上的人会谢谢你。'));

    var err = el('div', 'au-err');
    box.appendChild(err);
    var form = el('form');

    var fc = el('div', 'au-f');
    fc.appendChild(el('label', 'au-l', '分类'));
    var sel = el('select', 'au-i');
    CATS.forEach(function (c) {
      var o = el('option', null, c[1]); o.value = c[0]; sel.appendChild(o);
    });
    fc.appendChild(sel);

    var ft = el('div', 'au-f');
    ft.appendChild(el('label', 'au-l', '标题'));
    var it = el('input', 'au-i');
    it.type = 'text'; it.maxLength = 60;
    it.placeholder = '一句话说清这条讲什么，比如「怎么圈一块自己的地」';
    ft.appendChild(it);

    var fs = el('div', 'au-f');
    fs.appendChild(el('label', 'au-l', '摘要'));
    var isum = el('input', 'au-i');
    isum.type = 'text'; isum.maxLength = 200;
    isum.placeholder = '两三句话概括结论，让人不点开也能知道答案';
    fs.appendChild(isum);

    var fb = el('div', 'au-f');
    fb.appendChild(el('label', 'au-l', '正文'));
    var ib = el('textarea', 'au-i');
    ib.maxLength = 8000;
    ib.placeholder = '按步骤写清楚。指令单独一行，我们会自动排版。';
    fb.appendChild(ib);

    var go = el('button', 'au-go', '提交投稿');
    go.type = 'submit';

    form.appendChild(fc); form.appendChild(ft); form.appendChild(fs);
    form.appendChild(fb); form.appendChild(go);
    box.appendChild(form);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.classList.remove('show');
      go.disabled = true; go.textContent = '提交中…';
      api('wiki.php', {
        body: {
          cat: sel.value, title: it.value.trim(),
          summary: isum.value.trim(), body: ib.value.trim()
        }
      }).then(function (j) {
        go.disabled = false; go.textContent = '提交投稿';
        if (!j.ok) { err.textContent = j.message || '提交失败'; err.classList.add('show'); return; }
        close();
        toast(j.message || '投稿已提交');
      }).catch(function () {
        go.disabled = false; go.textContent = '提交投稿';
        err.textContent = '连不上服务器';
        err.classList.add('show');
      });
    });

    return box;
  }

  /* ───── 导航栏渲染 ───── */
  function render() {
    /* 桌面端插在 .nav-r 里，手机端插在抽屉里 */
    var slot = $('.nav-r');
    if (slot) {
      var old = slot.querySelector('.au-btn, .au-me');
      if (old) old.remove();
      slot.appendChild(S.user ? meNode() : loginBtn());
    }
    var drawer = $('#drawer');
    if (drawer) {
      var od = drawer.querySelector('.au-dw');
      if (od) od.remove();
      var b = el('button', 'nl au-dw', S.user ? ('我的资料（' + S.user.name + '）') : '登录');
      b.style.cssText = 'font-family:inherit;font-size:15px;font-weight:500;color:var(--ink2);' +
        'background:none;border:none;text-align:left;padding:12px 14px;border-radius:11px;cursor:pointer;';
      b.addEventListener('click', function () {
        var ham = $('#ham');
        if (ham && ham.classList.contains('open')) ham.click();
        if (S.user) { open(profileDialog()); } else { startLogin(); }
      });
      var sep = drawer.querySelector('.drawer-sep');
      if (sep) drawer.insertBefore(b, sep); else drawer.appendChild(b);
    }
  }

  function loginBtn() {
    var b = el('button', 'au-btn', '登录');
    /* 接口探测失败时按钮不消失，只是变成描边样式并给出说明 ——
       否则光看页面无法分辨「按钮没做」和「后端没装」。 */
    if (S.apiReady === false) b.className = 'au-btn off';
    b.addEventListener('click', startLogin);
    return b;
  }

  function startLogin() {
    if (S.apiReady === false) {
      toast('登录接口还没部署。把 api/ 目录传到服务器并填好 config.php 就能用了');
      return;
    }
    open(loginDialog());
  }

  function meNode() {
    var wrap = el('div', 'au-me');
    var av = el('div', 'au-av', S.user.name.slice(0, 1).toUpperCase());
    var nm = el('span', 'au-nm', S.user.name);
    var drop = el('div', 'au-drop');

    if (S.feat.profile) {
      var b1 = el('button', null, '我的资料');
      b1.addEventListener('click', function (e) {
        e.stopPropagation(); drop.classList.remove('open'); open(profileDialog());
      });
      drop.appendChild(b1);
    }
    if (S.feat.wiki_submit) {
      var b2 = el('button', null, '投一条词条');
      b2.addEventListener('click', function (e) {
        e.stopPropagation(); drop.classList.remove('open'); open(submitDialog());
      });
      drop.appendChild(b2);
    }
    drop.appendChild(el('div', 'sep'));
    var b3 = el('button', 'out', '退出登录');
    b3.addEventListener('click', function (e) {
      e.stopPropagation();
      api('logout.php', { body: {} }).then(function () {
        S.user = null; render(); toast('已退出登录');
      });
    });
    drop.appendChild(b3);

    wrap.appendChild(av); wrap.appendChild(nm); wrap.appendChild(drop);
    wrap.addEventListener('click', function (e) {
      e.stopPropagation(); drop.classList.toggle('open');
    });
    document.addEventListener('click', function () { drop.classList.remove('open'); });
    return wrap;
  }

  /* ───── 让 wiki.html 的「参与贡献」按钮走投稿流程 ───── */
  function hookWikiButtons() {
    ['contribBtn', 'contribBtn2'].forEach(function (id) {
      var b = document.getElementById(id);
      if (!b) return;
      var fresh = b.cloneNode(true);          // 去掉原来的 showToast 提示
      b.parentNode.replaceChild(fresh, b);
      fresh.addEventListener('click', function (e) {
        e.preventDefault();
        if (!S.feat.wiki_submit) { toast('投稿功能未开启'); return; }
        if (!S.user) { toast('先登录才能投稿'); open(loginDialog()); return; }
        open(submitDialog());
      });
    });
  }

  /* ───── 启动 ─────
     先立刻把登录按钮画到导航栏右上，不等接口。
     否则后端没装时页面上什么都看不到，会误以为功能没做。
     接口探测回来后再决定是保持「登录」还是换成头像。 */
  S.apiReady = null;                          // null=探测中 true=可用 false=不可用
  render();

  api('me.php').then(function (j) {
    if (!j || !j.ok) { S.apiReady = false; render(); return; }
    S.apiReady = true;
    S.user = j.user; S.csrf = j.csrf || ''; S.feat = j.feat || {};
    render();
    hookWikiButtons();
  }).catch(function () {
    /* 接口不可用（多为还没部署，或直接双击打开了 html 文件）。
       按钮留着但变灰，点了会说明原因。页面其余部分照常可用。 */
    S.apiReady = false;
    render();
  });
})();
