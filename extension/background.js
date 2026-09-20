/**
 * Talks to the site, so the page never has to.
 *
 * The picker (overlay.js) runs inside whatever page you are looking at and
 * knows nothing about your site or your login. It hands images here one at a
 * time; this worker fetches each file and posts it to the Inspiration Board
 * plugin's REST route, which makes one pin out of each.
 *
 * Fetching from here rather than from the page matters twice over: the
 * extension's host permissions get past the cross-origin rules a page is held
 * to, and the site's login cookie is never exposed to the page.
 */

const DEFAULT_SITE = 'https://kelly.blog';
const MAX_UPLOAD = 24 * 1024 * 1024;

const EXTENSIONS = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/gif': 'gif',
  'image/webp': 'webp',
  'image/avif': 'avif',
};

/** An error the picker should answer with "sign in", not "try again". */
class AuthError extends Error {
  constructor(message) {
    super(message);
    this.auth = true;
  }
}

async function getConfig() {
  const saved = await chrome.storage.sync.get({
    site: DEFAULT_SITE,
    username: '',
    appPassword: '',
  });
  saved.site = String(saved.site || DEFAULT_SITE).trim().replace(/\/+$/, '');
  return saved;
}

/**
 * How to prove who we are.
 *
 * Normally that is the login you already have in this browser: the plugin
 * hands back a REST nonce for whoever the cookie says you are. An application
 * password, if you saved one, is used instead, which also works when you are
 * signed out.
 */
let session = null;

async function getSession(cfg, refresh) {
  const fresh = session && session.site === cfg.site && Date.now() - session.at < 10 * 60 * 1000;
  if (fresh && !refresh) return session;

  let res;
  try {
    res = await fetch(`${cfg.site}/wp-admin/admin-ajax.php?action=inspiration_board_session`, {
      credentials: 'include',
      cache: 'no-store',
    });
  } catch (e) {
    throw new Error(`Could not reach ${cfg.site}. ${e.message}`);
  }

  const body = await res.json().catch(() => null);
  if (!body || !body.success || !body.data || !body.data.nonce) {
    const message = (body && body.data && body.data.message) || '';
    if (res.status === 401 || res.status === 403 || !body) {
      throw new AuthError(message || `Sign in to ${cfg.site} in this browser, then try again.`);
    }
    throw new Error(message || `${cfg.site} gave an unexpected answer.`);
  }

  session = { site: cfg.site, nonce: body.data.nonce, name: body.data.name, rest: body.data.rest, at: Date.now() };
  return session;
}

async function authFor(cfg, refresh) {
  // Where the routes live. The site tells us when it can; failing that, the
  // usual address for a site with pretty permalinks.
  const fallback = `${cfg.site}/wp-json/inspiration-board/v1/`;

  if (cfg.username && cfg.appPassword) {
    // Application password: send no cookies, so WordPress doesn't try to read
    // the login one instead and then reject the request for want of a nonce.
    const token = btoa(`${cfg.username}:${cfg.appPassword.replace(/\s+/g, '')}`);
    return { headers: { Authorization: `Basic ${token}` }, credentials: 'omit', who: cfg.username, rest: fallback };
  }

  const s = await getSession(cfg, refresh);
  return { headers: { 'X-WP-Nonce': s.nonce }, credentials: 'include', who: s.name, rest: s.rest || fallback };
}

/**
 * Posts to the plugin, retrying once with a new nonce: nonces expire, and the
 * first sign of that is a rejected request.
 */
async function callSite(path, build) {
  const cfg = await getConfig();

  for (const refresh of [false, true]) {
    const auth = await authFor(cfg, refresh);
    const res = await fetch(auth.rest + path, {
      method: 'POST',
      headers: auth.headers,
      credentials: auth.credentials,
      body: build(),
    });

    if ((res.status === 401 || res.status === 403) && !refresh && !cfg.appPassword) continue;

    const body = await res.json().catch(() => null);
    if (!res.ok) {
      const message = (body && body.message) || `${cfg.site} answered ${res.status}.`;
      throw res.status === 401 || res.status === 403 ? new AuthError(message) : new Error(message);
    }
    return body;
  }
}

/**
 * The image file itself, fetched here where the extension's permissions
 * apply. Returns null when the site won't hand it over, in which case the
 * address is passed to WordPress to try from its end instead.
 */
async function fetchImage(url) {
  try {
    const res = await fetch(url, { credentials: 'include', cache: 'force-cache' });
    if (!res.ok) return null;
    const blob = await res.blob();
    if (!blob.size || blob.size > MAX_UPLOAD) return null;
    if (!EXTENSIONS[blob.type]) return null;
    return blob;
  } catch (e) {
    return null;
  }
}

function fileNameFor(url, type) {
  let base = 'image';
  try {
    const last = decodeURIComponent(new URL(url).pathname.split('/').pop() || '');
    base = last.replace(/\.[a-z0-9]+$/i, '') || 'image';
  } catch (e) {
    /* A data: URL or nothing usable; the fallback name will do. */
  }
  base = base.replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'image';
  return `${base}.${EXTENSIONS[type] || 'jpg'}`;
}

/**
 * One image becomes one pin.
 *
 * `item.candidates` are addresses for the same picture, biggest first: a
 * guessed full-size version ahead of the one the page actually used. The
 * first that gives back a real image wins, so a guess that misses costs
 * nothing.
 */
