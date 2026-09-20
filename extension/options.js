/** Settings: which site to post to, and optionally who to post as. */

const $ = (id) => document.getElementById(id);
const DEFAULT_SITE = 'https://kelly.blog';

function tidy(site) {
  const value = String(site || '').trim().replace(/\/+$/, '');
  if (!value) return '';
  return /^https?:\/\//i.test(value) ? value : `https://${value}`;
}

function say(text, tone) {
  $('said').textContent = text;
  $('said').className = tone ? `said ${tone}` : 'said';
}

function authorizeLink() {
  const site = tidy($('site').value) || DEFAULT_SITE;
  $('authorize').href = `${site}/wp-admin/profile.php#application-passwords-section`;
}

chrome.storage.sync.get({ site: DEFAULT_SITE, username: '', appPassword: '' }).then((saved) => {
  $('site').value = saved.site;
  $('username').value = saved.username;
  $('password').value = saved.appPassword;
  if (saved.username || saved.appPassword) $('advanced').open = true;
  authorizeLink();
});

$('site').addEventListener('input', authorizeLink);

$('save').addEventListener('click', async () => {
  const site = tidy($('site').value);
  if (!site) {
    say('A site address is needed.', 'bad');
    return;
  }
  await chrome.storage.sync.set({
    site,
    username: $('username').value.trim(),
    appPassword: $('password').value.trim(),
  });
  $('site').value = site;
  authorizeLink();
  say('Saved.', 'good');
});

$('check').addEventListener('click', () => {
  say('Checking…');
  chrome.runtime.sendMessage({ type: 'status' }, (reply) => {
    if (chrome.runtime.lastError) {
      say(chrome.runtime.lastError.message, 'bad');
    } else if (reply && reply.ok) {
      say(reply.who ? `Connected as ${reply.who}.` : 'Connected.', 'good');
    } else {
      say((reply && reply.error) || 'Could not connect.', 'bad');
    }
  });
});
