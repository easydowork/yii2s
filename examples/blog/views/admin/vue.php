<?php
use yii\helpers\Html;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= Html::csrfMetaTags() ?>
    <title>Blog Admin</title>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <style>
        :root { --bg:#0f172a; --panel:#1e293b; --panel2:#273449; --accent:#6366f1; --accent2:#818cf8; --text:#e2e8f0; --muted:#94a3b8; --border:#334155; --danger:#ef4444; --ok:#22c55e; --warn:#f59e0b; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:'Inter',system-ui,-apple-system,sans-serif; background:var(--bg); color:var(--text); }
        a { color:var(--accent2); text-decoration:none; }
        .login-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; background:radial-gradient(1200px 600px at 20% 0%, #1e293b, #0f172a); }
        .login-card { background:var(--panel); padding:2.2rem; border-radius:16px; width:360px; box-shadow:0 20px 60px rgba(0,0,0,.5); border:1px solid var(--border); }
        .login-card h1 { margin:0 0 .3rem; font-size:1.4rem; }
        .login-card p { color:var(--muted); margin:0 0 1.5rem; font-size:.9rem; }
        .field { margin-bottom:1rem; }
        .field label { display:block; font-size:.8rem; color:var(--muted); margin-bottom:.35rem; }
        .field input, .field select, .field textarea { width:100%; background:var(--panel2); border:1px solid var(--border); color:var(--text); padding:.6rem .8rem; border-radius:8px; font-size:.95rem; }
        .field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:var(--accent); }
        .btn { display:inline-flex; align-items:center; gap:.4rem; background:var(--accent); color:#fff; border:none; padding:.6rem 1rem; border-radius:8px; cursor:pointer; font-size:.9rem; }
        .btn:hover { background:var(--accent2); }
        .btn.ghost { background:transparent; border:1px solid var(--border); color:var(--text); }
        .btn.danger { background:var(--danger); }
        .btn.sm { padding:.35rem .6rem; font-size:.8rem; }
        .err { color:var(--danger); font-size:.8rem; margin-top:.3rem; }
        .layout { display:flex; min-height:100vh; }
        .sidebar { width:220px; background:var(--panel); border-right:1px solid var(--border); padding:1.2rem 0; }
        .sidebar .brand { padding:0 1.2rem 1rem; font-weight:700; font-size:1.1rem; border-bottom:1px solid var(--border); margin-bottom:1rem; }
        .sidebar .nav { padding:.6rem 1.2rem; cursor:pointer; color:var(--muted); font-size:.9rem; }
        .sidebar .nav:hover { color:var(--text); background:var(--panel2); }
        .sidebar .nav.active { color:#fff; background:var(--accent); border-radius:6px; margin:0 .6rem; }
        .main { flex:1; padding:1.6rem 2rem; }
        .topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.5rem; }
        .topbar .who { color:var(--muted); font-size:.9rem; }
        .cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin-bottom:1.5rem; }
        .card { background:var(--panel); border:1px solid var(--border); border-radius:12px; padding:1.2rem; }
        .card .n { font-size:1.8rem; font-weight:700; }
        .card .l { color:var(--muted); font-size:.8rem; margin-top:.2rem; }
        .toolbar { display:flex; gap:.6rem; margin-bottom:1rem; flex-wrap:wrap; }
        .toolbar input, .toolbar select { background:var(--panel2); border:1px solid var(--border); color:var(--text); padding:.5rem .7rem; border-radius:8px; }
        table { width:100%; border-collapse:collapse; background:var(--panel); border-radius:12px; overflow:hidden; border:1px solid var(--border); }
        th, td { padding:.7rem .9rem; text-align:left; font-size:.9rem; border-bottom:1px solid var(--border); }
        th { background:var(--panel2); color:var(--muted); font-weight:600; }
        tr:last-child td { border-bottom:none; }
        .tag { display:inline-block; padding:.15rem .5rem; border-radius:20px; font-size:.75rem; }
        .tag.published, .tag.approved, .tag.active { background:rgba(34,197,94,.15); color:var(--ok); }
        .tag.draft, .tag.pending { background:rgba(245,158,11,.15); color:var(--warn); }
        .tag.disabled { background:rgba(239,68,68,.15); color:var(--danger); }
        .pager { display:flex; gap:.5rem; align-items:center; margin-top:1rem; color:var(--muted); font-size:.85rem; }
        .modal-mask { position:fixed; inset:0; background:rgba(0,0,0,.6); display:flex; align-items:center; justify-content:center; z-index:10; }
        .modal { background:var(--panel); border:1px solid var(--border); border-radius:16px; padding:1.6rem; width:520px; max-width:90vw; max-height:90vh; overflow:auto; }
        .modal h2 { margin:0 0 1rem; }
        .flash { position:fixed; top:1rem; right:1rem; background:var(--accent); color:#fff; padding:.7rem 1rem; border-radius:8px; z-index:20; }
        .muted { color:var(--muted); }
        .flex { display:flex; gap:.6rem; align-items:center; }
        .spacer { flex:1; }
    </style>
</head>
<body>
<div id="app">
    <!-- 登录 -->
    <div v-if="!user" class="login-wrap">
        <div class="login-card">
            <h1>Blog Admin</h1>
            <p>Vue3 管理后台 · 多用户 + RBAC</p>
            <div class="field">
                <label>用户名</label>
                <input v-model="loginForm.username" @keyup.enter="login" placeholder="admin">
            </div>
            <div class="field">
                <label>密码</label>
                <input v-model="loginForm.password" type="password" @keyup.enter="login" placeholder="admin123">
            </div>
            <div v-if="loginError" class="err">{{ loginError }}</div>
            <button class="btn" style="width:100%" @click="login">登录</button>
            <p style="margin-top:1rem;font-size:.75rem" class="muted">admin/admin123 · editor/editor123 · author/author123</p>
        </div>
    </div>

    <!-- 主界面 -->
    <div v-else class="layout">
        <div class="sidebar">
            <div class="brand">Blog Admin</div>
            <div class="nav" :class="{active:view==='dashboard'}" @click="go('dashboard')">📊 仪表盘</div>
            <div class="nav" :class="{active:view==='posts'}" @click="go('posts')">📝 文章管理</div>
            <div class="nav" :class="{active:view==='comments'}" @click="go('comments')">💬 评论审核</div>
            <div class="nav" v-if="isAdmin" :class="{active:view==='users'}" @click="go('users')">👥 用户管理</div>
        </div>
        <div class="main">
            <div class="topbar">
                <div class="who">你好，<b>{{ user.username }}</b>（{{ user.role_label }}）</div>
                <div class="flex">
                    <a :href="frontUrl" target="_blank" class="btn ghost sm">查看前台</a>
                    <button class="btn ghost sm" @click="logout">退出</button>
                </div>
            </div>

            <!-- 仪表盘 -->
            <div v-if="view==='dashboard'">
                <div class="cards" v-if="stats">
                    <div class="card"><div class="n">{{ stats.posts }}</div><div class="l">文章总数</div></div>
                    <div class="card"><div class="n">{{ stats.published }}</div><div class="l">已发布</div></div>
                    <div class="card"><div class="n">{{ stats.drafts }}</div><div class="l">草稿</div></div>
                    <div class="card"><div class="n">{{ stats.pendingComments }}</div><div class="l">待审核评论</div></div>
                    <div class="card"><div class="n">{{ stats.users }}</div><div class="l">用户</div></div>
                    <div class="card"><div class="n">{{ stats.totalViews }}</div><div class="l">总浏览量</div></div>
                </div>
                <p class="muted">这是一个 Vue3 单页后台，数据通过 /api/admin/* 读取，认证用 session + CSRF。</p>
            </div>

            <!-- 文章 -->
            <div v-if="view==='posts'">
                <div class="toolbar">
                    <input v-model="postsQ" placeholder="搜索标题..." @keyup.enter="loadPosts">
                    <select v-model="postsStatus" @change="loadPosts">
                        <option value="">全部状态</option>
                        <option value="published">已发布</option>
                        <option value="draft">草稿</option>
                    </select>
                    <button class="btn sm" @click="loadPosts">搜索</button>
                    <div class="spacer"></div>
                    <button class="btn sm" @click="openPost()">+ 新建文章</button>
                </div>
                <table>
                    <thead><tr><th>ID</th><th>标题</th><th>作者</th><th>状态</th><th>浏览</th><th>评论</th><th>操作</th></tr></thead>
                    <tbody>
                        <tr v-for="p in posts" :key="p.id">
                            <td>{{ p.id }}</td>
                            <td>{{ p.title }}</td>
                            <td>{{ p.author }}</td>
                            <td><span class="tag" :class="p.status===1?'published':'draft'">{{ p.status===1?'已发布':'草稿' }}</span></td>
                            <td>{{ p.views }}</td>
                            <td>{{ p.comments }}</td>
                            <td class="flex">
                                <button class="btn ghost sm" @click="openPost(p)">编辑</button>
                                <button class="btn danger sm" @click="deletePost(p)">删除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="pager" v-if="postsMeta">
                    <span>共 {{ postsMeta.total }} 条</span>
                    <button class="btn ghost sm" :disabled="postsMeta.page<=1" @click="postsMeta.page--,loadPosts()">上一页</button>
                    <span>{{ postsMeta.page }} / {{ Math.ceil(postsMeta.total/postsMeta.per_page) || 1 }}</span>
                    <button class="btn ghost sm" @click="postsMeta.page++,loadPosts()">下一页</button>
                </div>
            </div>

            <!-- 评论 -->
            <div v-if="view==='comments'">
                <div class="toolbar">
                    <select v-model="commentsStatus" @change="loadComments">
                        <option value="">全部</option>
                        <option value="pending">待审核</option>
                        <option value="approved">已通过</option>
                    </select>
                </div>
                <table>
                    <thead><tr><th>ID</th><th>文章</th><th>作者</th><th>内容</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                        <tr v-for="c in comments" :key="c.id">
                            <td>{{ c.id }}</td>
                            <td>{{ c.post_title }}</td>
                            <td>{{ c.author_name }}</td>
                            <td>{{ c.content }}</td>
                            <td><span class="tag" :class="c.status===1?'approved':'pending'">{{ c.status===1?'已通过':'待审核' }}</span></td>
                            <td class="flex">
                                <button v-if="c.status===0" class="btn sm" @click="approveComment(c)">通过</button>
                                <button class="btn danger sm" @click="deleteComment(c)">删除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="pager" v-if="commentsMeta">
                    <span>共 {{ commentsMeta.total }} 条</span>
                    <button class="btn ghost sm" :disabled="commentsMeta.page<=1" @click="commentsMeta.page--,loadComments()">上一页</button>
                    <button class="btn ghost sm" @click="commentsMeta.page++,loadComments()">下一页</button>
                </div>
            </div>

            <!-- 用户 -->
            <div v-if="view==='users' && isAdmin">
                <div class="toolbar">
                    <input v-model="usersQ" placeholder="搜索用户名..." @keyup.enter="loadUsers">
                    <button class="btn sm" @click="loadUsers">搜索</button>
                    <div class="spacer"></div>
                    <button class="btn sm" @click="openUser()">+ 新建用户</button>
                </div>
                <table>
                    <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>状态</th><th>文章</th><th>操作</th></tr></thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id">
                            <td>{{ u.id }}</td>
                            <td>{{ u.username }}</td>
                            <td>{{ u.email }}</td>
                            <td>{{ u.role_label }}</td>
                            <td><span class="tag" :class="u.status===1?'active':'disabled'">{{ u.status===1?'正常':'禁用' }}</span></td>
                            <td>{{ u.posts }}</td>
                            <td class="flex">
                                <button class="btn ghost sm" @click="openUser(u)">编辑</button>
                                <button class="btn danger sm" @click="deleteUser(u)">删除</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="pager" v-if="usersMeta">
                    <span>共 {{ usersMeta.total }} 条</span>
                    <button class="btn ghost sm" :disabled="usersMeta.page<=1" @click="usersMeta.page--,loadUsers()">上一页</button>
                    <button class="btn ghost sm" @click="usersMeta.page++,loadUsers()">下一页</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 文章弹窗 -->
    <div class="modal-mask" v-if="postModal">
        <div class="modal">
            <h2>{{ postEditing ? '编辑文章' : '新建文章' }}</h2>
            <div class="field"><label>标题</label><input v-model="postForm.title"></div>
            <div class="field"><label>内容</label><textarea v-model="postForm.content" rows="10"></textarea></div>
            <div class="field"><label>状态</label>
                <select v-model="postForm.status">
                    <option :value="1">已发布</option>
                    <option :value="0">草稿</option>
                </select>
            </div>
            <div v-if="postError" class="err">{{ postError }}</div>
            <div class="flex" style="margin-top:1rem">
                <button class="btn" @click="savePost">保存</button>
                <button class="btn ghost" @click="postModal=false">取消</button>
            </div>
        </div>
    </div>

    <!-- 用户弹窗 -->
    <div class="modal-mask" v-if="userModal">
        <div class="modal">
            <h2>{{ userEditing ? '编辑用户' : '新建用户' }}</h2>
            <div class="field"><label>用户名</label><input v-model="userForm.username"></div>
            <div class="field"><label>邮箱</label><input v-model="userForm.email"></div>
            <div class="field" v-if="!userEditing"><label>密码</label><input v-model="userForm.password" type="password"></div>
            <div class="field"><label>角色</label>
                <select v-model="userForm.role">
                    <option value="author">Author</option>
                    <option value="editor">Editor</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="field"><label>状态</label>
                <select v-model="userForm.status">
                    <option :value="1">正常</option>
                    <option :value="0">禁用</option>
                </select>
            </div>
            <div v-if="userError" class="err">{{ userError }}</div>
            <div class="flex" style="margin-top:1rem">
                <button class="btn" @click="saveUser">保存</button>
                <button class="btn ghost" @click="userModal=false">取消</button>
            </div>
        </div>
    </div>

    <div class="flash" v-if="flash">{{ flash }}</div>
</div>

<script>
const { createApp } = Vue;
createApp({
    data() {
        return {
            csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
            frontUrl: '',
            user: null,
            view: 'dashboard',
            loginForm: { username: '', password: '' },
            loginError: '',
            stats: null,
            posts: [], postsMeta: null, postsQ: '', postsStatus: '',
            comments: [], commentsMeta: null, commentsStatus: '',
            users: [], usersMeta: null, usersQ: '',
            postModal: false, postForm: { title: '', content: '', status: 1 }, postEditing: false, postError: '',
            userModal: false, userForm: { username: '', email: '', password: '', role: 'author', status: 1 }, userEditing: false, userError: '',
            flash: '',
        };
    },
    computed: {
        isAdmin() { return this.user && this.user.role === 'admin'; },
    },
    methods: {
        async api(path, method = 'GET', body = null) {
            const opts = { method, headers: {} };
            if (body !== null) {
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            if (this.csrf && method !== 'GET') opts.headers['X-CSRF-Token'] = this.csrf;
            const res = await fetch(path, opts);
            const data = await res.json().catch(() => ({}));
            if (res.status === 401) { this.user = null; this.view = 'dashboard'; throw new Error('未登录'); }
            if (!res.ok) {
                const msg = data?.error?.message || ('请求失败 (' + res.status + ')');
                const errors = data?.errors ? Object.values(data.errors).flat().join('；') : '';
                throw new Error(msg + (errors ? '：' + errors : ''));
            }
            return data;
        },
        flashMsg(m) { this.flash = m; setTimeout(() => this.flash = '', 2000); },
        async init() {
            try {
                const r = await this.api('/api/admin/me');
                if (!r.guest) { this.user = r.user; this.go('dashboard'); }
            } catch (e) {}
        },
        async login() {
            this.loginError = '';
            try {
                const r = await this.api('/api/admin/login', 'POST', { username: this.loginForm.username, password: this.loginForm.password, rememberMe: true });
                this.user = r.user;
                if (r.csrf) this.csrf = r.csrf;
                this.go('dashboard');
            } catch (e) { this.loginError = e.message; }
        },
        async logout() {
            try { await this.api('/api/admin/logout', 'POST'); } catch (e) {}
            this.user = null; this.view = 'dashboard';
        },
        go(v) {
            this.view = v;
            if (v === 'dashboard') this.loadDashboard();
            if (v === 'posts') this.loadPosts();
            if (v === 'comments') this.loadComments();
            if (v === 'users') this.loadUsers();
        },
        async loadDashboard() { this.stats = await this.api('/api/admin/dashboard'); },
        async loadPosts() {
            const q = new URLSearchParams({ page: this.postsMeta?.page || 1, per_page: 20, q: this.postsQ, status: this.postsStatus });
            const r = await this.api('/api/admin/posts?' + q);
            this.posts = r.data; this.postsMeta = r.meta;
        },
        openPost(p) {
            this.postEditing = !!p;
            this.postForm = p ? { id: p.id, title: p.title, content: p.content, status: p.status } : { title: '', content: '', status: 1 };
            this.postError = ''; this.postModal = true;
        },
        async savePost() {
            this.postError = '';
            try {
                if (this.postEditing) await this.api('/api/admin/posts/' + this.postForm.id, 'PUT', this.postForm);
                else await this.api('/api/admin/posts', 'POST', this.postForm);
                this.postModal = false; this.flashMsg('已保存'); this.loadPosts();
            } catch (e) { this.postError = e.message; }
        },
        async deletePost(p) {
            if (!confirm('删除文章「' + p.title + '」？')) return;
            await this.api('/api/admin/posts/' + p.id, 'DELETE');
            this.flashMsg('已删除'); this.loadPosts();
        },
        async loadComments() {
            const q = new URLSearchParams({ page: this.commentsMeta?.page || 1, per_page: 20, status: this.commentsStatus });
            const r = await this.api('/api/admin/comments?' + q);
            this.comments = r.data; this.commentsMeta = r.meta;
        },
        async approveComment(c) {
            await this.api('/api/admin/comments/' + c.id + '/approve', 'POST');
            this.flashMsg('评论已通过'); this.loadComments();
        },
        async deleteComment(c) {
            if (!confirm('删除这条评论？')) return;
            await this.api('/api/admin/comments/' + c.id, 'DELETE');
            this.flashMsg('已删除'); this.loadComments();
        },
        async loadUsers() {
            const q = new URLSearchParams({ page: this.usersMeta?.page || 1, per_page: 20, q: this.usersQ });
            const r = await this.api('/api/admin/users?' + q);
            this.users = r.data; this.usersMeta = r.meta;
        },
        openUser(u) {
            this.userEditing = !!u;
            this.userForm = u ? { id: u.id, username: u.username, email: u.email || '', role: u.role, status: u.status } : { username: '', email: '', password: '', role: 'author', status: 1 };
            this.userError = ''; this.userModal = true;
        },
        async saveUser() {
            this.userError = '';
            try {
                if (this.userEditing) await this.api('/api/admin/users/' + this.userForm.id, 'PUT', this.userForm);
                else await this.api('/api/admin/users', 'POST', this.userForm);
                this.userModal = false; this.flashMsg('已保存'); this.loadUsers();
            } catch (e) { this.userError = e.message; }
        },
        async deleteUser(u) {
            if (!confirm('删除用户「' + u.username + '」？其文章也会一并删除')) return;
            await this.api('/api/admin/users/' + u.id, 'DELETE');
            this.flashMsg('已删除'); this.loadUsers();
        },
    },
    mounted() {
        this.frontUrl = window.location.origin + '/';
        this.init();
    },
}).mount('#app');
</script>
</body>
</html>
