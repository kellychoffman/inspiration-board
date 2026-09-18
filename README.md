# Inspiration Board

A WordPress plugin that turns the images from your X (Twitter) bookmarks into a quiet, Are.na-style gallery page on your site. Each image becomes its own post with a link back to the original tweet.

- **Gallery page:** a grid of square tiles with uncropped images and small grey captions. It sizes itself to your theme's main column, capped at 1280px wide.
- **One post per image:** the image is the post's featured image, and the post content is a single link: "Source: Name (@handle) on X ↗".
- **Its own category:** posts go into an "Inspiration" category the plugin creates. It never adopts an existing category.
- **Stays out of your blog:** imported posts are left off your posts page and main RSS feed. They only appear on the board and their category archive.
- **No API keys:** a small browser snippet reads the bookmarks page you're already logged in to. Nothing is sent to X or anywhere else.

## Install

1. Download this repository as a zip (Code → Download ZIP), or clone it into `wp-content/plugins/inspiration-board`.
2. In WordPress, go to Plugins → Add New → Upload Plugin, choose the zip, and activate.

Requires WordPress 6.4+ and PHP 7.4+.

## Use

1. **Collect.** Go to Tools → Inspiration Board and click **Copy snippet**. Open [x.com/i/bookmarks](https://x.com/i/bookmarks) (X also lists bookmarks under History), open the browser console (Cmd+Option+J in Chrome), paste, and press Return. It scrolls to the end of the list and downloads `inspiration-bookmarks.json`.
2. **Import.** Back on Tools → Inspiration Board, choose that file and click **Import images**. Photos you've already imported are skipped, so you can re-run it whenever you bookmark more.
3. **Show.** Click **Create an "Inspiration" page**, or add `[inspiration_board]` to any page.

Shortcode options: `[inspiration_board columns="4" per_page="60"]`.

Only photos are imported. Tweets that only have video or GIFs are skipped.

## Publishing quietly

Imported posts are created as drafts, flagged, and then published, so on sites running Jetpack (including WordPress.com) they don't:

- email your subscribers (`_jetpack_dont_email_post_to_subs`)
- auto-share to social networks (`_wpas_done_all`, plus the `jetpack_publicize_should_publicize_published_post` filter during import)

## Filters

| Filter | Default | What it does |
| --- | --- | --- |
| `inspiration_board_hide_from_blog` | `true` | Return `false` to let imported posts appear on your posts page and main feed. |
| `inspiration_board_category_slug` | `inspiration-board` | Slug used when the plugin first creates its category. |

## How the snippet works

`assets/collect-bookmarks.js` runs in your own logged-in browser tab. It scrolls the timeline and reads each tweet's link, author, text, date, and photo URLs from the page, then saves them as JSON. The importer only accepts `x.com`/`twitter.com` status links and images from `pbs.twimg.com/media/`, and downloads each image into your media library.

X changes its page markup from time to time. If the snippet collects nothing, the selectors in that file probably need updating.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
