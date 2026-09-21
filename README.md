# KidTube

A tiny, self-hosted app so your kid can only watch videos and see photos you've
specifically approved. Three ways to add content:

- **YouTube links** (optional, off by default — see below)
- **Upload your own video files** (MP4, MOV, WebM, M4V)
- **Upload your own photos** (JPG, PNG, GIF, WebP)

- **Kid side** (`index.php`, `watch.php`): a password gate (separate from
  the admin login, meant for a grown-up to unlock once per device — see
  below) in front of big colorful cards, swipeable browsing between items,
  nothing but what you've approved.
- **Parent side** (`admin/`): password-protected. Add media, review it, sort
  into categories, edit thumbnails, hide or delete anything, any time -
  redesigned to work well on a phone too.

## Kid-side access gate

If this site is reachable from the open internet (a subdomain, for
instance), anyone who finds the URL could otherwise browse the kid
interface — there's no admin login standing in the way of *that* side by
design, since it's meant to be tap-and-watch for a small child. `gate.php`
adds a separate password screen in front of `index.php`/`watch.php` to close
that gap.

- Set the password in `config.php` via `KID_GATE_PASSWORD`. Set
  `KID_GATE_ENABLED` to `false` if you're only ever running this on a
  trusted home network and don't want the extra step.
- It's meant to be entered once by a grown-up, not typed by the kid — check
  "Remember this device" and that browser won't be asked again for 90 days
  (a signed cookie, not the password itself, is what's stored).
- After 6 wrong attempts it locks out for a minute, to slow down guessing.
- Changing `KID_GATE_PASSWORD` automatically signs out every previously
  "remembered" device, since the remember-cookie is cryptographically tied
  to the current password.
- Heads up: this protects the *pages* (index.php/watch.php), not the raw
  uploaded files themselves — a direct link to something like
  `/uploads/videos/<random-name>.mp4` would still play if someone had that
  exact URL. Filenames are random 20-character hex strings (not guessable,
  not listed anywhere public), so this is the same practical security model
  as an "unlisted" video link — fine for keeping randos from browsing in,
  not intended to withstand someone who already has a direct link shared
  with them some other way.

## About YouTube link-off (read this if you're deciding whether to enable it)

`ENABLE_YOUTUBE` in `config.php` is **off by default**. Here's why: YouTube's
embed terms require the YouTube logo to stay clickable in the player
controls, and clicking it (or right-clicking → "Watch on YouTube") always
opens youtube.com in a new tab — full search, recommendations, comments, the
works. `modestbranding`/`rel=0` trim some branding and related-video
clutter, but there's no reliable, ToS-compliant way to remove that
click-through entirely. Overlaying it with a transparent div is a common hack
but it's unreliable (breaks fullscreen and mobile, breaks whenever YouTube
updates their player) and violates YouTube's embed terms.

**If a guaranteed no-link-off experience matters to you, leave `ENABLE_YOUTUBE`
false and stick to uploaded videos/photos** — download the YouTube video
first (for content you have the right to use), then upload it here as a
regular video file. Uploaded content never links anywhere.

If you'd rather have the convenience of linking straight to YouTube videos
and are fine with that tradeoff, set `ENABLE_YOUTUBE` to `true` in
`config.php` and the "YouTube link" tab reappears in the admin panel.

## Requirements

- Apache with PHP 8+ (mod_php or PHP-FPM both work) and the `pdo_mysql`
  and `gd` extensions enabled (`gd` is optional but recommended — see below).
- MySQL or MariaDB.
- `ffmpeg` on the server is optional but recommended — if present, uploaded
  videos get a real thumbnail pulled from the video itself, and you can
  regenerate it at any timestamp later from the admin panel. If absent, a
  generic placeholder thumbnail is used instead and everything still works
  (you can still set a thumbnail manually — see "Editing thumbnails" below).

  **On Rocky Linux / RHEL / CentOS**, `ffmpeg` isn't in the base repos and
  needs RPM Fusion:
  ```
  sudo dnf install -y https://dl.fedoraproject.org/pub/epel/epel-release-latest-$(rpm -E %rhel).noarch.rpm
  sudo dnf install -y --nogpgcheck https://download1.rpmfusion.org/free/el/rpmfusion-free-release-$(rpm -E %rhel).noarch.rpm
  sudo dnf install -y ffmpeg
  ```
  On Debian/Ubuntu it's simpler: `sudo apt install ffmpeg`.

## Setup (fresh install)

1. **Upload the files**, including the `uploads/` folder and its `.htaccess`
   — that file is what stops anything uploaded there from ever being run as
   a script, so don't skip it. Make sure `uploads/videos`, `uploads/images`,
   and `uploads/thumbnails` are writable by the web server user (e.g.
   `chown -R www-data:www-data uploads` on most Debian/Ubuntu servers).

