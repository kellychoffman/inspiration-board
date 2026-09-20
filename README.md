# Inspiration Board

A WordPress plugin. Images you save elsewhere become a quiet, Are.na-style board on your own site: square tiles, uncropped, newest first. Each tile is its own post holding the image, a GIF or a video, and a link to where it came from.

Requires WordPress 6.4+ and PHP 7.4+. The no-sidebar page template needs 6.7.

## Install

Download this repository as a zip, then Plugins → Add New → Upload Plugin → Activate.

## Use

Everything lives under **Tools → Inspiration Board**.

**From X bookmarks.** Copy the snippet, paste it into the browser console on [x.com/i/bookmarks](https://x.com/i/bookmarks), and keep the window visible while it scrolls (X stops loading when hidden). It downloads a JSON file; upload that on the same screen. Photos, GIFs and videos are saved to your media library. Re-run it any time: anything already on the board is skipped.

**From your own posts.** Name a category, click Find images, and pick from the thumbnails. Those pins reuse the existing file and link back to the post.

**The board.** One click creates a page at `/inspiration` using the plugin's full-width, no-sidebar template. Or put `[inspiration_board]` on any page yourself; options are `columns` (default 4) and `per_page` (default 60).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
