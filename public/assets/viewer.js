import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.9.155/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.9.155/pdf.worker.min.mjs';

const canvas    = document.getElementById('pdf-canvas');
const ctx       = canvas.getContext('2d');
const slideInfo = document.getElementById('slide-info');
const permalink = document.getElementById('permalink');
const statusEl  = document.getElementById('status');
const tweetBtn  = document.getElementById('tweet-btn');
const liveBadge = document.getElementById('live-badge');
const resumeBtn = document.getElementById('resume-btn');
const prevBtn   = document.getElementById('prev-btn');
const nextBtn   = document.getElementById('next-btn');

let pdfDoc      = null;
let currentPage = 1;
let rendering   = false;
let pendingPage = null;

let presenting  = false;  // プレゼン開始済みか
let syncing     = true;   // 発表者に追従中か
let syncedPage  = 1;      // 発表者の最新ページ

// 初期状態を取得
const state = await fetch('/api/state').then(r => r.json());
const deckInfo = { user: state.user, slug: state.slug, tweet_hashtags: state.tweet_hashtags };

presenting = state.presenting ?? false;
syncedPage = state.effective || 1;

const hashPage = parseInt(location.hash.slice(1));
const initialPage = (hashPage > 0) ? hashPage : (state.effective || 1);
if (hashPage > 0 && presenting && initialPage !== syncedPage) {
  syncing = false;
}

await loadPdf();
await renderPage(initialPage);
updateUI();

// Mercure SSE
const es = new EventSource('/.well-known/mercure?topic=slide');

es.addEventListener('open', () => {
  updateUI();
});

es.addEventListener('error', () => {
  statusEl.textContent = '再接続中…';
});

es.addEventListener('message', (e) => {
  const data = JSON.parse(e.data);
  if (data.presenting === false) {
    presenting = false;
    syncing    = true;
    updateUI();
    return;
  }
  syncedPage = data.effective;
  presenting = true;
  if (syncing) queueRender(data.effective);
  updateUI();
});

// ナビゲーション
prevBtn.addEventListener('click', () => navigateTo(currentPage - 1));
nextBtn.addEventListener('click', () => navigateTo(currentPage + 1));

// キーボード操作
document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowLeft'  || e.key === 'h' || e.key === 'H') navigateTo(currentPage - 1);
  if (e.key === 'ArrowRight' || e.key === 'l' || e.key === 'L') navigateTo(currentPage + 1);
});

// 画面左右クリックでページ操作
const viewerWrap = document.getElementById('viewer-wrap');
viewerWrap.addEventListener('click', (e) => {
  if (e.clientX >= window.innerWidth / 2) {
    navigateTo(currentPage + 1);
  } else {
    navigateTo(currentPage - 1);
  }
});

// スワイプでページ操作
let touchStartX = 0;
let touchStartY = 0;
viewerWrap.addEventListener('touchstart', (e) => {
  touchStartX = e.touches[0].clientX;
  touchStartY = e.touches[0].clientY;
}, { passive: true });
viewerWrap.addEventListener('touchend', (e) => {
  const dx = e.changedTouches[0].clientX - touchStartX;
  const dy = e.changedTouches[0].clientY - touchStartY;
  if (Math.abs(dx) >= 50 && Math.abs(dx) >= Math.abs(dy)) {
    e.preventDefault();
    navigateTo(currentPage + (dx < 0 ? 1 : -1));
  }
}, { passive: false });

resumeBtn.addEventListener('click', resumeSync);
liveBadge.addEventListener('click', () => {
  if (!presenting) return;
  if (syncing) {
    syncing = false;
    updateUI();
  } else {
    resumeSync();
  }
});

function navigateTo(page) {
  if (!pdfDoc) return;
  if (presenting && syncing) {
    syncing = false;
    updateUI();
  }
  renderPage(Math.max(1, Math.min(pdfDoc.numPages, page)));
}

function resumeSync() {
  syncing = true;
  renderPage(syncedPage);
  updateUI();
}

function updateUI() {
  if (!presenting) {
    liveBadge.className = 'hidden';
    resumeBtn.hidden = true;
    statusEl.textContent = 'プレゼン開始前';
    return;
  }

  if (syncing) {
    liveBadge.className = 'live';
    liveBadge.textContent = '● LIVE';
    resumeBtn.hidden = true;
    statusEl.textContent = '';
  } else {
    liveBadge.className = 'hidden';
    resumeBtn.hidden = false;
    resumeBtn.textContent = `ライブに戻る (p.${syncedPage}) ▶`;
    statusEl.textContent = '';
  }
}

async function loadPdf() {
  try {
    pdfDoc = await pdfjsLib.getDocument('/slide.pdf').promise;
  } catch {
    statusEl.textContent = 'PDF読み込みエラー';
  }
}

function queueRender(page) {
  if (rendering) {
    pendingPage = page;
  } else {
    renderPage(page);
  }
}

async function renderPage(pageNum) {
  if (!pdfDoc) return;
  const p = Math.max(1, Math.min(pdfDoc.numPages, pageNum));

  rendering   = true;
  currentPage = p;

  const page    = await pdfDoc.getPage(p);
  const dpr     = window.devicePixelRatio || 1;
  const scale   = calcScale(page) * dpr;
  const viewport = page.getViewport({ scale });

  canvas.width  = viewport.width;
  canvas.height = viewport.height;
  canvas.style.width  = `${viewport.width  / dpr}px`;
  canvas.style.height = `${viewport.height / dpr}px`;

  await page.render({ canvasContext: ctx, viewport }).promise;

  rendering = false;

  const total = pdfDoc.numPages;
  slideInfo.textContent = `${p} / ${total}`;
  prevBtn.disabled = p <= 1;
  nextBtn.disabled = p >= total;
  history.replaceState(null, '', `#${p}`);
  updatePermalink(p);

  if (pendingPage !== null && pendingPage !== currentPage) {
    const next = pendingPage;
    pendingPage = null;
    renderPage(next);
  }
}

function calcScale(page) {
  const wrap  = document.getElementById('viewer-wrap');
  const wrapW = wrap.clientWidth  || window.innerWidth;
  const wrapH = wrap.clientHeight || window.innerHeight - 40;
  const vp    = page.getViewport({ scale: 1 });
  return Math.min(wrapW / vp.width, wrapH / vp.height);
}

function updatePermalink(pageNum) {
  if (!deckInfo.user || !deckInfo.slug) return;
  const url = `https://speakerdeck.com/${deckInfo.user}/${deckInfo.slug}?slide=${pageNum}`;
  permalink.href        = url;
  permalink.textContent = url;

  const tweetUrl = new URL('https://twitter.com/intent/tweet');
  tweetUrl.searchParams.set('url', url);
  if (deckInfo.tweet_hashtags) tweetUrl.searchParams.set('hashtags', deckInfo.tweet_hashtags);
  tweetBtn.href = tweetUrl.toString();
  tweetBtn.onclick = (e) => {
    e.preventDefault();
    const w = 550, h = 420;
    const left = window.screenX + 40;
    const top  = window.screenY + 40;
    window.open(tweetBtn.href, 'tweet', `width=${w},height=${h},left=${left},top=${top}`);
  };
}

window.addEventListener('resize', () => {
  if (pdfDoc) renderPage(currentPage);
});
