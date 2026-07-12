# Releasing Filecheck to WordPress.org

The plugin is hosted at:

* SVN: `https://plugins.svn.wordpress.org/filecheck`
* Public listing: `https://wordpress.org/plugins/filecheck`

Deployment is fully automated via GitHub Actions — you never need to run
an SVN client by hand.

## One-time setup

1. **Set your SVN password** at
   [profiles.wordpress.org → Account & Security → SVN password](https://profiles.wordpress.org/printapp/profile/edit/group/3/?screen=svn-password).
   This is a dedicated password, not your wp.org login password.
2. **Add GitHub repository secrets** (Settings → Secrets and variables → Actions):
   * `SVN_USERNAME` = `printapp` (case sensitive — not the email address)
   * `SVN_PASSWORD` = the SVN password from step 1
3. Wait for commit access to activate (up to 1 hour after the approval email).

## Shipping a new version

1. Bump the version in **three places** (the workflow fails if they disagree):
   * `filecheck.php` → `Version:` header
   * `filecheck.php` → `FILECHECK_VERSION` constant
   * `readme.txt` → `Stable tag:`
2. Add a `== Changelog ==` entry (and `== Upgrade Notice ==` if relevant) in `readme.txt`.
3. Commit to `main`, then tag and push:

   ```bash
   git tag v1.0.2
   git push origin main v1.0.2
   ```

4. The **Release plugin** workflow verifies versions, deploys `trunk` +
   `tags/1.0.2` + `assets` to wp.org SVN, and attaches the zip to a GitHub
   release. Note: wp.org search results can take up to 72 hours to reflect
   changes for a new plugin.

What gets deployed is everything in the repo **except** the paths listed in
`.distignore` (workflows, docs, package.py, the `.wordpress-org` folder —
the latter is deployed separately to SVN `assets/`).

## Updating only the listing (no code release)

Editing `readme.txt` or anything in `.wordpress-org/` on `main`
automatically runs the **Update wp.org assets & readme** workflow, which
pushes those changes to SVN without releasing a new version. You can also
trigger it manually from the Actions tab.

## Store listing assets (`.wordpress-org/`)

| File | Purpose |
|------|---------|
| `banner-1544x500.png` | Retina header banner on the plugin page |
| `banner-772x250.png`  | Standard header banner |
| `icon-256x256.png`    | Retina directory/search icon |
| `icon-128x128.png`    | Standard icon |
| `screenshot-1.png` … `screenshot-4.png` | Shown in the Screenshots tab, in order; captions come from the `== Screenshots ==` section of `readme.txt` |

Screenshot rule: the numbering in `readme.txt`'s `== Screenshots ==` section
must match the `screenshot-N.png` filenames.

## First deploy sanity check

After the first successful workflow run, verify:

* `https://plugins.svn.wordpress.org/filecheck/trunk/` contains the plugin files
* `https://plugins.svn.wordpress.org/filecheck/tags/1.0.1/` exists
* `https://plugins.svn.wordpress.org/filecheck/assets/` contains banners/icons/screenshots
* `https://wordpress.org/plugins/filecheck` renders the listing (may take a while to refresh)
