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

## How pins behave

- Untitled, with the media in the post and a source link beneath it.
- Dated like the tweet or post they came from, so their permalinks match.
- Ordered by when you saved them, which is not the same as their dates.
- GIFs loop silently on the board. Videos do too, unless they're over 15MB or the visitor asked for reduced motion or less data; those keep a play badge and play on their own page.
- Kept out of your posts page and main feed, and off Jetpack's subscriber emails and social sharing.
- Deleted pins stay deleted: later imports skip them, and their files go with them.

## Filters

| Filter | Default | Effect |
| --- | --- | --- |
| `inspiration_board_hide_from_blog` | `true` | `false` lets pins appear on your posts page and main feed. |
| `inspiration_board_category_slug` | `inspiration-board` | Slug used when the plugin first creates its category. |

## Notes

X streams videos rather than serving files, so the plugin asks X's public embed endpoint for a downloadable mp4 (up to 1280px wide), including videos inside a quoted tweet. Pins imported before that worked can be filled in from Tools.

The collector reads the page you're looking at and sends nothing anywhere. X changes its markup from time to time; if a run collects nothing, the selectors in `assets/collect-bookmarks.js` are the place to look.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
