# HEISVERSE

A personal movie center with an admin dashboard.

- **Public site**: worldwide discovery (TMDB), free public-domain films (Internet Archive), a **Catalog** of movies your admin published, and offline playback of the videos on your own PC.
- **Admin dashboard**: add movies, search TMDB and the Internet Archive, record a rights review, attach legal playback sources, and publish. Published movies appear in the public Catalog.
- **MySQL** stores the catalog, sources and admin accounts. The watchlist and settings of each visitor stay in their own browser.

## 1. Folder layout

Everything lives in one folder named `heisverse`:

```
heisverse/
├── index.php            public site
├── api.php              public JSON API (TMDB proxy + published catalog)
├── config.php           TMDB key, region, database login
├── database.sql         creates the database and tables
├── README.md
├── .htaccess            blocks .sql and .md files from the web
├── assets/
│   ├── icons/           favicon
│   ├── images/          your own site images
│   └── uploads/         posters and videos uploaded by the admin (PHP never runs here)
├── css/                 style.css (public), admin.css (admin)
├── js/                  script.js (public), admin.js (admin)
├── admin/               dashboard: index, login, logout, movies, movie-add, movie-edit,
│   │                    search-online, sources, settings
│   └── includes/        auth, csrf, header, sidebar, footer, movie-form
└── includes/            database.php, functions.php, movie-sources.php
```

Free films from the Internet Archive are searched directly by the public page (`js/script.js`) and, for the admin, by `includes/movie-sources.php`, so no separate free-films files are needed.

## 2. Install XAMPP

1. Download XAMPP for Windows from https://www.apachefriends.org (choose a version with **PHP 8.0 or newer**) and install it in `C:\xampp`.
2. Open the **XAMPP Control Panel**.

## 3. Copy the project

