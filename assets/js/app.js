/**
 * TELEPAGE — app.js  (v2 — 9 fix applicate)
 */
(function () {
    "use strict";

    const state = {
        page:1, tag:window.TELEPAGE_CONFIG.initialTag||"",
        type:window.TELEPAGE_CONFIG.initialType||"", q:window.TELEPAGE_CONFIG.initialSearch||"",
        dateFrom:"", dateTo:"", loading:false, totalResolved:false,
        paginationType:window.TELEPAGE_CONFIG.paginationType||"classic", perPage:12,
    };

    const isAdmin = window.TELEPAGE_CONFIG.isAdmin || false;
    const LANG    = window.TELEPAGE_CONFIG.lang;

    const DOM = {
        grid:        document.getElementById("content-grid"),
        searchInput: document.getElementById("search-input"),
        tagCloud:    document.getElementById("tag-cloud"),
        typeFilters: document.getElementById("type-filters"),
        pagination:  document.getElementById("pagination-container"),
        resultsInfo: document.getElementById("results-info"),
        resultsCount:document.getElementById("results-count"),
        dateFrom:    document.getElementById("filter-date-from"),
        dateTo:      document.getElementById("filter-date-to"),
    };

    function init() {
        attachListeners();
        loadContents(true);
        window.addEventListener("popstate", (e) => {
            if (e.state) { Object.assign(state, e.state); renderFilters(); loadContents(true); }
        });
        if (state.paginationType === "infinite") {
            const sentinel = document.createElement("div");
            sentinel.style.height = "10px";
            DOM.pagination.appendChild(sentinel);
            new IntersectionObserver((entries) => {
                if (entries[0].isIntersecting && !state.loading && !state.totalResolved) {
                    state.page++; loadContents(false);
                }
            }, {threshold:0.1}).observe(sentinel);
        }
    }

    function attachListeners() {
        let searchTimer;
        DOM.searchInput.addEventListener("input", (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                state.q = e.target.value.trim(); state.page = 1;
                updateUrl(); loadContents(true);
            }, 350);
        });
        DOM.tagCloud.addEventListener("click", (e) => {
            const btn = e.target.closest(".tag-btn");
            if (!btn) return;
            e.preventDefault();
            state.tag = btn.dataset.tag; state.page = 1;
            renderFilters(); updateUrl(); loadContents(true);
        });
        DOM.typeFilters.addEventListener("click", (e) => {
            const btn = e.target.closest(".filter-item");
            if (!btn) return;
            e.preventDefault();
            state.type = btn.dataset.type; state.page = 1;
            renderFilters(); updateUrl(); loadContents(true);
        });
        if (DOM.dateFrom) DOM.dateFrom.addEventListener("change", (e) => { state.dateFrom = e.target.value; state.page=1; loadContents(true); });
        if (DOM.dateTo)   DOM.dateTo.addEventListener("change",   (e) => { state.dateTo   = e.target.value; state.page=1; loadContents(true); });
    }

    window.resetFilters = function() {
        state.tag=""; state.type=""; state.q=""; state.dateFrom=""; state.dateTo=""; state.page=1;
        if (DOM.searchInput) DOM.searchInput.value="";
        if (DOM.dateFrom)    DOM.dateFrom.value="";
        if (DOM.dateTo)      DOM.dateTo.value="";
        // Svuota ricerca tag
        const tagSearch = document.getElementById("tag-search");
        if (tagSearch) tagSearch.value = "";
        // Ripristina tag collassati DOPO qualsiasi operazione di filtro
        document.querySelectorAll(".tag-btn[data-tag]").forEach(b => {
            b.style.display = ""; // prima mostra tutti
        });
        document.querySelectorAll(".tag-overflow").forEach(b => {
            b.style.display = "none"; // poi nascondi overflow
        });
        const more = document.getElementById("tag-show-more");
        if (more) more.style.display = "";
        renderFilters(); updateUrl(); loadContents(true);
    };

    async function loadContents(clearGrid=true) {
        if (state.loading) return;
        state.loading = true;
        if (clearGrid) { DOM.grid.innerHTML=generateSkeletons(); DOM.pagination.innerHTML=""; window.scrollTo({top:0,behavior:"smooth"}); }
        const params = new URLSearchParams({page:state.page,q:state.q,tag:state.tag,type:state.type});
        if (state.dateFrom) params.set("from", state.dateFrom);
        if (state.dateTo)   params.set("to",   state.dateTo);
        try {
            const res    = await fetch("api/contents.php?"+params);
            const result = await res.json();
            if (!result.ok) throw new Error(result.error);
            if (clearGrid) DOM.grid.innerHTML="";
            if (result.data.length===0 && clearGrid) renderEmpty();
            else { renderCards(result.data); renderPagination(result.meta); updateResultInfo(result.meta); }
            state.totalResolved = !result.meta.has_next;
        } catch(err) {
            DOM.grid.innerHTML=`<div style="text-align:center;padding:100px;color:var(--error)">Errore: ${err.message}</div>`;
        } finally { state.loading=false; }
    }

    function renderCards(cards) {
        cards.forEach(card => {
            const el = document.createElement("article");
            el.className = "content-card";
            const thumb    = card.image || generatePlaceholderDataUri(card.title, card.content_type);
            const typeIcon = getTypeIcon(card.content_type);
            const date     = new Date(card.created_at).toLocaleDateString("it-IT",{day:"numeric",month:"short",year:"numeric"});
            const tagsHtml = card.tags.slice(0,4).map(t=>
                `<span class="card-tag clickable-tag" data-tag="${escapeHtml(t.slug)}" style="--tag-color:${tagColorFromName(t.name, t.color)}">#${escapeHtml(t.name)}</span>`
            ).join("");
            const deleteBtn = isAdmin ? `<button class="delete-btn" title="Elimina dal sito" data-id="${card.id}">🗑</button>` : "";

            el.innerHTML = `
                <div class="card-media">
                    <img src="${escapeHtml(thumb)}" alt="${escapeHtml(card.title||"")}" loading="lazy"
                         onerror="this.src='${generatePlaceholderDataUri(card.title,card.content_type)}'">
                    <div class="card-type-badge">${typeIcon}</div>
                    ${deleteBtn}
                </div>
                <div class="card-content">
                    <div class="card-source">
                        ${card.favicon?`<img src="${escapeHtml(card.favicon)}" class="card-favicon" onerror="this.remove()">` : ""}
                        <span>${escapeHtml(card.source_domain||"Telegram")}</span>
                    </div>
                    <h2 class="card-title">${escapeHtml(card.title||"Contenuto Telegram")}</h2>
                    <p class="card-description">${escapeHtml(card.ai_summary||card.description||"")}</p>
                    <div class="card-tags">${tagsHtml}</div>
                    <div class="card-footer">
                        <span class="card-date">${date}</span>
                        ${card.url ? `<span class="card-link-hint">↗ apri</span>` : ""}
                    </div>
                </div>`;

            if (card.url) {
                el.style.cursor = "pointer";
                el.addEventListener("click", (e) => {
                    if (e.target.closest(".delete-btn")||e.target.closest(".clickable-tag")) return;
                    window.open(card.url, "_blank", "noopener,noreferrer");
                });
            }

            el.querySelectorAll(".clickable-tag").forEach(t => {
                t.addEventListener("click", (e) => {
                    e.stopPropagation();
                    state.tag=t.dataset.tag; state.page=1;
                    renderFilters(); updateUrl(); loadContents(true);
                });
            });

            if (isAdmin) {
                const delBtn = el.querySelector(".delete-btn");
                if (delBtn) delBtn.addEventListener("click", async (e) => {
                    e.stopPropagation();
                    if (!confirm("Eliminare questo contenuto dal sito?")) return;
                    try {
                        const csrf = document.querySelector("meta[name=csrf]")?.content||"";
                        const res  = await fetch("api/admin.php?action=delete_content",{
                            method:"POST", headers:{"Content-Type":"application/json","X-CSRF-Token":csrf},
                            body:JSON.stringify({id:parseInt(card.id)})
                        });
                        const data = await res.json();
                        if (data.ok) {
                            el.style.transition="opacity .3s,transform .3s";
                            el.style.opacity="0"; el.style.transform="scale(0.9)";
                            setTimeout(()=>el.remove(), 300);
                        } else alert("Errore: "+(data.error||"impossibile eliminare"));
                    } catch(err) { alert("Errore: "+err.message); }
                });
            }

            DOM.grid.appendChild(el);
        });
    }

    function renderPagination(meta) {
        if (state.paginationType==="classic"||state.paginationType==="enhanced") {
            DOM.pagination.innerHTML="";
            if (meta.pages<=1) return;
            const start=Math.max(1,state.page-2), end=Math.min(meta.pages,state.page+2);
            if (start>1) addPageBtn(1,"1");
            if (start>2) DOM.pagination.insertAdjacentHTML("beforeend","<span style='color:var(--text-muted);padding:0 6px'>…</span>");
            for (let i=start;i<=end;i++) addPageBtn(i,String(i),i===state.page);
            if (end<meta.pages-1) DOM.pagination.insertAdjacentHTML("beforeend","<span style='color:var(--text-muted);padding:0 6px'>…</span>");
            if (end<meta.pages) addPageBtn(meta.pages,String(meta.pages));
            if (state.paginationType==="enhanced") {
                const inp=document.createElement("input");
                inp.type="number"; inp.className="search-input";
                inp.style.cssText="width:60px;padding:8px;margin-left:8px";
                inp.placeholder="#"; inp.value=state.page; inp.min=1; inp.max=meta.pages;
                inp.addEventListener("change",(e)=>{state.page=parseInt(e.target.value)||1;loadContents(true);});
                DOM.pagination.appendChild(inp);
            }
        } else if (state.paginationType==="loadmore") {
            DOM.pagination.innerHTML="";
            if (meta.has_next) {
                const btn=document.createElement("button");
                btn.className="load-more-btn"; btn.textContent=LANG.load_more||"Carica altri";
                btn.onclick=()=>{state.page++;loadContents(false);};
                DOM.pagination.appendChild(btn);
            }
        }
    }

    function addPageBtn(num,label,isActive=false) {
        const btn=document.createElement("a");
        btn.href="#"; btn.className="page-btn"+(isActive?" active":""); btn.textContent=label;
        btn.onclick=(e)=>{e.preventDefault();if(isActive)return;state.page=num;updateUrl();loadContents(true);};
        DOM.pagination.appendChild(btn);
    }

    function renderFilters() {
        DOM.tagCloud.querySelectorAll(".tag-btn").forEach(b=>b.classList.toggle("active",b.dataset.tag===state.tag));
        DOM.typeFilters.querySelectorAll(".filter-item").forEach(b=>b.classList.toggle("active",b.dataset.type===state.type));
    }

    function renderEmpty() {
        DOM.grid.innerHTML=`<div style="grid-column:1/-1;text-align:center;padding:120px 20px">
            <div style="font-size:64px;margin-bottom:24px">🔍</div>
            <h3 style="font-size:24px;font-weight:700">${LANG.no_results||"Nessun risultato"}</h3>
            <p style="color:var(--text-muted);margin-top:12px">Prova a cambiare i filtri.</p>
            <button onclick="resetFilters()" style="margin-top:20px;padding:10px 24px;background:var(--accent-color);color:#fff;border:none;border-radius:8px;cursor:pointer">✕ Reset filtri</button>
        </div>`;
        DOM.resultsInfo.style.display="none";
    }

    function updateResultInfo(meta) {
        if (state.page===1) { DOM.resultsInfo.style.display="block"; DOM.resultsCount.textContent=meta.total; }
    }

    function generateSkeletons(count=8) {
        let h="";
        for(let i=0;i<count;i++) h+=`<div class="content-card"><div class="card-media skeleton"></div>
            <div class="card-content">
                <div class="skeleton" style="height:12px;width:40%;margin-bottom:12px"></div>
                <div class="skeleton" style="height:20px;width:100%;margin-bottom:8px"></div>
                <div class="skeleton" style="height:20px;width:70%;margin-bottom:16px"></div>
                <div class="skeleton" style="height:12px;width:100%;margin-bottom:8px"></div>
            </div></div>`;
        return h;
    }

    function getTypeIcon(t) {
        return {link:"🔗",youtube:"📺",tiktok:"🎵",instagram:"📸",photo:"🖼️",video:"🎥",document:"📄",note:"📝",telegram_post:"📡"}[t]||"🔗";
    }

    function escapeHtml(text) {
        if (!text) return "";
        const d=document.createElement("div"); d.textContent=String(text); return d.innerHTML;
    }

    function updateUrl() {
        const p=new URLSearchParams();
        if (state.tag)      p.set("tag",state.tag);
        if (state.type)     p.set("type",state.type);
        if (state.q)        p.set("q",state.q);
        if (state.dateFrom) p.set("from",state.dateFrom);
        if (state.dateTo)   p.set("to",state.dateTo);
        if (state.page>1)   p.set("page",state.page);
        history.pushState({...state},document.title,p.toString()?"?"+p:"index.php");
    }

    function generatePlaceholderDataUri(title,type) {
        const color=window.TELEPAGE_CONFIG.accentColor||"#3b82f6";
        const icon=getTypeIcon(type);
        const label=(title||"Telepage").slice(0,22);
        const svg=`<svg width="400" height="225" xmlns="http://www.w3.org/2000/svg">
            <rect width="400" height="225" fill="${color}" fill-opacity="0.08"/>
            <text x="50%" y="44%" text-anchor="middle" font-size="42" fill="${color}" fill-opacity="0.35">${icon}</text>
            <text x="50%" y="68%" text-anchor="middle" font-family="sans-serif" font-size="14" fill="${color}" fill-opacity="0.55">${label}</text>
        </svg>`;
        return "data:image/svg+xml;base64,"+btoa(unescape(encodeURIComponent(svg)));
    }

    // ── Tag sidebar search & collapse ─────────────────────────────────────
    window.filterTagList = function(query) {
        const q = query.toLowerCase().trim();
        document.querySelectorAll('.tag-btn[data-name]').forEach(btn => {
            const name = (btn.dataset.name || '').toLowerCase();
            btn.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
        const more = document.getElementById('tag-show-more');
        if (more) more.style.display = q ? 'none' : '';
    };

    window.showAllTags = function(e) {
        e.preventDefault();
        e.stopPropagation(); // blocca propagazione al listener tag-cloud
        document.querySelectorAll('.tag-overflow').forEach(b => b.style.display = '');
        const more = document.getElementById('tag-show-more');
        if (more) more.style.display = 'none';
    };

    function tagColorFromName(name, dbColor) {
        if (dbColor && dbColor !== '#6c757d') return dbColor;
        const colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#f97316','#84cc16','#e11d48','#6366f1'];
        let hash = 0;
        for (let i = 0; i < name.length; i++) {
            hash = ((hash << 5) - hash) + name.charCodeAt(i);
            hash = hash & 0x7FFFFFFF;
        }
        return colors[hash % colors.length];
    }

    init();
})();