2. **Create the database**:
   ```
   mysql -u root -p -e "CREATE DATABASE ezra_tube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p ezra_tube < schema.sql
   ```
   Then create a database user if you don't already have one:
   ```sql
   CREATE USER 'kidtube_user'@'localhost' IDENTIFIED BY 'pick-a-real-password';
   GRANT ALL PRIVILEGES ON ezra_tube.* TO 'kidtube_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. **Edit `config.php`** — fill in `DB_USER`/`DB_PASS`, optionally change
   `DEFAULT_ADMIN_USER`/`DEFAULT_ADMIN_PASS`, and decide on `ENABLE_YOUTUBE`
   (see above).

4. **Visit the site once** (creates your admin account), then log into
   `/admin/login.php`.

5. **Add media** from the dashboard's "+ Add media" button — upload a video,
   upload a photo, or (if enabled) paste a YouTube link.

## Upgrading from the YouTube-only version (iteration 1)

If you already have an earlier install with just the `videos` table:

1. **Back up your database first**: `mysqldump -u root -p kidtube > backup.sql`
2. Replace all the application files with the new ones **except**
   `config.php` (keep your existing DB credentials) — or just copy your
   `DB_*` values into the new `config.php`.
3. Add the new `uploads/` folder (with its `.htaccess`) to your deployment.
4. Run the migration (using your actual database name):
   ```
   mysql -u root -p ezra_tube < migrate_v2.sql
   ```
   This renames `videos` to `media`, adds the new columns, and leaves all
   your existing YouTube entries intact and working. It's safe to run more
   than once.
5. Add `ENABLE_YOUTUBE` and the `UPLOADS_*`/`UPLOAD_MAX_*` constants to your
   `config.php` if you're reusing your old one — copy them from the new
   `config.php` template.
6. Add the `.user.ini` file too if you want larger upload limits (see below).

## Upgrading from the upload-enabled version (iteration 2) to this version

No database migration needed this time — just new files and a couple of new
config constants.

1. Add the new files: `gate.php`, `includes/kid_gate.php`,
   `admin/edit_thumbnail.php`.
2. Replace `index.php`, `watch.php`, `admin/dashboard.php`,
   `includes/functions.php`, `assets/css/kid.css`, and `assets/css/admin.css`
   with the new versions.
3. Add `KID_GATE_ENABLED` and `KID_GATE_PASSWORD` to your existing
   `config.php` (copy from the template) and set a real password.
4. If you'd previously worked around the thumbnail path bug with a manual
   fix in `dashboard.php` (adding a leading `/`), that's no longer
   needed — the new `media_src()` helper in `functions.php` handles it
   properly everywhere, so a plain `dashboard.php` replacement is fine.

## A few notes

- **Change the default admin password** after your first login (update the
  `password_hash` column for your row, generated with PHP's
  `password_hash()`), and change `DEFAULT_ADMIN_USER`/`DEFAULT_ADMIN_PASS`
  in `config.php` too — they're only used for that first account creation.
- **Upload size limits**: the app enforces `UPLOAD_MAX_VIDEO_MB` /
  `UPLOAD_MAX_IMAGE_MB` from `config.php`, but PHP's own
  `upload_max_filesize`/`post_max_size` apply first. The included
  `.user.ini` raises those automatically on most setups (mod_php and
  PHP-FPM); if your host ignores `.user.ini` files, make the same changes in
  your server's `php.ini` and restart PHP.
- **File type safety**: uploads are checked against their actual file
  content (not just the filename extension), and the `uploads/` folder is
  configured to never execute anything as a script even if a file somehow
  got past that check — that's what `uploads/.htaccess` does. Please deploy
  it as-is.
- **Deleting an uploaded item** removes its file and generated thumbnail
  from disk, not just the database row.
- **Categories** are free-text — type a new one and it becomes a filter pill
  on the kid home page automatically.
- **HTTPS strongly recommended** for the admin login if the site is
  reachable outside your home network.
- **Editing thumbnails**: click "Edit thumbnail" on any item in the
  dashboard to either upload a custom image, or (for uploaded videos, if
  `ffmpeg` is installed) pull a fresh frame from any timestamp in the video.
  Useful if the auto-picked frame (1 second in, by default) landed on
  something blurry or awkward.
- **Swipeable kid navigation**: while watching something, a kid can swipe
  left/right (or use arrow keys) to move to the next/previous approved item
  without going back to the grid. If they opened it from a filtered
  category, swiping stays within that category.
- **Mobile-friendly admin dashboard**: the media list is a responsive card
  grid rather than a wide table, so it's usable on a phone screen without
  side-scrolling.
- **Path handling**: thumbnail/file paths are stored root-relative in the
  database and converted to absolute (`/uploads/...`) at display time via
  the `media_src()` helper in `includes/functions.php`, so they render
  correctly from any page regardless of folder depth (e.g. `/admin/`). This
  assumes the site is deployed at the root of its own (sub)domain rather
  than in a subfolder like `example.com/kidtube/` — if you're deploying into
  a subfolder, you'll need to adjust `media_src()` to prefix that subfolder
  path too.
- **Fullscreen mode**: a round button in the top-right corner of every kid
  page toggles true browser fullscreen (hides the address bar and tabs, not
  just the video player). One real limitation, not a bug: browsers are
  spec'd to automatically exit fullscreen on every page navigation, so
  tapping a video card or swiping to the next item drops back out of it.
  The app makes a best-effort attempt to silently re-enter fullscreen on the
  next page automatically, but browsers may block that (fullscreen normally
  requires a fresh tap/click) - if so, it just quietly does nothing and the
  button is right there to tap again. There's no reliable way around this
  restriction without turning the whole site into a single-page JS app,
  which is a much bigger change than this project takes on.
  On iOS Safari specifically, generic fullscreen support is inconsistent
  across versions — if the button doesn't appear at all there, that's this
  script detecting it isn't supported rather than a bug. "Add to Home
  Screen" is Apple's own recommended way to get a truly chrome-free
  experience on iPad/iPhone, since a home-screen-launched site opens without
  Safari's UI at all.

