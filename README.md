# Inspiration Board

<img width="1308" height="780" alt="Screenshot 2026-09-20 at 7 46 35 AM" src="https://github.com/user-attachments/assets/c35eb09c-57c3-4a4f-9179-d97778636685" />


A WordPress plugin that gives you a nice Pinterest/are.na style board on your site. Each item is its own post with the image, GIF, or video with the source.

## Install

Download this repository as a zip, then Plugins → Add New → Upload Plugin → Activate.

## Use

**From X bookmarks.** Copy the snippet, paste it into the browser console on [x.com/i/bookmarks](https://x.com/i/bookmarks), and keep the window visible while it scrolls (X stops loading when hidden). It stops at your 20 newest bookmarks with media and downloads a JSON file; upload that on the same screen. Photos, GIFs and videos are saved to your media library. Re-run it any time: anything already on the board is skipped. To reach further back, raise `RECENT` at the top of the snippet, or set it to 0 for the whole list.

**From any page on the web.** Download the Chrome extension from the same screen, unzip it, and load it at `chrome://extensions` with Developer mode on (or load `extension/` from this repository directly). Then a toolbar button on any page shows you every image it has, you tick the ones you want, and each becomes its own pin dated the moment you pinned it. Right-clicking a single image offers the same thing for one. Pinning while scrolling an archive links back to the post each image was in rather than to the archive, and an image already in your own media library is reused where it sits instead of being copied. It posts as whoever is signed in to your site in that browser; there is nothing to set up unless your site is somewhere other than the default.

**From your own posts.** Name a category, click Find images, and pick from the thumbnails. Those pins reuse the existing file and link back to the post.

**The board.** One click creates a page at `/inspiration` using the plugin's full-width, no-sidebar template. Or put `[inspiration_board]` on any page yourself; options are `columns` (default 4) and `per_page` (default 60). A Shuffle link beside the title reorders the tiles at random. (Yes, this is kind of old school with a shortcode, I know I know.)

Manage this plugin via **Tools → Inspiration Board**.

## The Chrome extension

`extension/` is a Manifest V3 Chrome extension, unpacked. It talks to three things the plugin adds:

| | |
| --- | --- |
| `admin-ajax.php?action=inspiration_board_session` | Trades your login cookie for a REST nonce, so no password is stored anywhere. |
| `POST /wp-json/inspiration-board/v1/pin` | One image in, one pin out. Sends the file itself when the browser can fetch it, otherwise the address for WordPress to fetch. |
| `POST /wp-json/inspiration-board/v1/pinned` | Which images on this page are on the board already, so they can be greyed out. |

An application password can be used instead of the cookie, under the extension's options, for pinning while signed out.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
