# Inspiration Board

A WordPress plugin. Images you save elsewhere become a quiet, Are.na-style board on your own site: square tiles, uncropped, newest first. Each tile is its own post holding the image, a GIF or a video, and a link to where it came from.

Requires WordPress 6.4+ and PHP 7.4+. The no-sidebar page template needs 6.7.

## Install

Download this repository as a zip, then Plugins → Add New → Upload Plugin → Activate.

## Use

Everything lives under **Tools → Inspiration Board**.

**From X bookmarks.** Copy the snippet, paste it into the browser console on [x.com/i/bookmarks](https://x.com/i/bookmarks), and keep the window visible while it scrolls (X stops loading when hidden). It downloads a JSON file; upload that on the same screen. Photos, GIFs and videos are saved to your media library. Re-run it any time: anything already on the board is skipped.

**From any page on the web.** Download the Chrome extension from the same screen, unzip it, and load it at `chrome://extensions` with Developer mode on (or load `extension/` from this repository directly). Then a toolbar button on any page shows you every image it has, you tick the ones you want, and each becomes its own pin dated the moment you pinned it. Right-clicking a single image offers the same thing for one. It posts as whoever is signed in to your site in that browser; there is nothing to set up unless your site is somewhere other than the default.

**From your own posts.** Name a category, click Find images, and pick from the thumbnails. Those pins reuse the existing file and link back to the post.

**The board.** One click creates a page at `/inspiration` using the plugin's full-width, no-sidebar template. Or put `[inspiration_board]` on any page yourself; options are `columns` (default 4) and `per_page` (default 60).

## The extension

`extension/` is a Manifest V3 Chrome extension, unpacked. It talks to three things the plugin adds:

| | |
| --- | --- |
| `admin-ajax.php?action=inspiration_board_session` | Trades your login cookie for a REST nonce, so no password is stored anywhere. |
| `POST /wp-json/inspiration-board/v1/pin` | One image in, one pin out. Sends the file itself when the browser can fetch it, otherwise the address for WordPress to fetch. |
| `POST /wp-json/inspiration-board/v1/pinned` | Which images on this page are on the board already, so they can be greyed out. |

An application password can be used instead of the cookie, under the extension's options, for pinning while signed out.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
