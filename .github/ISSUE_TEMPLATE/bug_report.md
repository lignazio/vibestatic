---
name: Bug report
about: Something in VibeStatic does not work
title: ''
labels: ''
assignees: ''

---

## Before filing

Please work down this list first — it separates a VibeStatic bug from a theme,
plugin or environment problem, and it is usually quick.

- [ ] **Switch to a default WordPress theme** and export again. If the problem
      goes away, it is theme-related.
- [ ] **Deactivate every plugin except VibeStatic** and export again. If the
      problem goes away, turn them back on one at a time until you find which.
- [ ] **Check the Diagnostics page** (VibeStatic → Diagnostics). An unlimited
      `max_execution_time`, a writable uploads directory and a permalink
      structure ending in a slash are the three things that most often are not
      what the plugin needs.
- [ ] **Look at the log** (VibeStatic → Logs). It usually names the URL or the
      file the run stopped on.

If it persists after all four, it is likely a VibeStatic bug. Please file it.

## What happened

A clear description of the problem.

## How to reproduce

1. …
2. …
3. …

## What you expected instead

## Environment

- VibeStatic version:
- WordPress version:
- PHP version:
- Single site or multisite:
- Which deployment add-on:
- Hosting / local environment (WP Engine, a VPS, Local, Valet, Docker, …):

## Logs

Please check for anything sensitive before attaching — the logs contain the URLs
of your site, and a deploy log can contain the path or host you publish to.

- VibeStatic logs: WordPress dashboard → VibeStatic → Logs
- Server logs: PHP and web server error logs

## Anything else

Screenshots, the contents of the Advanced page, the size of the site in pages —
whatever you think matters.

---

**Security issues do not go here.** See
[SECURITY.md](https://github.com/lignazio/vibestatic/blob/vibestatic/SECURITY.md).
