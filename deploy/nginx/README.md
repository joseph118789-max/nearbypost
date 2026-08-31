# nginx

`nearbypost.conf` is a copy of the live vhost at
`/etc/nginx/sites-available/nearbypost.conf`, kept here so the web server
configuration is reviewable and recoverable like the rest of the code.

It is a copy, not the source of truth. Edit the file in `/etc/nginx`, test with
`nginx -t`, reload, then copy it back here in the same commit.

## Reloading on this server

`systemctl reload nginx` and `nginx -s reload` both fail: systemd has lost track
of the running process and `/run/nginx.pid` is empty. Reload by signalling the
master process directly:

```
kill -HUP $(pgrep -f '^nginx: master process nginx$')
```

Pick the master whose parent is PID 1 and whose workers run as `www-data`. A
second nginx master on this machine belongs to an unrelated Docker container.

## Three settings that have caused outages

- **`index` must list `index.php`, and must not list `index.html`.** A stale
  `public/index.html` holding a raw, un-rendered Blade template was served in
  place of the application and took the public site down for weeks — returning
  HTTP 200 the whole time, so no monitor noticed.

- **`try_files $uri $uri/ /index.php?$query_string`** is what routes requests
  into Laravel. With `=404` in its place, every application route returned 404
  while static files kept working.

- **`try_files $uri` serves any real file in `public/` before Laravel runs.** A
  static file silently wins over a route of the same name; that is how a
  leftover `public/robots.txt` beat the generated one.

## Things deliberately not in this file

- `/api/` is **not** proxied to `php artisan serve` on port 8000 any more. That
  was a single-threaded development server that nothing restarts if it dies.
  Laravel registers `routes/api.php`, so the normal request path serves it.

- No `Content-Security-Policy` yet. The pages carry inline JSON-LD for search
  and answer engines, so a `script-src` policy needs hashes or a nonce or it
  will silently drop the structured data.

- `Strict-Transport-Security` is set to one day on purpose. Browsers cache HSTS,
  so a long `max-age` is awkward to walk back. Raise it to a year once it has
  been running without complaint.