Copy the `heisverse` folder to `C:\xampp\htdocs\` so that this file exists:

`C:\xampp\htdocs\heisverse\index.php`

## 4. Start Apache and MySQL

In the XAMPP Control Panel click **Start** next to **Apache** and next to **MySQL**. Both should turn green.

## 5. Import the database

1. Open http://localhost/phpmyadmin/
2. Click **Import** (top menu). Do not select a database first.
3. Click **Choose File**, pick `C:\xampp\htdocs\heisverse\database.sql`, then click **Import** at the bottom.
4. You should now see a database named `heisverse` with the tables `admins`, `login_attempts`, `movies` and `movie_sources`.

Importing again is safe: it only creates what is missing and never deletes data.

## 6. Check config.php

Open `C:\xampp\htdocs\heisverse\config.php` in Notepad.

- Database: the defaults match a fresh XAMPP (`DB_USER` is `root`, `DB_PASS` is empty). Change them only if you set a MySQL password.
- TMDB key (optional but recommended): create a free account at https://www.themoviedb.org, open **Settings > API**, request a Developer key and paste the **API Key (v3)**: `const TMDB_API_KEY = 'your-key';`
- Set `DEFAULT_REGION` to your country code (for example `RW`).

The TMDB key stays on the server. It never reaches the browser. TMDB supplies movie information and trailers. It does **not** supply full movies.

## 7. Open the site and the dashboard

- Public site: http://localhost/heisverse/
- Admin dashboard: http://localhost/heisverse/admin/

The first time you open the dashboard it asks you to **create the first admin account** (username and a password of at least 10 characters). There is no default password. After that, the same page is a normal sign-in. More admins can be added in **Settings**.

## 8. Publish your first movie

1. Dashboard > **Search online**.
2. **TMDB tab**: search a title and **Import as draft** (metadata, poster, trailer). **Internet Archive tab**: search a free film and **Import**. You can attach the film to an existing draft, which is the usual way to pair TMDB details with an Internet Archive copy.
3. Open the movie (**Movies** > Edit) and complete the **Rights review**: choose the rights status, write the basis (for example "Public Domain Mark 1.0 shown on the Internet Archive item page"), and optionally paste a proof link.
4. **Manage sources**: confirm there is at least one active, legal playback source.
5. Click **Publish to public site**. The page lists anything still missing.
6. Open http://localhost/heisverse/#catalog. The movie is there. **Unpublish** removes it again.

## Legal playback: how the rules are enforced

A movie appears publicly only when **all** of these are true, checked on the server, both when you click Publish and every time the public API answers:

- its status is Published,
- its rights status is public domain, open license, licensed, or owned (never "Not reviewed" or "Not cleared"),
- rights notes explain the basis (publishing requires them),
- it has at least one active playback source.

If you later turn off the last source or change the rights status, the movie is moved back to drafts automatically.

Allowed sources: an Internet Archive item, a YouTube video on an official or authorized channel, an official free page (opens as a link), or a video file you are allowed to host. TMDB trailers are trailers only. Nothing here bypasses DRM, subscriptions, accounts or regional limits, and the dashboard cannot add other sites as sources. The license text the Internet Archive shows is what the **uploader declared**. You are the reviewer: open the item page and confirm it.

## Security notes

- Admin pages need a signed-in session on every request, including every action. Sessions end after 30 minutes without activity. All forms carry a CSRF token. Passwords are stored with `password_hash`.
- Failed sign-ins are limited to 8 per IP per 15 minutes.
- Uploads are renamed with random names, checked by real file type, and saved in `assets/uploads/`, where Apache is told never to run PHP. Keep the XAMPP default of `AllowOverride All` for `htdocs` so the `.htaccess` files work.
- This setup is made for your own PC. Before putting it on the internet: use HTTPS, set a MySQL password and a database user other than `root`, and keep `config.php` private.

## My PC videos (unchanged)

Open **My PC**, choose a folder or video files (or drag them in). Files are played from your disk, never uploaded, and work without Wi-Fi. Resume positions and "Watched" marks are saved in the browser by filename and size. Browsers cannot silently reopen files after the tab closes, so choose the folder again next time. MP4 (H.264/AAC) and WebM play almost everywhere. MKV and AVI often fail on codecs: convert with HandBrake or open the file in VLC.

## What needs internet

| Feature | Offline | Needs internet |
|---|---|---|
| My PC library and player | Yes | |
| Watchlist, history, settings | Yes | |
| Public Catalog (your own database) | Yes, text and uploaded posters | TMDB posters and Internet Archive / YouTube players |
| Discover, search, trailers, where to watch | | Yes |
| Free films (Internet Archive) | | Yes |
| Admin: Search online | | Yes |

## Troubleshooting

- **Catalog says "Cannot connect to the database"**: start MySQL in XAMPP, then check `DB_*` in `config.php`.
- **"The catalog tables are missing"**: import `database.sql` (step 5).
- **"Cannot reach api.php"**: Apache is not running, or you opened the file directly. Use http://localhost/heisverse/.
- **SSL certificate error** (TMDB or Internet Archive search from the dashboard): set `TMDB_VERIFY_SSL` to `false` in `config.php` as a temporary workaround, or set `curl.cainfo` in `C:\xampp\php\php.ini` to a CA bundle file. Restart Apache.
- **curl error**: in `php.ini`, make sure `extension=curl` is not commented out, then restart Apache.
- **Video upload fails**: raise `upload_max_filesize` and `post_max_size` in `C:\xampp\php\php.ini` (for example `2G`), then restart Apache. Or add the video as a link instead.
- **Forgot the admin password**: in phpMyAdmin open the `heisverse` database, empty the `admins` table, and open `/admin/` again to create a new first admin. Movies are kept.
- **No "where to watch" data**: pick another country in Settings on the public site.
- **Dashboard looks unstyled**: it loads `../css/admin.css`. Make sure you opened http://localhost/heisverse/admin/ and the `css` folder is inside `heisverse`.
# heis
