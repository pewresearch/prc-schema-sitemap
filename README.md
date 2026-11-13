# PRC Schema Sitemaps

> Canonical docs: [docs/plugins/prc-schema-sitemap/](../../docs/plugins/prc-schema-sitemap/)

Forked from [msm-sitemap](https://github.com/automattic/msm-sitemap).

This fork adds support for taxonomy sitemaps and Google News as well as adding images support to the posts sitemap.

Comprehensive sitemaps for your WordPress.com VIP site. Joint collaboration between Pew Research Center, Metro.co.uk, WordPress.com VIP, Alley Interactive, Maker Media, 10up, and others.

## How It Works

### Sitemap Data Storage

- One post type entry for each date.
- Sitemap XML is generated and stored in meta. This has several benefits:
- Avoid memory and timeout problems when rendering heavy sitemap pages with lots of posts.
- Older archives that are unlikely to change can be served up faster since we're not building them on-demand.
- Archive pages are rendered on-demand.

### Sitemap Generation

We want to generate the entire sitemap catalogue async to avoid running into timeout and memory issues.

Here's how the default WP-Cron approach works:

- Get year range for content.
- Store these years in options table.
- Kick off a cron event for the first year.
- Calculate the months to process for that year and store in an option.
- Kick off a cron event for the first month in the year we're processing.
- Calculate the days to process for that year and store in an option.
- Kick off a cron event for the first day in the month we're processing.
- Generate the sitemap for that day.
- Find the next day to process and repeat until we run out of days.
- Move on to the next month and repeat.
- Move on to next year when we run out of months.

### The 15-minute update pass

`prc_cron_update_sitemap` runs every 15 minutes and rebuilds each date that a changed post touches. One pass:

1. It reads the cursor, `prc_sitemap_update_cursor` (a GMT datetime). Before the first pass the cursor is `prc_sitemap_update_last_run`. The cursor supersedes `prc_sitemap_update_last_run`, which stays as a last-run stamp for the admin screen.
2. It fixes the window end before it queries: now minus 60 seconds, and never more than 24 hours after the cursor. The 60 seconds cover a post that appears after its `post_modified_gmt` stamp. After an outage of more than a day, each pass moves the cursor 24 hours on, so one pass never scans or queues an unbounded set.
3. It reads every post with `post_modified_gmt` in `[cursor, end)` in one query, in any post status. The query has no `LIMIT` and no `ORDER BY`: `post_modified_gmt` has no index, so a second scan or a sort costs more than the read.
4. It queues one event per date, then stores the end as the new cursor. If the query fails (`$wpdb->last_error` is set), the pass queues nothing and leaves the cursor, so the next pass reads the same window.

The pass cannot see a post whose `post_modified_gmt` did not change into the window. Three hooks queue the date of such a change: `post_updated` (a changed post date queues the old and the new date), `transition_post_status` (any move into `publish`, for example a scheduled post that goes live, a REST `date`, or an importer), and `before_delete_post` (a published post deleted for good, or a trashed post that was published before the trash). A post with a floating date (`post_date_gmt` of zero) and a date after the site's today queue nothing.

Each date event is a separate event on the day hook, 60 seconds after the one before it. Cron Control runs one event of an action at a time. A pass that queues 2,500 dates takes about 41.7 hours to drain.

"Generate from latest articles" runs the same pass, but it reads up to now, because the editor's save is already committed. It still stores the 60-second lagged end as the cursor, so the next cron pass reads the same rows again. A date that two passes queue is one event.

"Reset sitemap data" clears the cursor, so the next pass starts at the last-run stamp again. If a page of sitemap posts cannot be read, the reset stops with an error notice. It keeps the walk, the build, and the cursor, and a second press continues.

### Updates while a build runs

Each date that the update pass queues is an event on the same day hook as the walk (`prc_cron_generate_sitemap_for_year_month_day`) with an extra `update` arg. Cron Control runs one event of an action at a time, so an update never builds a date while the walk builds it. The handler builds the date and stops: an update does not move the walk's place in `prc_days_to_process`.

Every walk event carries the token of its build, so an event that a halt, a duplicate delivery, or a dead build left queued stops and cannot run as an unleased walk. A step never takes an expired lease back: a step of a build whose state row is not `running` or `halting`, or whose lease expired, ends the build and stops. Halt on a page that reads `idle` retires the build: it deletes the token, clears the lease row, and drops the queued walk events. Halt stops the full walk only. An update event does not read the build state (`prc_sitemap_build_state`), does not end the build, and does not release its lease, so the 15-minute update keeps running while a build is halted.

Deactivating the plugin removes every queued sitemap cron event.

## CLI Commands

The plugin ships with a bunch of wp-cli commands to simplify sitemap creation:

```
$ wp prc sitemap
usage: wp prc sitemap generate-sitemap
   or: wp prc sitemap generate-sitemap-for-year
   or: wp prc sitemap generate-sitemap-for-year-month
   or: wp prc sitemap generate-sitemap-for-year-month-day
   or: wp prc sitemap export-urls
   or: wp prc sitemap recount-indexed-posts

See 'wp help prc sitemap' for more information on a specific command.
```

`export-urls` writes CSV (`url,lastmod,source`) to STDOUT. Redirect it to a file:

```
wp prc sitemap export-urls > sitemap-urls.csv
```

### Indexed URLs

The "Indexed URLs" number on the sitemap admin page is a sum. `PRC_Sitemap::get_total_indexed_url_count()` adds up `prc_indexed_url_count` over the published sitemap posts. The sum is cached in the object cache under the sitemap data version, so every build and every delete retires it. No build writes a shared total, so two builds at once cannot lose each other's URLs. The option `prc_sitemap_indexed_url_count` is no longer read or written.

- A date that has two sitemap posts counts twice until one is removed.
- Only published sitemap posts count. The old total counted every status. On a site that holds draft sitemap posts, the number steps down once at deploy. Before you deploy, run a read-only check: `SELECT COUNT(*) FROM wp_N_posts WHERE post_type = 'prc_sitemap' AND post_status <> 'publish'`.
- The cached sum can lag after a direct SQL change to a sitemap post, because that change does not start a new version. It catches up at the next build, delete or version bump, or after 24 hours.
- To roll back to code that keeps the option total, run `wp prc sitemap recount-indexed-posts` on each site after the rollback, on the old code. On this head the command does nothing for the total. The old code only adds a change to `prc_sitemap_indexed_url_count` at each build, so a stale option stays wrong until a recount on the old code rewrites it.
- `wp prc sitemap recount-indexed-posts` is moot for the total. It still rereads each sitemap's XML and rewrites that date's `prc_indexed_url_count`, which the sum then picks up, but it writes no total.

## Filtering Sitemap URLs

If you need to filter the URLs displayed in a sitemap created via the Comprehensive Sitemap plugin, there are two considerations. First, if you are filtering the individual sitemaps, which display the URLs to the articles published on a specific date, you can use the `prc_sitemap_entry` hook to filter the URLs. An example for a reverse-proxy situation is below:

```
function example_filter_prc_sitemap_entry( $url ) {
    $location = str_replace( 'example.wordpress.com', 'example.com/blog', $url->loc );
    $url->loc = $location;
    return $url;
}
add_filter( 'prc_sitemap_entry', 'example_filter_prc_sitemap_entry', 10, 1 );
```

Second, if you are filtering the root sitemap, which displays the URLs to the individual sitemaps by date, you will need to filter the `home_url` directly. There is no plugin-specific hook to filter the URLs on the root sitemap.

## Filter Sitemap Index

Use the `prc_sitemap_index` filter to exclude daily sitemaps from the index based on date.

```
add_filter( 'prc_sitemap_index', function( $sitemaps ) {
    $reference_date = strtotime( '2017-09-09' );

    return array_filter( $sitemaps, function ( $date ) use ( $reference_date ) {
        return ( $reference_date < strtotime( $date ) );
    } );
} );
```