async function pin(item, page) {
  const candidates = (item.candidates || [item.url]).filter(Boolean);
  let blob = null;
  let url = candidates[candidates.length - 1] || '';

  for (const candidate of candidates) {
    blob = await fetchImage(candidate);
    if (blob) {
      url = candidate;
      break;
    }
  }

  const form = new FormData();
  // Only an address the site can fetch for itself is worth sending as one.
  if (/^https?:/i.test(url)) form.append('url', url);
  form.append('source', page.url);
  // The page this was pinned from. When it differs from the source, the
  // picker has already worked out which post the image belongs to.
  if (page.on) form.append('page', page.on);
  if (page.title) form.append('title', page.title);
  if (item.alt) form.append('alt', item.alt);
  if (blob) form.append('file', blob, fileNameFor(url, blob.type));

  if (!blob && !/^https?:/i.test(url)) {
    throw new Error('That image could not be read from the page.');
  }

  return callSite('pin', () => form);
}

/** Which of these images are on the board already. */
async function pinned(urls) {
  return callSite('pinned', () => {
    const form = new FormData();
    urls.forEach((u) => form.append('urls[]', u));
    return form;
  });
}

const handlers = {
  async pin({ item, page }) {
    return { ok: true, result: await pin(item, page) };
  },
  async pinned({ urls }) {
    return { ok: true, result: await pinned(urls) };
  },
  async status() {
    const cfg = await getConfig();
    const auth = await authFor(cfg, false);
    return { ok: true, site: cfg.site, who: auth.who };
  },
};

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  const handler = handlers[message && message.type];
  if (!handler) return false;

  handler(message).then(sendResponse, (error) =>
    sendResponse({ ok: false, error: String((error && error.message) || error), auth: !!(error && error.auth) })
  );
  return true;
});

/* Clicking the toolbar button opens the picker in the page. */
chrome.action.onClicked.addListener(async (tab) => {
  if (!tab.id || !/^https?:/i.test(tab.url || '')) {
    await flash(tab.id, 'no', '#8a8a8a');
    return;
  }
  try {
    await chrome.scripting.executeScript({ target: { tabId: tab.id }, files: ['overlay.js'] });
  } catch (e) {
    await flash(tab.id, '!', '#c0392b');
  }
});

/* Right-clicking a single image pins just that one, no picker. */
chrome.runtime.onInstalled.addListener(() => {
  // Also runs on every update, and the menu outlives one. The new menu waits
  // for the clearing to finish, or it can be swept away by it.
  chrome.contextMenus.removeAll(() => {
    chrome.contextMenus.create({
      id: 'inspiration-board-pin',
      title: 'Pin this image to the Inspiration Board',
      contexts: ['image'],
    });
  });
});

chrome.contextMenus.onClicked.addListener(async (info, tab) => {
  if (info.menuItemId !== 'inspiration-board-pin' || !info.srcUrl) return;
  try {
    // Right-clicking gives us the page and nothing else, so the site itself
    // works out which post one of its own images belongs to.
    const where = info.pageUrl || (tab && tab.url) || '';
    const result = await pin({ url: info.srcUrl, candidates: [info.srcUrl] }, {
      url: where,
      title: (tab && tab.title) || '',
      on: where,
    });
    const made = result.status === 'created';
    await flash(tab && tab.id, made ? '✓' : '=', '#2f7d4f');
    await tell(
      made ? 'Pinned to your Inspiration Board' : 'Already on your Inspiration Board',
      made ? 'It is at the top of the board now.' : result.message || '',
      result.board
    );
  } catch (e) {
    await flash(tab && tab.id, '!', '#c0392b');
    await tell('That one could not be pinned', e.message, '');
  }
});

/**
 * Says what happened, since the right-click route has no window of its own.
 * Where the board is rides along on the notification, so clicking it opens
 * the board rather than nothing.
 */
const boards = new Map();

async function tell(title, message, board) {
  const id = `inspiration-board-${Date.now()}`;
  if (board) boards.set(id, board);

  try {
    await chrome.notifications.create(id, {
      type: 'basic',
      iconUrl: chrome.runtime.getURL('icons/icon-128.png'),
      title,
      message: message || '',
      buttons: board ? [{ title: 'See the board' }] : [],
    });
  } catch (e) {
    /* Notifications turned off for Chrome: the badge already said as much. */
  }
}

function openBoard(id) {
  const board = boards.get(id);
  if (board) chrome.tabs.create({ url: board });
  boards.delete(id);
  // Already gone if the viewer dismissed it themselves.
  chrome.notifications.clear(id).catch(() => {});
}

chrome.notifications.onClicked.addListener(openBoard);
chrome.notifications.onButtonClicked.addListener(openBoard);

async function flash(tabId, text, colour) {
  const target = tabId ? { tabId } : {};

  // The tab can be closed at any point in this, including during the wait
  // before the badge is cleared. A badge on a tab that no longer exists is
  // nothing to raise an error about, least of all an unhandled one from a
  // timer nobody is waiting on.
  const quietly = async (change) => {
    try {
      await change();
    } catch (e) {
      /* The tab has gone; there is no badge left to set. */
    }
  };

  await quietly(() => chrome.action.setBadgeBackgroundColor({ ...target, color: colour }));
  await quietly(() => chrome.action.setBadgeText({ ...target, text }));
  setTimeout(() => quietly(() => chrome.action.setBadgeText({ ...target, text: '' })), 2500);
}
