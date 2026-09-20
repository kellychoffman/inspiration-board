/**
 * The picker: everything you see when you click the toolbar button.
 *
 * It gathers every image the page has actually drawn, shows them as a board
 * of its own to choose from, and hands the chosen ones to the background
 * worker one at a time. It knows nothing about your site or your login.
 *
 * Injected on demand, so a second click closes it again.
 */

(() => {
  if (window.__inspirationBoardPicker) {
    window.__inspirationBoardPicker.close();
    return;
  }

  const MIN_SIDE = 200;
  const HOST_ID = 'inspiration-board-picker';

  /* ---------------------------------------------------------------- images */

  const absolute = (url, base) => {
    try {
      return new URL(url, base || location.href).href;
    } catch (e) {
      return '';
    }
  };

  /** The biggest address in a srcset, by the width or density it claims. */
  function fromSrcset(value) {
    let best = '';
    let bestWeight = 0;

    String(value || '')
      .split(',')
      .forEach((part) => {
        const bits = part.trim().split(/\s+/);
        if (!bits[0]) return;
        const descriptor = bits[1] || '1x';
        const weight = /w$/.test(descriptor)
          ? parseInt(descriptor, 10)
          : parseFloat(descriptor) * 1000 || 1;
        if (weight >= bestWeight) {
          bestWeight = weight;
          best = bits[0];
        }
      });

    return best ? absolute(best) : '';
  }

  /**
   * A guess at the full-size version of an image a CDN has shrunk for the
   * page. Returns '' when there is nothing to guess at. A wrong guess is
   * harmless: the background worker falls back to the address the page used.
   */
  function fullSize(url) {
    let u;
    try {
      u = new URL(url);
    } catch (e) {
      return '';
    }

    const host = u.hostname;
    const path = u.pathname;
    const wider = (pattern, replacement) => {
      if (!pattern.test(path)) return '';
      u.pathname = path.replace(pattern, replacement);
      return u.href;
    };

    // WordPress.com and Jetpack's image CDN.
    if (/(^|\.)wp\.com$/.test(host) || /\.files\.wordpress\.com$/.test(host)) {
      if (!u.searchParams.has('w') && !u.searchParams.has('resize')) return '';
      ['h', 'fit', 'resize', 'crop'].forEach((k) => u.searchParams.delete(k));
      u.searchParams.set('w', '2000');
      return u.href;
    }
    if (host === 'images.unsplash.com') {
      if (!u.searchParams.has('w')) return '';
      ['h', 'fit', 'crop', 'ar'].forEach((k) => u.searchParams.delete(k));
      u.searchParams.set('w', '2400');
      u.searchParams.set('q', '85');
      return u.href;
    }
    if (/\.squarespace(-cdn)?\.com$/.test(host)) {
      if (!/^\d+w$/.test(u.searchParams.get('format') || '')) return '';
      u.searchParams.set('format', '2500w');
      return u.href;
    }
    if (/substackcdn\.com$/.test(host)) return wider(/\/w_\d+/, '/w_2000');
    // Cloudinary: drop the whole resizing step rather than widening it, or a
    // height left behind would squash the picture into a 2000px-wide strip.
    if (/res\.cloudinary\.com$/.test(host)) return wider(/\/upload\/[a-z]_[^/]*\//, '/upload/');
    if (/cdn\.shopify\.com$/.test(host)) return wider(/_\d+x\d*(\.[a-z]+)$/i, '$1');
    if (/miro\.medium\.com$/.test(host)) {
      return (
        wider(/(resize:fi(?:t|ll):)\d+/, (whole, prefix) => `${prefix}2400`) ||
        wider(/\/(max|fit)\/\d+\//, '/max/2400/')
      );
    }
    if (/(^|\.)pbs\.twimg\.com$/.test(host)) {
      u.searchParams.set('name', 'large');
      return u.href;
    }

    return '';
  }

  const found = new Map();

  const usable = (url) =>
    !!url && !/^(blob:|about:)/i.test(url) && !/^data:image\/svg/i.test(url) && !/\.svgz?($|\?)/i.test(url);

  /**
   * One picture, however many addresses the page has for it.
   *
   * `url` is the one the page actually drew, so the thumbnail here is already
   * in the browser's cache. `candidates` are addresses for the same picture
   * with the biggest first, which is what gets fetched: a guess at the
   * full-size version, then anything bigger the page listed, then the one on
   * screen as the fallback that is certain to work.
   */
  function add(url, { alt = '', width = 0, height = 0, larger = [] } = {}) {
    if (!usable(url)) return;

    const key = url.split('#')[0];
    const existing = found.get(key);
    if (existing) {
      // The same picture can appear twice, small in one place and large in
      // another. Keep the larger sighting, and any alt text going.
      if (width * height > existing.width * existing.height) {
        existing.width = width;
        existing.height = height;
      }
      if (!existing.alt) existing.alt = alt;
      return;
    }

    const candidates = [fullSize(url), ...larger, url].filter(usable);

    found.set(key, {
      url,
      candidates: [...new Set(candidates)],
      alt: alt.trim().slice(0, 200),
      width,
      height,
      state: '',
    });
  }

  function collect() {
    document.querySelectorAll('img').forEach((img) => {
      const box = img.getBoundingClientRect();
      const shown = absolute(img.currentSrc || img.src);

      // What the page drew is not always the best it has: a srcset, or a
      // <picture>, may list a larger rendition than the browser picked.
      const picture = img.closest('picture');
      const larger = [fromSrcset(img.getAttribute('srcset'))];
      if (picture) {
        picture
          .querySelectorAll('source[srcset]')
          .forEach((source) => larger.push(fromSrcset(source.getAttribute('srcset'))));
      }

      add(shown, {
        alt: img.alt || '',
        width: img.naturalWidth || Math.round(box.width),
        height: img.naturalHeight || Math.round(box.height),
        larger,
      });
    });

    // Pictures the page paints as a background rather than as an <img>.
    let seen = 0;
    for (const el of document.querySelectorAll('*')) {
      if (++seen > 5000) break;
      const box = el.getBoundingClientRect();
      if (box.width < MIN_SIDE || box.height < MIN_SIDE) continue;

      const layers = getComputedStyle(el).backgroundImage;
      if (!layers || layers === 'none') continue;

      const match = layers.match(/url\((['"]?)(.*?)\1\)/);
      if (match && match[2]) {
        add(absolute(match[2]), {
          alt: el.getAttribute('aria-label') || el.title || '',
          width: Math.round(box.width),
          height: Math.round(box.height),
        });
      }
    }

    // The page's own share image, which is often the best one it has.
    const share = document.querySelector('meta[property="og:image"], meta[name="twitter:image"]');
    if (share && share.content) {
      add(absolute(share.content), { alt: document.title, width: 1200, height: 630 });
    }

    return [...found.values()];
  }

  const items = collect();
  const isSmall = (item) => item.width < MIN_SIDE || item.height < MIN_SIDE;
  const big = items.filter((item) => !isSmall(item));

  /* ------------------------------------------------------------------- ui */

  const host = document.createElement('div');
  host.id = HOST_ID;
  // Its own shadow tree, so the page's stylesheet can't reach inside and
  // nothing here can leak back out onto the page.
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = `
    <style>
      :host { all: initial; }
      * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; }
      .scrim {
        position: fixed; inset: 0; z-index: 2147483647;
        background: rgba(8, 8, 8, 0.72);
        -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
        display: flex; align-items: center; justify-content: center;
        padding: 28px 20px;
      }
      .panel {
        display: flex; flex-direction: column;
        width: 100%; max-width: 1180px; max-height: 100%;
        background: #131313; color: #f4f3ef;
        box-shadow: 0 24px 80px rgba(0, 0, 0, 0.6);
      }
      header { display: flex; align-items: baseline; gap: 14px; padding: 18px 20px 14px; border-bottom: 1px solid #262626; }
      h1 { font-size: 15px; font-weight: 600; letter-spacing: 0.01em; }
      .count { font-size: 12px; color: #8d8b85; }
      .who { margin-left: auto; font-size: 12px; color: #8d8b85; }
      .who b { font-weight: 500; color: #c9c7c0; }
      .close {
        appearance: none; border: 1px solid #333; background: none; color: #8d8b85;
        font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase;
        padding: 4px 8px; cursor: pointer;
      }
      .close:hover { color: #f4f3ef; border-color: #555; }

      .grid { overflow: auto; padding: 16px 20px 20px; display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); align-content: start; }
      .tile {
        appearance: none; position: relative; padding: 0; border: 0;
        background: #1d1d1d; cursor: pointer; aspect-ratio: 1;
        outline-offset: -2px;
        display: flex; align-items: center; justify-content: center;
      }
      /* Shown at its own size at most, so a small image looks small rather
         than blown up and blurry. */
      .tile img { max-width: 100%; max-height: 100%; display: block; }
      .tile:focus-visible { outline: 2px solid #f4f3ef; }
      .tile[aria-pressed="true"] { outline: 2px solid #e85d3c; }
      .tile[data-done="pinned"] { outline: 2px solid #4f9a68; }
      .tile[data-done="failed"] { outline: 2px solid #c0392b; }
      .tile[data-known] img { opacity: 0.32; }
      .tile[data-known] { cursor: default; }
      .badge {
        position: absolute; left: 0; bottom: 0; right: 0;
        font-size: 10px; letter-spacing: 0.07em; text-transform: uppercase;
        padding: 4px 6px; background: rgba(0, 0, 0, 0.72); color: #cdcbc4; text-align: left;
      }
      .badge.on { color: #e85d3c; }
      .badge.ok { color: #7ec994; }
      .badge.bad { color: #e8776a; }
      .size { position: absolute; top: 0; right: 0; font-size: 10px; padding: 3px 5px; background: rgba(0, 0, 0, 0.6); color: #8d8b85; }

      footer { display: flex; align-items: center; gap: 16px; padding: 14px 20px; border-top: 1px solid #262626; flex-wrap: wrap; }
      label { font-size: 12px; color: #a3a09a; display: flex; align-items: center; gap: 6px; cursor: pointer; }
      .link { appearance: none; background: none; border: 0; padding: 0; color: #a3a09a; font-size: 12px; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
      .link:hover { color: #f4f3ef; }
      .status { margin-left: auto; font-size: 12px; color: #a3a09a; }
      .status.bad { color: #e8776a; }
      .go {
        appearance: none; border: 0; background: #f4f3ef; color: #131313;
        font-size: 13px; font-weight: 600; padding: 9px 16px; cursor: pointer;
      }
      .go[disabled] { background: #333; color: #777; cursor: default; }
      .empty { padding: 60px 20px; text-align: center; color: #8d8b85; font-size: 13px; }
      a { color: #e85d3c; }
    </style>

    <div class="scrim" part="scrim">
      <div class="panel" role="dialog" aria-modal="true" aria-label="Pin images to your Inspiration Board">
        <header>
          <h1>Pin to Inspiration Board</h1>
          <span class="count"></span>
          <span class="who"></span>
          <button class="close" type="button">Esc</button>
        </header>
        <div class="grid"></div>
        <footer>
          <label><input type="checkbox" class="small-toggle"> Show small images</label>
          <button class="link select-all" type="button">Select all</button>
          <span class="status" aria-live="polite"></span>
          <button class="go" type="button" disabled>Pin</button>
        </footer>
      </div>
    </div>
  `;

  const $ = (selector) => root.querySelector(selector);
  const grid = $('.grid');
  const status = $('.status');
  const go = $('.go');
  const countLabel = $('.count');

  const selected = new Set();
  let showSmall = false;
  let working = false;

  function visible() {
    return showSmall ? items : big;
  }

  function label(item) {
    if (item.state === 'pinning') return ['Pinning…', ''];
    if (item.state === 'pinned') return ['Pinned', 'ok'];
    if (item.state === 'failed') return ['Failed — click to retry', 'bad'];
    if (item.known === 'pinned') return ['On the board', ''];
    if (item.known === 'deleted') return ['Removed before', ''];
    if (selected.has(item)) return ['Selected', 'on'];
    return ['', ''];
  }

  function render() {
    grid.replaceChildren();
    const list = visible();

    countLabel.textContent = list.length
      ? `${list.length} image${list.length === 1 ? '' : 's'} on this page`
      : '';

    if (!list.length) {
      const empty = document.createElement('p');
      empty.className = 'empty';
      empty.textContent = items.length
        ? 'Nothing here is big enough to pin. Turn on "Show small images" to see the rest.'
        : 'No images found on this page.';
      grid.append(empty);
    }

    list.forEach((item) => {
      const tile = document.createElement('button');
      tile.type = 'button';
      tile.className = 'tile';
      tile.setAttribute('aria-pressed', String(selected.has(item)));
      if (item.known && !item.state) tile.dataset.known = item.known;
      if (item.state === 'pinned' || item.state === 'failed') tile.dataset.done = item.state;
      if (item.error) tile.title = item.error;

      const img = document.createElement('img');
      img.src = item.url;
      img.alt = item.alt || '';
      img.loading = 'lazy';
      tile.append(img);

      if (item.width && item.height) {
        const size = document.createElement('span');
        size.className = 'size';
        size.textContent = `${item.width}×${item.height}`;
        tile.append(size);
      }

      const [text, tone] = label(item);
      if (text) {
        const badge = document.createElement('span');
        badge.className = `badge ${tone}`;
        badge.textContent = item.state === 'pinned' && item.link ? 'Pinned ↗' : text;
        tile.append(badge);
      }

      tile.addEventListener('click', () => {
        if (working) return;
        if (item.state === 'pinned' && item.link) {
          window.open(item.link, '_blank', 'noopener');
          return;
        }
        if (item.state === 'failed') {
          // A failure is worth another go: the site may have been slow, or
          // the page may only now have finished loading the picture.
          item.state = '';
          item.error = '';
          selected.add(item);
          render();
          return;
        }
        if (item.known || item.state) return;
        if (selected.has(item)) selected.delete(item);
        else selected.add(item);
        render();
      });

      grid.append(tile);
    });

    updateFooter();
  }

  function updateFooter() {
    go.disabled = working || !selected.size;
    go.textContent = selected.size
      ? `Pin ${selected.size} image${selected.size === 1 ? '' : 's'}`
      : 'Pin';
    $('.select-all').textContent = selected.size ? 'Select none' : 'Select all';
  }

  function say(text, bad) {
    status.textContent = text;
    status.className = bad ? 'status bad' : 'status';
  }

  /* --------------------------------------------------------------- actions */

  const ask = (message) =>
    new Promise((resolve) => chrome.runtime.sendMessage(message, (reply) => {
      const failed = chrome.runtime.lastError;
      resolve(failed ? { ok: false, error: failed.message } : reply);
    }));

  async function pinSelected() {
    // Pinned newest-last, so the first image you picked ends up first on the
    // board: the board shows the most recent pin ahead of the others.
    const queue = visible().filter((item) => selected.has(item)).reverse();
    if (!queue.length) return;

    working = true;
    updateFooter();

    let created = 0;
    let already = 0;
    let failed = 0;
    let at = 0;

    for (const item of queue) {
      item.state = 'pinning';
      render();
      say(`Pinning ${++at} of ${queue.length}…`);

      const reply = await ask({
        type: 'pin',
        item: { url: item.url, candidates: item.candidates, alt: item.alt },
        page: { url: location.href, title: document.title },
      });

      if (reply && reply.ok) {
        const result = reply.result || {};
        if (result.status === 'created') {
          item.state = 'pinned';
          item.link = result.link || '';
          created += 1;
        } else {
          // Already on the board, or taken off it once before.
          item.state = '';
          item.known = result.status;
          item.error = result.message || '';
          already += 1;
        }
      } else {
        item.state = 'failed';
        item.error = (reply && reply.error) || 'Something went wrong.';
        failed += 1;
        if (reply && reply.auth) {
          selected.clear();
          working = false;
          render();
          say(item.error, true);
          return;
        }
      }

      selected.delete(item);
      render();
    }

    working = false;
    render();

    const said = [`${created} pinned`];
    if (already) said.push(`${already} already there`);
    if (failed) said.push(`${failed} failed`);
    say(said.join(', ') + (failed ? '. Click a red tile to try again.' : '.'), !!failed);
  }

  /* --------------------------------------------------------------- wiring */

  function close() {
    document.removeEventListener('keydown', onKey, true);
    host.remove();
    delete window.__inspirationBoardPicker;
  }

  function onKey(event) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      close();
    }
  }

  $('.close').addEventListener('click', close);
  $('.scrim').addEventListener('click', (event) => {
    if (event.target === $('.scrim')) close();
  });
  document.addEventListener('keydown', onKey, true);

  $('.small-toggle').addEventListener('change', (event) => {
    showSmall = event.target.checked;
    render();
  });

  $('.select-all').addEventListener('click', () => {
    if (working) return;
    if (selected.size) selected.clear();
    else visible().forEach((item) => {
      if (!item.known && !item.state) selected.add(item);
    });
    render();
  });

  go.addEventListener('click', pinSelected);

  window.__inspirationBoardPicker = { close };
  document.documentElement.append(host);
  render();

  /* Who we are posting as, and what has been pinned already. */
  ask({ type: 'status' }).then((reply) => {
    if (reply && reply.ok) {
      $('.who').innerHTML = '';
      $('.who').append(document.createTextNode('Posting to '));
      const b = document.createElement('b');
      b.textContent = reply.site.replace(/^https?:\/\//, '');
      $('.who').append(b);
      if (reply.who) $('.who').append(document.createTextNode(` as ${reply.who}`));
    } else {
      say((reply && reply.error) || 'Not connected.', true);
    }
  });

  say('Checking what is on the board already…');
  const everyUrl = [...new Set(items.flatMap((item) => item.candidates))];
  ask({ type: 'pinned', urls: everyUrl }).then((reply) => {
    if (status.textContent.startsWith('Checking')) say('');
    if (!reply || !reply.ok || !reply.result) return;
    items.forEach((item) => {
      // Any of a picture's addresses being on the board means the picture is.
      const hit = item.candidates.find((url) => reply.result[url]);
      if (hit) item.known = reply.result[hit];
    });
    render();
  });
})();
